<?php

namespace App\Services\Seating;

use App\Exceptions\SeatingHttpException;
use App\Models\Event;
use App\Models\EventEntitlement;
use App\Models\Order;

/**
 * Entitlement for the seating builder + AI.
 *
 * 1. config('seating.enabled') — global feature flag (ON for local/testing, OFF elsewhere unless SEATING_ENABLED=true).
 * 2. config('seating.paywall.enabled') — when ON, the event also needs the paid seating add-on
 *    (event_entitlements.seating_unlocked_at, credited by AddonPurchaseService after a successful payment)
 *    or a plan listed in seating.paywall.included_subscriptions. Locked → HTTP 402 with a paywall payload.
 *
 * TODO(pricing): price/packaging not confirmed by Gal (config/addons.php seating_addon / seating_ai_topup).
 * The guest CRM (/guests) is intentionally NOT gated.
 */
class SeatingEntitlement
{
    public static function enabled(int $event_id): bool
    {
        return self::featureOn() && self::unlocked($event_id);
    }

    public static function assert(int $event_id): void
    {
        if (!self::featureOn()) {
            throw new SeatingHttpException('Seating is not enabled for this event', 403, ['entitlement' => self::describe($event_id)]);
        }
        if (!self::unlocked($event_id)) {
            throw new SeatingHttpException('Seating add-on required', 402, ['entitlement' => self::describe($event_id)]);
        }
    }

    public static function describe(int $event_id): array
    {
        $paywall = self::paywallOn();
        $ent = EventEntitlement::where('event_id', $event_id)->first();
        $source = 'feature_flag';
        if ($paywall) {
            $source = $ent?->seating_unlocked_at ? 'purchase' : (self::includedByPlan($event_id) ? 'plan' : 'locked');
        }

        return [
            'enabled'         => self::enabled($event_id),
            'feature_enabled' => self::featureOn(),
            'paywall'         => $paywall,
            'source'          => $source,
            'addon'           => 'seating',
            'unlocked_at'     => optional($ent?->seating_unlocked_at)->toIso8601String(),
            'ai_extra_runs'   => (int) ($ent?->seating_ai_extra_runs ?? 0),
            'products'        => [
                'seating_addon'    => self::product('seating_addon'),
                'seating_ai_topup' => self::product('seating_ai_topup'),
            ],
            'checkout_enabled' => (bool) config('addons.enabled', false),
            // TODO(pricing): defaults until Gal confirms.
            'pending_pricing' => true,
        ];
    }

    public static function extraAiRuns(int $event_id): int
    {
        return (int) (EventEntitlement::where('event_id', $event_id)->value('seating_ai_extra_runs') ?? 0);
    }

    private static function featureOn(): bool
    {
        return (bool) config('seating.enabled', false);
    }

    private static function paywallOn(): bool
    {
        return (bool) config('seating.paywall.enabled', false);
    }

    private static function unlocked(int $event_id): bool
    {
        if (!self::paywallOn()) {
            return true;
        }
        if (EventEntitlement::where('event_id', $event_id)->whereNotNull('seating_unlocked_at')->exists()) {
            return true;
        }

        return self::includedByPlan($event_id);
    }

    private static function includedByPlan(int $event_id): bool
    {
        $included = (array) config('seating.paywall.included_subscriptions', []);
        if (!$included) {
            return false;
        }
        $order_id = Event::where('id', $event_id)->value('order_id');
        $sub = $order_id ? Order::where('id', $order_id)->value('subscription_id') : null;

        return $sub !== null && in_array((int) $sub, array_map('intval', $included), true);
    }

    private static function product(string $key): ?array
    {
        $p = config("addons.products.{$key}");

        return $p ? ['key' => $key, 'title' => $p['title'], 'price' => (float) $p['price'], 'runs' => $p['runs'] ?? null, 'description' => $p['description'] ?? null, 'max_quantity' => (int) ($p['max_quantity'] ?? 1)] : null;
    }
}
