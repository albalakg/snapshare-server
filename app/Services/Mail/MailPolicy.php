<?php

namespace App\Services\Mail;

use App\Models\MailDelivery;
use Carbon\Carbon;

/**
 * Sending rules (PLAN.md §3, Gal t142u):
 *  - transactional / urgent / admin : always sent immediately.
 *  - reminder  (D2/D3 scheduled event reminders): held only by night quiet hours; never dropped; no Shabbat hold
 *              (a Friday "tomorrow" reminder for a Saturday-night event must not arrive after the event).
 *  - activity  (results of the host's own actions: template reviewed, send summary, hub published, WhatsApp ready):
 *              quiet hours + Shabbat; never dropped.
 *  - digest    (digests and nudges): quiet hours + Shabbat + max `daily_cap` per recipient per rolling 24h
 *              (counting reminder/activity/digest emails); over the cap → skipped.
 * Behind EMAILS_POLICY_ENABLED (default on). Times in Asia/Jerusalem.
 */
class MailPolicy
{
    public const TRANSACTIONAL = 'transactional';
    public const URGENT = 'urgent';
    public const ADMIN = 'admin';
    public const REMINDER = 'reminder';
    public const ACTIVITY = 'activity';
    public const DIGEST = 'digest';

    public const CAPPED_COUNT = [self::REMINDER, self::ACTIVITY, self::DIGEST];

    public function enabled(): bool
    {
        return (bool) config('emails.policy.enabled', true);
    }

    /**
     * @return array{action: string, send_at: ?Carbon, reason: string}
     */
    public function decide(string $category, string $recipient, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy();
        if (!$this->enabled() || !in_array($category, self::CAPPED_COUNT, true)) {
            return ['action' => 'send', 'send_at' => null, 'reason' => 'exempt'];
        }

        if ($category === self::DIGEST && $this->overCap($recipient, $now)) {
            return ['action' => 'skip', 'send_at' => null, 'reason' => 'daily_cap'];
        }

        $shabbat = $category !== self::REMINDER && (bool) config('emails.policy.shabbat', true);
        $next = $this->nextAllowed($now, $shabbat);
        if ($next->greaterThan($now)) {
            return ['action' => 'defer', 'send_at' => $next->utc(), 'reason' => 'quiet_hours'];
        }

        return ['action' => 'send', 'send_at' => null, 'reason' => 'allowed'];
    }

    public function overCap(string $recipient, Carbon $now): bool
    {
        if (!MailLedger::available()) {
            return false;
        }
        $cap = max(1, (int) config('emails.policy.daily_cap', 1));

        return MailDelivery::where('recipient', $recipient)
            ->whereIn('category', self::CAPPED_COUNT)
            ->whereIn('status', ['sent', 'deferred'])
            ->where('created_at', '>=', $now->copy()->subDay())
            ->count() >= $cap;
    }

    /** First moment >= $now outside quiet hours (and Shabbat when $shabbat). */
    public function nextAllowed(Carbon $now, bool $shabbat = true): Carbon
    {
        $tz = (string) config('emails.policy.timezone', 'Asia/Jerusalem');
        $t = $now->copy()->setTimezone($tz);
        [$qsH, $qsM] = array_map('intval', explode(':', (string) config('emails.policy.quiet_start', '21:00')));
        [$qeH, $qeM] = array_map('intval', explode(':', (string) config('emails.policy.quiet_end', '08:00')));
        [$ssH, $ssM] = array_map('intval', explode(':', (string) config('emails.policy.shabbat_start', '16:00')));
        [$seH, $seM] = array_map('intval', explode(':', (string) config('emails.policy.shabbat_end', '20:00')));

        for ($i = 0; $i < 6; $i++) {
            $moved = false;
            $start = $t->copy()->setTime($qsH, $qsM);
            $end = $t->copy()->setTime($qeH, $qeM);
            if ($t->greaterThanOrEqualTo($start)) {
                $t = $end->copy()->addDay();
                $moved = true;
            } elseif ($t->lessThan($end)) {
                $t = $end;
                $moved = true;
            }
            if ($shabbat) {
                $isFri = $t->isFriday() && $t->greaterThanOrEqualTo($t->copy()->setTime($ssH, $ssM));
                $isSat = $t->isSaturday() && $t->lessThan($t->copy()->setTime($seH, $seM));
                if ($isFri || $isSat) {
                    $t = ($isFri ? $t->copy()->addDay() : $t->copy())->setTime($seH, $seM);
                    $moved = true;
                }
            }
            if (!$moved) {
                break;
            }
        }

        return $t;
    }
}
