<?php

namespace App\Services\Messaging;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * D7: Israel quiet hours (21:30–08:30) + Shabbat guard (Fri 15:00 – Sat 20:30), Asia/Jerusalem.
 * Both ON by default per event; the host can turn either off, or override a single send.
 */
class QuietHoursGuard
{
    public function __construct(private bool $quiet = true, private bool $shabbat = true)
    {
    }

    public static function forSettings(?object $settings): self
    {
        return new self(
            $settings ? (bool) $settings->quiet_hours_enabled : true,
            $settings ? (bool) $settings->shabbat_guard_enabled : true,
        );
    }

    /** @return string[] conflicts at the given moment: quiet | shabbat */
    public function conflicts(CarbonInterface $at): array
    {
        $local = Carbon::instance($at)->setTimezone(config('whatsapp_messaging.timezone', 'Asia/Jerusalem'));
        $out = [];
        if ($this->quiet && $this->inQuiet($local)) {
            $out[] = 'quiet';
        }
        if ($this->shabbat && $this->inShabbat($local)) {
            $out[] = 'shabbat';
        }

        return $out;
    }

    public function allowed(CarbonInterface $at): bool
    {
        return $this->conflicts($at) === [];
    }

    /** First allowed moment at or after $at (5-minute resolution), returned in UTC. */
    public function nextAllowed(CarbonInterface $at): Carbon
    {
        $t = Carbon::instance($at)->utc()->second(0);
        for ($i = 0; $i < 2000; $i++) {
            if ($this->allowed($t)) {
                return $t;
            }
            $t = $t->copy()->addMinutes(5);
            $t->minute((int) (ceil($t->minute / 5) * 5) % 60);
        }

        return $t;
    }

    private function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    private function inQuiet(Carbon $local): bool
    {
        $now = $local->hour * 60 + $local->minute;
        $start = $this->minutes(config('whatsapp_messaging.quiet_hours.start', '21:30'));
        $end = $this->minutes(config('whatsapp_messaging.quiet_hours.end', '08:30'));

        return $start > $end ? ($now >= $start || $now < $end) : ($now >= $start && $now < $end);
    }

    private function inShabbat(Carbon $local): bool
    {
        $cfg = config('whatsapp_messaging.shabbat');
        $dow = $local->dayOfWeek; // 0 = Sunday … 5 = Friday, 6 = Saturday
        $now = $local->hour * 60 + $local->minute;
        if ($dow === (int) $cfg['start_day']) {
            return $now >= $this->minutes($cfg['start']);
        }
        if ($dow === (int) $cfg['end_day']) {
            return $now < $this->minutes($cfg['end']);
        }

        return false;
    }
}
