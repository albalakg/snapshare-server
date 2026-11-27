<?php

namespace App\Services\Mail;

use App\Models\AddonPurchase;
use App\Models\AiSeatingRun;
use App\Models\Event;
use App\Models\EventAsset;
use App\Models\EventGuest;
use App\Models\EventHub;
use App\Models\EventWhatsAppSetting;
use App\Models\User;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use App\Services\Enums\EventGuestStatusEnum;
use App\Services\Enums\MailEnum;
use App\Services\Helpers\LogService;
use App\Services\Helpers\MailService;
use App\Services\Messaging\QuotaService;
use App\Services\Seating\SeatingAiService;
use App\Services\Seating\SeatingEntitlement;
use Throwable;

/**
 * Event-driven triggers for the v2 emails (t141u). Called from model observers (EmailServiceProvider),
 * a few services, and the emails:* commands. Every public method:
 *  - is a no-op unless EMAILS_V2_ENABLED (new emails only),
 *  - never throws (an email must never break the business action),
 *  - is idempotent through a dedupe key in mail_deliveries.
 * Guests never receive emails from here (Gal t142u) — only the event owner and the hardcoded admin.
 */
class EmailTriggers
{
    public static function mailer(): MailService
    {
        return new MailService();
    }

    private static function guard(string $what, callable $fn): void
    {
        if (!MailV2::enabled()) {
            return;
        }
        try {
            $fn();
        } catch (Throwable $e) {
            try {
                LogService::init()->error('Email trigger failed: ' . $what, ['error' => $e->getMessage()]);
            } catch (Throwable) {
                // logging must never break the caller either
            }
        }
    }

    /** @return array{0: ?Event, 1: ?User} */
    public static function owner(int $event_id): array
    {
        $event = Event::with('user')->find($event_id);
        return [$event, $event?->user];
    }

    private static function send(?User $user, string $class, array $data, string $dedupe, ?int $event_id = null): void
    {
        if (!$user || !$user->email) {
            return;
        }
        self::mailer()->send($user->email, $class, $data + ['first_name' => $user->first_name ?? ''], ['dedupe' => $dedupe, 'event_id' => $event_id]);
    }

    private static function admin(string $class, array $data, ?string $dedupe = null): void
    {
        self::mailer()->send(MailService::SYSTEM_EMAILS, $class, $data, array_filter(['dedupe' => $dedupe]));
    }

    // ── A · account ────────────────────────────────────────────────

    /** A2 — first sign-up through Google (no verification needed). */
    public static function welcomeGoogle(User $user): void
    {
        self::guard('A2', fn () => self::send($user, MailEnum::WELCOME_GOOGLE, ['order_url' => MailV2::client('/order')], 'user:' . $user->id . ':welcome-google'));
    }

    /** A4 — password changed (reset or profile). The provider only calls this when a previous password existed. */
    public static function passwordChanged(User $user): void
    {
        self::guard('A4', function () use ($user) {
            $at = now();
            self::send($user, MailEnum::PASSWORD_CHANGED, ['changed_at' => MailV2::date($at, 'd/m/Y H:i')], 'user:' . $user->id . ':password:' . $at->format('YmdHi'));
        });
    }

    // ── C · event setup ───────────────────────────────────────────

    /** C2 — hub published for the first time. C3 — first saved Save the Date design (when messaging is on). */
    public static function hubSaved(EventHub $hub, bool $publishedNow, bool $stdFirst): void
    {
        self::guard('C2/C3', function () use ($hub, $publishedNow, $stdFirst) {
            [$event, $user] = self::owner((int) $hub->event_id);
            if (!$event) {
                return;
            }
            if ($publishedNow) {
                self::send($user, MailEnum::HUB_PUBLISHED, ['event_name' => $event->name, 'hub_url' => MailV2::client('/h/' . $hub->slug)], 'event:' . $event->id . ':hub-published', $event->id);
            }
            if ($stdFirst && config('whatsapp_messaging.enabled')) {
                self::send($user, MailEnum::SAVE_THE_DATE_READY, ['event_name' => $event->name, 'compose_url' => MailV2::client('/event/save-the-date')], 'event:' . $event->id . ':std-ready', $event->id);
            }
        });
    }

    public static function hasStd($config): bool
    {
        $c = self::decode($config);
        return is_array($c['save_the_date'] ?? null) && !empty($c['save_the_date']);
    }

    public static function decode($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) ? $d : [];
    }

    // ── E · during the event ──────────────────────────────────────

    /** E2 — upload milestones (first upload, 50) — max 2 per event. */
    public static function assetCreated(EventAsset $asset): void
    {
        self::guard('E2', function () use ($asset) {
            $event_id = (int) $asset->event_id;
            $count = EventAsset::where('event_id', $event_id)->count();
            $milestones = (array) config('emails.triggers.first_uploads_milestones', [1, 50]);
            if (!in_array($count, $milestones, true)) {
                return;
            }
            [$event, $user] = self::owner($event_id);
            if (!$event) {
                return;
            }
            self::send($user, MailEnum::FIRST_UPLOADS, ['event_name' => $event->name, 'count' => $count, 'gallery_url' => MailV2::client('/event/gallery')], 'event:' . $event_id . ':uploads:' . $count, $event_id);
        });
    }

    // ── G · add-ons ───────────────────────────────────────────────

    /** G1 (owner) + J5 (admin) — once per purchase, when it is credited. */
    public static function addonCredited(AddonPurchase $p): void
    {
        self::guard('G1/J5', function () use ($p) {
            [$event, $user] = self::owner((int) $p->event_id);
            $base = ['first_name' => $user->first_name ?? '', 'event_name' => $event->name ?? ''];
            $data = MailCatalog::addonSample((string) $p->product, $base);
            $data['quantity'] = (int) $p->quantity;
            $data['amount'] = (float) $p->amount;
            $data['purchase_uid'] = (string) $p->uid;
            if ($p->product === 'seating_ai_topup') {
                $data['ai_runs_total'] = self::aiQuota((int) $p->event_id)['included'];
            }
            self::send($user, MailEnum::ADDON_RECEIPT, $data, 'addon:' . $p->uid . ':receipt', (int) $p->event_id);
            self::admin(MailEnum::ADMIN_ADDON_PURCHASE, [
                'title' => $data['title'], 'product' => $p->product, 'amount' => (float) $p->amount, 'quantity' => (int) $p->quantity,
                'user_email' => $user->email ?? '', 'event_id' => $p->event_id, 'purchase_uid' => $p->uid, 'provider' => $p->provider,
            ], 'addon:' . $p->uid . ':admin');
        });
    }

    /** G2 — a payment page that was opened ended failed/cancelled (not superseded by a newer checkout). */
    public static function addonFailed(AddonPurchase $p): void
    {
        self::guard('G2', function () use ($p) {
            if (($p->failure_reason ?? '') === 'superseded' || $p->credited_at) {
                return;
            }
            [$event, $user] = self::owner((int) $p->event_id);
            $retry = str_starts_with((string) $p->product, 'seating') ? '/event/seating' : '/event/messages/packages';
            self::send($user, MailEnum::ADDON_FAILED, [
                'event_name' => $event->name ?? '', 'title' => config('addons.products.' . $p->product . '.title', $p->product),
                'purchase_uid' => $p->uid, 'reason' => $p->status === 'cancelled' ? 'התשלום בוטל' : null, 'retry_url' => MailV2::client($retry),
            ], 'addon:' . $p->uid . ':failed', (int) $p->event_id);
        });
    }

    /** J6 — a payment callback failed verification. */
    public static function paymentCallbackRejected(string $kind, $id, string $reason): void
    {
        self::guard('J6', fn () => self::admin(MailEnum::ADMIN_PAYMENT_ALERT, ['kind' => $kind, 'id' => (string) $id, 'reason' => $reason, 'ip' => request()?->ip()]));
    }

    /** G3 — message quota crossed 80% / 100% (once per threshold per event). */
    public static function quotaCheck(int $event_id): void
    {
        self::guard('G3', function () use ($event_id) {
            [$event, $user] = self::owner($event_id);
            if (!$event) {
                return;
            }
            $settings = EventWhatsAppSetting::where('event_id', $event_id)->first();
            $withPhone = EventGuest::where('event_id', $event_id)->whereNotNull('phone_hash')->count();
            $q = app(QuotaService::class)->summary($event, $settings, $withPhone);
            $capacity = (int) $q['capacity'];
            if ($capacity <= 0) {
                return;
            }
            $pct = (int) floor($q['used'] * 100 / $capacity);
            $hit = null;
            foreach ((array) config('emails.triggers.quota_thresholds', [80, 100]) as $t) {
                if ($pct >= (int) $t) {
                    $hit = (int) $t;
                }
            }
            if ($hit === null) {
                return;
            }
            self::send($user, MailEnum::QUOTA_ALERT, [
                'event_name' => $event->name, 'threshold' => $hit, 'used' => (int) $q['used'], 'capacity' => $capacity,
                'packages_url' => MailV2::client('/event/messages/packages'),
            ], 'event:' . $event_id . ':quota:' . $hit . ':' . $capacity, $event_id);
        });
    }

    /** G4 — AI seating runs: 1 left / none left (once per threshold per quota size). */
    public static function aiRunCreated(AiSeatingRun $run): void
    {
        self::guard('G4', function () use ($run) {
            if ($run->engine !== 'ai') {
                return;
            }
            $q = self::aiQuota((int) $run->event_id);
            $left = (int) $q['remaining'];
            if ($left > 1) {
                return;
            }
            [$event, $user] = self::owner((int) $run->event_id);
            self::send($user, MailEnum::AI_RUNS_LOW, ['event_name' => $event->name ?? '', 'runs_left' => $left, 'seating_url' => MailV2::client('/event/seating')],
                'event:' . $run->event_id . ':ai-runs:' . $left . ':' . $q['included'], (int) $run->event_id);
        });
    }

    /** Same numbers as SeatingAiService::quota() without building the AI provider. */
    public static function aiQuota(int $event_id): array
    {
        $included = (int) config('seating.ai.runs_per_event', 5) + SeatingEntitlement::extraAiRuns($event_id);
        $used = AiSeatingRun::where('event_id', $event_id)->where('engine', 'ai')->whereIn('status', SeatingAiService::COUNTED_STATUSES)->count();

        return ['included' => $included, 'used' => $used, 'remaining' => max(0, $included - $used)];
    }

    // ── I · WhatsApp messaging ────────────────────────────────────

    /** I1 — messaging module opened for the event (settings row created). */
    public static function whatsappReady(EventWhatsAppSetting $s): void
    {
        self::guard('I1', function () use ($s) {
            if (!config('whatsapp_messaging.enabled')) {
                return;
            }
            [$event, $user] = self::owner((int) $s->event_id);
            if (!$event) {
                return;
            }
            $perGuest = app(QuotaService::class)->perGuest($event, $s);
            self::send($user, MailEnum::WHATSAPP_READY, ['event_name' => $event->name, 'per_guest' => $perGuest, 'messaging_url' => MailV2::client('/event/messages')], 'event:' . $event->id . ':whatsapp-ready', $event->id);
        });
    }

    /** I2 — Meta approved / rejected an event template. */
    public static function templateReviewed(WhatsAppTemplate $t): void
    {
        self::guard('I2', function () use ($t) {
            $status = strtolower((string) $t->meta_status);
            if (!$t->event_id || !in_array($status, ['approved', 'rejected'], true)) {
                return;
            }
            [$event, $user] = self::owner((int) $t->event_id);
            self::send($user, MailEnum::TEMPLATE_REVIEWED, [
                'event_name' => $event->name ?? '', 'template_title' => $t->title ?: $t->key, 'status' => $status,
                'reason' => $t->meta_reason, 'templates_url' => MailV2::client('/event/messages/templates'),
            ], 'template:' . $t->id . ':' . $status . ':' . optional($t->reviewed_at)->timestamp, (int) $t->event_id);
        });
    }

    /** I4 summary, or I7 problem (+ J7 admin) when ≥ send_problem_ratio failed; then G3 quota check. */
    public static function sendFinished(WhatsAppSend $send): void
    {
        self::guard('I4/I7', function () use ($send) {
            $rows = WhatsAppSendRecipient::where('send_id', $send->id)->selectRaw('status, channel, count(*) as n')->groupBy('status', 'channel')->get();
            $total = (int) $rows->sum('n');
            if ($total === 0 || $send->source === 'test') {
                return;
            }
            $sent = (int) $rows->whereIn('status', WhatsAppSendRecipient::DONE)->sum('n');
            $failed = (int) $rows->where('status', 'failed')->sum('n');
            $skipped = (int) $rows->where('status', 'skipped')->sum('n');
            $sms = (int) $rows->where('channel', 'sms')->whereIn('status', WhatsAppSendRecipient::DONE)->sum('n');
            [$event, $user] = self::owner((int) $send->event_id);
            $base = ['event_name' => $event->name ?? '', 'send_title' => $send->title ?: 'הודעה לאורחים', 'recipients' => $total];

            if ($failed > 0 && $failed / $total >= (float) config('emails.triggers.send_problem_ratio', 0.3)) {
                $reason = WhatsAppSendRecipient::where('send_id', $send->id)->where('status', 'failed')->whereNotNull('error')
                    ->selectRaw('error, count(*) as n')->groupBy('error')->orderByDesc('n')->value('error');
                self::send($user, MailEnum::SEND_PROBLEM, $base + ['failed' => $failed, 'reason' => $reason ? mb_substr($reason, 0, 120) : null, 'queue_url' => MailV2::client('/event/messages/queue')],
                    'send:' . $send->id . ':problem', (int) $send->event_id);
                self::admin(MailEnum::ADMIN_PROVIDER_ALERT, ['provider' => \App\Services\Messaging\Providers\ProviderFactory::whatsappName(), 'error' => (string) $reason, 'count' => $failed, 'send_id' => $send->id, 'event_id' => $send->event_id],
                    'send:' . $send->id . ':provider-alert');
            } else {
                self::send($user, MailEnum::SEND_SUMMARY, $base + ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'sms' => $sms, 'analytics_url' => MailV2::client('/event/messages/analytics')],
                    'send:' . $send->id . ':summary', (int) $send->event_id);
            }
        });
        self::quotaCheck((int) $send->event_id);
    }

    /** I3 — 1 hour before a scheduled send to ≥ N recipients (command emails:heads-up). */
    public static function headsUp(WhatsAppSend $send, int $recipients): void
    {
        self::guard('I3', function () use ($send, $recipients) {
            [$event, $user] = self::owner((int) $send->event_id);
            self::send($user, MailEnum::SEND_HEADS_UP, [
                'event_name' => $event->name ?? '', 'send_title' => $send->title ?: 'הודעה לאורחים', 'recipients' => $recipients,
                'send_time' => MailV2::date($send->scheduled_at, 'd/m/Y H:i'), 'queue_url' => MailV2::client('/event/messages/queue'),
            ], 'send:' . $send->id . ':heads-up:' . optional($send->scheduled_at)->timestamp, (int) $send->event_id);
        });
    }

    // ── daily (emails:daily, 09:00 Israel) ────────────────────────

    /** C1 — 2 and 7 days after the event was created, when setup is incomplete. */
    public static function setupNudge(Event $event): void
    {
        self::guard('C1', function () use ($event) {
            $event->loadMissing('user', 'hub');
            $age = $event->created_at ? (int) $event->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : -1;
            if (!in_array($age, (array) config('emails.triggers.setup_nudge_days', [2, 7]), true)) {
                return;
            }
            $items = [
                [(bool) ($event->name && $event->starts_at), 'שם ותאריך האירוע'],
                [(bool) ($event->hub?->is_published), 'עמוד האורחים'],
                [EventAsset::where('event_id', $event->id)->exists(), 'העלאה ראשונה לאלבום'],
            ];
            $missing = count(array_filter($items, fn ($i) => !$i[0]));
            if ($missing === 0) {
                return;
            }
            self::send($event->user, MailEnum::SETUP_NUDGE, ['event_name' => $event->name ?: 'האירוע שלכם', 'missing_count' => $missing, 'checklist' => $items, 'event_url' => MailV2::client('/event/details')],
                'event:' . $event->id . ':setup-nudge:' . $age, $event->id);
        });
    }

    /** H1 — N days before the event, ≥ M confirmed and no seating yet; only when seating is entitled (no upsell). */
    public static function seatingNudge(Event $event): void
    {
        self::guard('H1', function () use ($event) {
            if (!config('seating.enabled', false) || !$event->starts_at) {
                return;
            }
            $days = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($event->starts_at)->startOfDay(), false);
            if ($days !== (int) config('emails.triggers.seating_nudge_days_before', 10)) {
                return;
            }
            $entitled = !config('seating.paywall.enabled', false) || SeatingEntitlement::enabled($event->id);
            if (!$entitled) {
                return;
            }
            $confirmed = EventGuest::where('event_id', $event->id)->where('status', EventGuestStatusEnum::CONFIRMED)->count();
            if ($confirmed < (int) config('emails.triggers.seating_nudge_min_confirmed', 10)) {
                return;
            }
            $assigned = \App\Models\SeatingAssignment::whereIn('plan_id', \App\Models\SeatingPlan::where('event_id', $event->id)->pluck('id'))->exists();
            if ($assigned) {
                return;
            }
            $event->loadMissing('user');
            self::send($event->user, MailEnum::SEATING_NUDGE, ['event_name' => $event->name, 'confirmed' => $confirmed, 'seating_url' => MailV2::client('/event/seating')], 'event:' . $event->id . ':seating-nudge', $event->id);
        });
    }

    /** H2 — all confirmed guests seated, ≥ 3 days before the event (once). */
    public static function tableLinks(Event $event): void
    {
        self::guard('H2', function () use ($event) {
            if (!config('seating.enabled', false) || !$event->starts_at) {
                return;
            }
            $days = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($event->starts_at)->startOfDay(), false);
            if ($days < (int) config('emails.triggers.table_links_days_before', 3)) {
                return;
            }
            $confirmedIds = EventGuest::where('event_id', $event->id)->where('status', EventGuestStatusEnum::CONFIRMED)->pluck('id');
            if ($confirmedIds->isEmpty()) {
                return;
            }
            $planIds = \App\Models\SeatingPlan::where('event_id', $event->id)->pluck('id');
            $seated = \App\Models\SeatingAssignment::whereIn('plan_id', $planIds)->whereIn('guest_id', $confirmedIds)->distinct()->count('guest_id');
            if ($seated < $confirmedIds->count()) {
                return;
            }
            $tables = \App\Models\SeatingTable::whereIn('plan_id', $planIds)->count();
            $event->loadMissing('user');
            self::send($event->user, MailEnum::TABLE_LINKS_READY, ['event_name' => $event->name, 'assigned' => $seated, 'tables' => $tables, 'compose_url' => MailV2::client('/event/messages/compose')],
                'event:' . $event->id . ':table-links', $event->id);
        });
    }

    /**
     * I5 — RSVP digest when there are new answers in the last 24h (daily in the last week before the event, weekly before that).
     * Returns true when a digest was handed to the mailer (I6 is then folded into it).
     */
    public static function rsvpDigest(Event $event): bool
    {
        $sent = false;
        self::guard('I5', function () use ($event, &$sent) {
            if (!$event->starts_at || \Carbon\Carbon::parse($event->starts_at)->isPast()) {
                return;
            }
            $days = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($event->starts_at)->startOfDay(), false);
            if ($days > 7 && now()->timezone('Asia/Jerusalem')->dayOfWeek !== \Carbon\Carbon::SUNDAY) {
                return;
            }
            $since = $days > 7 ? now()->subDays(7) : now()->subDay();
            $guests = EventGuest::where('event_id', $event->id)->get(['id', 'full_name', 'status', 'party_size', 'rsvp_responded_at']);
            $new = $guests->filter(fn ($g) => $g->rsvp_responded_at && $g->rsvp_responded_at->gte($since));
            if ($new->isEmpty()) {
                return;
            }
            $count = fn ($s) => $guests->where('status', $s)->count();
            $labels = [EventGuestStatusEnum::CONFIRMED => ['מגיעים', 't-ok'], EventGuestStatusEnum::DECLINED => ['לא מגיעים', 't-warn'], EventGuestStatusEnum::MAYBE => ['אולי', 't-gold']];
            $recent = $new->sortByDesc('rsvp_responded_at')->take(5)->map(function ($g) use ($labels) {
                [$chip, $tone] = $labels[(int) $g->status] ?? ['עודכן', 't-gold'];
                $meta = ((int) $g->party_size > 1 ? (int) $g->party_size . ' אורחים · ' : '') . MailV2::date($g->rsvp_responded_at, 'd/m H:i');
                return [(string) $g->full_name, $meta, $chip, $tone];
            })->values()->all();
            $answered = $guests->filter(fn ($g) => in_array((int) $g->status, [EventGuestStatusEnum::CONFIRMED, EventGuestStatusEnum::DECLINED, EventGuestStatusEnum::MAYBE], true))->count();
            $review = \App\Models\WhatsAppMessage::where('event_id', $event->id)->where('direction', 'in')->whereNotNull('suggestion')->whereNull('handled')->count();
            $event->loadMissing('user');
            self::send($event->user, MailEnum::RSVP_DIGEST, [
                'event_name' => $event->name, 'new_count' => $new->count(), 'answered' => $answered, 'total' => $guests->count(),
                'counts' => ['yes' => $count(EventGuestStatusEnum::CONFIRMED), 'no' => $count(EventGuestStatusEnum::DECLINED), 'maybe' => $count(EventGuestStatusEnum::MAYBE), 'pending' => $count(EventGuestStatusEnum::INVITED)],
                'recent' => $recent, 'review_count' => $review, 'guests_url' => MailV2::client('/event/guests'),
            ], 'event:' . $event->id . ':rsvp-digest:' . now()->timezone('Asia/Jerusalem')->format('Ymd'), $event->id);
            $sent = true;
        });
        return $sent;
    }

    /** I6 — ≥ N AI suggestions waiting > 24h (skipped on days I5 went out — it is included there). */
    public static function repliesReview(Event $event): void
    {
        self::guard('I6', function () use ($event) {
            $q = \App\Models\WhatsAppMessage::where('event_id', $event->id)->where('direction', 'in')->whereNotNull('suggestion')->whereNull('handled')->where('created_at', '<=', now()->subDay());
            $count = (clone $q)->count();
            if ($count < (int) config('emails.triggers.replies_review_min', 3)) {
                return;
            }
            $items = (clone $q)->latest()->take(4)->get()->map(function ($m) {
                $guest = $m->guest_id ? EventGuest::find($m->guest_id) : null;
                $s = (array) $m->suggestion;
                $chip = match ((int) ($s['status'] ?? -1)) { 1 => 'מגיעים' . (isset($s['party_size']) ? ' · ' . (int) $s['party_size'] : ''), 2 => 'לא מגיעים', 3 => 'אולי', default => 'עדכון' };
                $tone = match ((int) ($s['status'] ?? -1)) { 1 => 't-ok', 2 => 't-warn', default => 't-gold' };
                return [(string) ($guest?->full_name ?: 'אורח'), '״' . mb_substr((string) $m->body, 0, 60) . '״', $chip, $tone];
            })->all();
            $event->loadMissing('user');
            self::send($event->user, MailEnum::REPLIES_REVIEW, ['event_name' => $event->name, 'count' => $count, 'items' => $items, 'inbox_url' => MailV2::client('/event/messages/inbox')],
                'event:' . $event->id . ':replies-review:' . now()->timezone('Asia/Jerusalem')->format('Ymd'), $event->id);
        });
    }
}
