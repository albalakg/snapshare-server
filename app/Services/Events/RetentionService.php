<?php

namespace App\Services\Events;

use App\Models\Subscription;
use App\Services\Enums\SubscriptionEnum;
use Carbon\Carbon;

/**
 * Event retention (Gal t142u): Classic 180 days, Premium 365 days after the event ends.
 * Source of truth: subscriptions.storage_time (hours); the constants below are only the fallback.
 * Used by `event:warning` (F3, N days before) and `event:disable` (F4), so both always agree.
 * Trial events are not disabled by these commands (unchanged behaviour).
 */
class RetentionService
{
    public const FALLBACK_DAYS = [
        SubscriptionEnum::CLASSIC_ID => 180,
        SubscriptionEnum::PREMIUM_ID => 365,
    ];

    /** Days a finished event of this subscription keeps its files, or null when the command must not touch it. */
    public static function days(?int $subscription_id): ?int
    {
        if (!$subscription_id || !array_key_exists($subscription_id, self::FALLBACK_DAYS)) {
            return null;
        }
        $hours = (int) (Subscription::whereKey($subscription_id)->value('storage_time') ?? 0);
        return $hours >= 24 ? intdiv($hours, 24) : self::FALLBACK_DAYS[$subscription_id];
    }

    /** Date (Asia/Jerusalem) when the event will be disabled. */
    public static function until($finished_at, ?int $subscription_id): ?Carbon
    {
        $days = self::days($subscription_id);
        if ($days === null || !$finished_at) {
            return null;
        }
        return Carbon::parse($finished_at)->addDays($days)->timezone('Asia/Jerusalem');
    }

    /** Whole days since the event ended, measured the same way the legacy commands did. */
    public static function daysSince($finished_at, ?Carbon $now = null): int
    {
        return Carbon::parse($finished_at)->diffInDays(($now ?? Carbon::now())->copy()->endOfDay());
    }

    public static function shouldWarn($finished_at, ?int $subscription_id, ?Carbon $now = null): bool
    {
        $days = self::days($subscription_id);
        $before = (int) config('emails.triggers.deactivation_warning_days', 3);
        return $days !== null && $finished_at && self::daysSince($finished_at, $now) === $days - $before;
    }

    /**
     * Due on the retention day, or within a short catch-up window if the scheduler missed a run.
     * Deliberately NOT "everything older than retention": enabling the schedule must not mass-disable old events.
     */
    public static function shouldDisable($finished_at, ?int $subscription_id, ?Carbon $now = null): bool
    {
        $days = self::days($subscription_id);
        if ($days === null || !$finished_at) {
            return false;
        }
        $since = self::daysSince($finished_at, $now);
        return $since >= $days && $since <= $days + (int) config('emails.retention.catch_up_days', 7);
    }

    /** Event ids the disable command must never touch (local test events by default). */
    public static function isProtected(int $event_id): bool
    {
        return in_array($event_id, (array) config('emails.retention.protected_event_ids', []), true);
    }
}
