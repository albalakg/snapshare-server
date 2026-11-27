<?php

namespace App\Services\Addons;

use App\Exceptions\SeatingHttpException;
use App\Models\AddonPurchase;
use App\Models\EventEntitlement;
use App\Models\User;
use App\Services\Helpers\LogService;
use App\Services\Messaging\MessagingContext;
use App\Services\Seating\SeatingEntitlement;
use App\Services\Seating\SeatingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Per-event add-on purchases (t135u). Own table + callback; plan orders / prices are untouched.
 *
 * Flow: checkout() → pending purchase + hosted payment page (PayPlus or mock) → provider callback
 * (/api/store/addon-confirmation, signature + IPN verified) → markPaid() credits the event exactly once
 * (row lock + credited_at), so repeated / concurrent callbacks are no-ops.
 */
class AddonPurchaseService
{
    public const OPEN = ['pending', 'in_progress'];

    private LogService $log;

    public function __construct(private ?AddonPaymentGateway $gateway = null)
    {
        $this->log = new LogService('payment');
    }

    private function gateway(): AddonPaymentGateway
    {
        return $this->gateway ??= AddonGatewayFactory::make();
    }

    private function assertAccess(int $event_id, int $user_id): void
    {
        try {
            (new SeatingService())->crm()->assertAccess($event_id, $user_id);
        } catch (SeatingHttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SeatingHttpException('Event not found', 404);
        }
    }

    // ───────────────────────── read ─────────────────────────

    public function catalog(int $event_id, int $user_id): array
    {
        $this->assertAccess($event_id, $user_id);
        $s = MessagingContext::settings($event_id);
        $ent = EventEntitlement::where('event_id', $event_id)->first();
        $seating = SeatingEntitlement::describe($event_id);

        $products = [];
        foreach ((array) config('addons.products', []) as $key => $p) {
            $owned = match ($key) {
                'wa_pro'        => (bool) $s->pro_addon,
                'seating_addon' => (bool) $ent?->seating_unlocked_at,
                default         => false,
            };
            $available = match ($key) {
                'wa_pro'           => !$s->pro_addon,
                'wa_topup'         => true,
                'seating_addon'    => (bool) config('seating.paywall.enabled', false) && !$seating['enabled'] && $seating['feature_enabled'],
                'seating_ai_topup' => $seating['enabled'],
                default            => false,
            };
            $products[] = [
                'key' => $key, 'title' => $p['title'], 'description' => $p['description'] ?? null,
                'price' => (float) $p['price'], 'currency' => config('addons.currency', 'ILS'),
                'max_quantity' => (int) ($p['max_quantity'] ?? 1), 'feature' => $p['feature'] ?? null,
                'runs' => $p['runs'] ?? null, 'owned' => $owned, 'available' => $available,
            ];
        }

        return [
            'enabled'   => (bool) config('addons.enabled', false),
            'driver'    => AddonGatewayFactory::driver(),
            'is_mock'   => AddonGatewayFactory::driver() === 'mock',
            'products'  => $products,
            'whatsapp'  => ['pro_addon' => (bool) $s->pro_addon, 'topups' => (int) $s->topups],
            'seating'   => ['unlocked' => (bool) $ent?->seating_unlocked_at, 'ai_extra_runs' => (int) ($ent?->seating_ai_extra_runs ?? 0), 'paywall' => (bool) config('seating.paywall.enabled', false)],
            'purchases' => AddonPurchase::where('event_id', $event_id)->orderByDesc('id')->limit(20)->get()->map(fn ($p) => $this->format($p))->values()->all(),
        ];
    }

    public function show(int $event_id, int $user_id, string $uid): array
    {
        $this->assertAccess($event_id, $user_id);

        return $this->format($this->find($event_id, $uid));
    }

    /** Lookup by uid for the post-payment return page (owner only, no event id in the URL). */
    public function showForUser(int $user_id, string $uid): array
    {
        $p = AddonPurchase::where('uid', $uid)->first();
        if (!$p) {
            throw new SeatingHttpException('Purchase not found', 404);
        }
        $this->assertAccess((int) $p->event_id, $user_id);

        return $this->format($p);
    }

    // ───────────────────────── checkout ─────────────────────────

    public function checkout(int $event_id, int $user_id, string $product, int $quantity = 1): array
    {
        $this->assertAccess($event_id, $user_id);
        if (!config('addons.enabled', false)) {
            throw new SeatingHttpException('Add-on purchases are not enabled yet', 403);
        }
        $p = config("addons.products.{$product}");
        if (!$p) {
            throw new SeatingHttpException('Unknown product', 422);
        }
        $max = (int) ($p['max_quantity'] ?? 1);
        if ($quantity < 1 || $quantity > $max) {
            throw new SeatingHttpException("Quantity must be between 1 and {$max}", 422);
        }
        $this->assertPurchasable($event_id, $product);

        $user = User::findOrFail($user_id);
        // Abandon older open checkouts for the same product (their page can no longer credit: see markPaid()).
        AddonPurchase::where('event_id', $event_id)->where('product', $product)->whereIn('status', self::OPEN)
            ->update(['status' => 'cancelled', 'failure_reason' => 'superseded']);

        $unit = (float) $p['price'];
        $purchase = AddonPurchase::create([
            'uid' => 'ad_' . Str::lower(Str::random(24)), 'event_id' => $event_id, 'user_id' => $user_id,
            'product' => $product, 'quantity' => $quantity, 'unit_price' => $unit, 'amount' => round($unit * $quantity, 2),
            'currency' => config('addons.currency', 'ILS'), 'status' => 'pending', 'provider' => $this->gateway()->name(),
        ]);

        try {
            $page = $this->gateway()->createPaymentPage($purchase, $user);
        } catch (Throwable $e) {
            $purchase->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 250)]);
            throw new SeatingHttpException('Could not open the payment page, please try again', 502);
        }
        $purchase->update([
            'status' => 'in_progress', 'page_request_uid' => $page['page_request_uid'], 'payment_page_link' => $page['payment_page_link'],
        ]);
        $this->log->info('Addon checkout created', ['purchase' => $purchase->uid, 'product' => $product, 'event_id' => $event_id, 'provider' => $purchase->provider]);

        return ['purchase' => $this->format($purchase->refresh()), 'payment_page_link' => $page['payment_page_link']];
    }

    private function assertPurchasable(int $event_id, string $product): void
    {
        if ($product === 'wa_pro' && MessagingContext::settings($event_id)->pro_addon) {
            throw new SeatingHttpException('WhatsApp Pro is already active for this event', 409);
        }
        if ($product === 'seating_addon') {
            if (!config('seating.enabled', false)) {
                throw new SeatingHttpException('Seating is not enabled', 403);
            }
            if (EventEntitlement::where('event_id', $event_id)->whereNotNull('seating_unlocked_at')->exists()) {
                throw new SeatingHttpException('Seating is already unlocked for this event', 409);
            }
        }
        if ($product === 'seating_ai_topup' && !SeatingEntitlement::enabled($event_id)) {
            throw new SeatingHttpException('Unlock seating first', 402, ['entitlement' => SeatingEntitlement::describe($event_id)]);
        }
    }

    // ───────────────────────── confirmation ─────────────────────────

    /**
     * Provider callback (public). Invalid / unverifiable callbacks never change state.
     */
    public function confirmCallback(array $data, string $raw_body): array
    {
        $uid = (string) ($data['page_request_uid'] ?? '');
        $purchase = $uid !== '' ? AddonPurchase::where('page_request_uid', $uid)->first() : null;
        if (!$purchase) {
            $this->log->error('Addon callback: unknown page_request_uid', ['page_request_uid' => $uid]);
            throw new SeatingHttpException('Purchase not found', 404);
        }
        if ($purchase->provider !== 'payplus' || !$this->gateway()->verifyCallback($data, $raw_body)) {
            $this->log->error('Addon callback rejected', ['purchase' => $purchase->uid]);
            \App\Services\Mail\EmailTriggers::paymentCallbackRejected('addon', $purchase->uid, 'payment callback verification failed');
            throw new SeatingHttpException('Invalid payment callback', 400);
        }

        return $this->format($this->markPaid($purchase->id, (string) $data['approval_number'], ['callback' => true]));
    }

    /** Mock driver only: simulate the provider result from the mock checkout page. */
    public function devComplete(int $event_id, int $user_id, string $uid, string $outcome): array
    {
        $this->assertAccess($event_id, $user_id);
        if (!$this->devAllowed()) {
            throw new SeatingHttpException('Not found', 404);
        }
        $purchase = $this->find($event_id, $uid);
        if ($purchase->provider !== 'mock') {
            throw new SeatingHttpException('Not found', 404);
        }
        if ($outcome !== 'success') {
            if (in_array($purchase->status, self::OPEN, true)) {
                $purchase->update(['status' => $outcome === 'cancel' ? 'cancelled' : 'failed', 'failure_reason' => 'mock ' . $outcome]);
            }

            return $this->format($purchase->refresh());
        }

        return $this->format($this->markPaid($purchase->id, 'MOCK-' . strtoupper(Str::random(6)), ['mock' => true]));
    }

    public function devAllowed(): bool
    {
        return config('addons.mock_dev_endpoints', false) && AddonGatewayFactory::driver() === 'mock' && !app()->environment('production');
    }

    /**
     * Idempotent: the row is locked; an already-credited purchase is returned unchanged.
     */
    public function markPaid(int $purchase_id, string $approval_number, array $meta = []): AddonPurchase
    {
        return DB::transaction(function () use ($purchase_id, $approval_number, $meta) {
            /** @var AddonPurchase $p */
            $p = AddonPurchase::whereKey($purchase_id)->lockForUpdate()->firstOrFail();
            if ($p->credited_at) {
                $this->log->info('Addon already credited (idempotent no-op)', ['purchase' => $p->uid]);

                return $p;
            }
            // A paid callback for a superseded/cancelled checkout is still money taken → credit it.
            $p->fill([
                'status' => 'paid', 'approval_number' => mb_substr($approval_number, 0, 64), 'paid_at' => $p->paid_at ?? now(),
                'provider_payload' => array_merge((array) $p->provider_payload, $meta), 'failure_reason' => null,
            ]);
            $this->credit($p);
            $p->credited_at = now();
            $p->save();
            $this->log->info('Addon credited', ['purchase' => $p->uid, 'product' => $p->product, 'quantity' => $p->quantity, 'event_id' => $p->event_id]);

            return $p;
        });
    }

    private function credit(AddonPurchase $p): void
    {
        $event_id = (int) $p->event_id;
        switch ($p->product) {
            case 'wa_pro':
                $s = MessagingContext::settings($event_id);
                $s->pro_addon = true;
                $s->save();
                break;
            case 'wa_topup':
                $s = MessagingContext::settings($event_id);
                $s->topups = (int) $s->topups + (int) $p->quantity;
                $s->save();
                break;
            case 'seating_addon':
                $e = EventEntitlement::firstOrCreate(['event_id' => $event_id]);
                $e->seating_unlocked_at ??= now();
                $e->seating_purchase_id ??= $p->id;
                $e->save();
                break;
            case 'seating_ai_topup':
                $runs = (int) config('addons.products.seating_ai_topup.runs', 10);
                $e = EventEntitlement::firstOrCreate(['event_id' => $event_id]);
                $e->seating_ai_extra_runs = (int) $e->seating_ai_extra_runs + $runs * (int) $p->quantity;
                $e->save();
                break;
            default:
                throw new SeatingHttpException('Unknown product', 422);
        }
    }

    // ───────────────────────── helpers ─────────────────────────

    private function find(int $event_id, string $uid): AddonPurchase
    {
        $p = AddonPurchase::where('event_id', $event_id)->where('uid', $uid)->first();
        if (!$p) {
            throw new SeatingHttpException('Purchase not found', 404);
        }

        return $p;
    }

    public function format(AddonPurchase $p): array
    {
        return [
            'uid' => $p->uid, 'event_id' => (int) $p->event_id, 'product' => $p->product,
            'title' => (string) config("addons.products.{$p->product}.title", $p->product),
            'quantity' => (int) $p->quantity, 'unit_price' => (float) $p->unit_price, 'amount' => (float) $p->amount,
            'currency' => $p->currency, 'status' => $p->status, 'provider' => $p->provider,
            'credited' => (bool) $p->credited_at, 'paid_at' => optional($p->paid_at)->toIso8601String(),
            'created_at' => optional($p->created_at)->toIso8601String(),
        ];
    }
}
