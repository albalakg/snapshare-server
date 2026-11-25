<?php

namespace App\Services\Seating;

use App\Exceptions\SeatingHttpException;

/**
 * Entitlement stub for the seating builder + AI.
 *
 * TODO(pricing): replace with a real per-event paid add-on check once Gal confirms
 * packaging and price (PLAN.md D1/D2/D4). Today it is a config flag only:
 * config('seating.enabled') — ON for local/testing, OFF elsewhere unless SEATING_ENABLED=true.
 * The guest CRM (/guests) is intentionally NOT gated.
 */
class SeatingEntitlement
{
    public static function enabled(int $event_id): bool
    {
        return (bool) config('seating.enabled', false);
    }

    public static function assert(int $event_id): void
    {
        if (!self::enabled($event_id)) {
            throw new SeatingHttpException('Seating is not enabled for this event', 403, ['entitlement' => self::describe($event_id)]);
        }
    }

    public static function describe(int $event_id): array
    {
        return [
            'enabled' => self::enabled($event_id),
            'source'  => 'feature_flag',
            'addon'   => 'seating',
            // Pricing is intentionally absent until confirmed.
            'pending_pricing' => true,
        ];
    }
}
