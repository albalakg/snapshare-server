<?php

namespace App\Services\Messaging;

use App\Models\Event;
use App\Models\EventWhatsAppSetting;
use App\Models\Order;
use App\Models\WhatsAppSendRecipient;
use Illuminate\Support\Facades\DB;

/**
 * D1/D3 per-guest message quota. TODO(pricing): Pro add-on / top-ups are flags on event_whatsapp_settings
 * until checkout is wired; subscription prices are untouched.
 *
 * A credit is used when the provider accepts a template message (credited=1).
 * Pending / sending rows reserve a credit so the queue can never oversell a guest.
 */
class QuotaService
{
    public const RESERVING = ['pending', 'sending'];

    public function package(Event $event): array
    {
        $packages = config('whatsapp_messaging.pricing.packages');
        $sub = null;
        if ($event->order_id) {
            $sub = Order::where('id', $event->order_id)->value('subscription_id');
        }
        $id = $sub && isset($packages[$sub]) ? (int) $sub : (int) config('whatsapp_messaging.pricing.default_package_id', 1);

        return ['id' => $id] + ($packages[$id] ?? reset($packages));
    }

    public function perGuest(Event $event, ?EventWhatsAppSetting $settings): int
    {
        $base = (int) $this->package($event)['per_guest'];
        if ($settings?->pro_addon) {
            $base = max($base, (int) config('whatsapp_messaging.pricing.pro_addon.per_guest', 6));
        }

        return $base + (int) ($settings?->topups ?? 0) * (int) config('whatsapp_messaging.pricing.topup.per_guest', 2);
    }

    /** guest_id => ['used' => n, 'reserved' => n] for the event, optionally ignoring one send. */
    public function usage(int $event_id, ?int $exclude_send_id = null): array
    {
        $q = WhatsAppSendRecipient::where('event_id', $event_id)
            ->where(fn ($w) => $w->where('credited', true)->orWhereIn('status', self::RESERVING))
            ->select('guest_id', DB::raw('SUM(CASE WHEN credited = 1 THEN 1 ELSE 0 END) as used'), DB::raw("SUM(CASE WHEN credited = 0 AND status IN ('pending','sending') THEN 1 ELSE 0 END) as reserved"))
            ->groupBy('guest_id');
        if ($exclude_send_id) {
            $q->where('send_id', '!=', $exclude_send_id);
        }
        $out = [];
        foreach ($q->get() as $row) {
            $out[(int) $row->guest_id] = ['used' => (int) $row->used, 'reserved' => (int) $row->reserved];
        }

        return $out;
    }

    /** Distinct guests that already received or are queued for a message (Trial guest cap). */
    public function messagedGuestIds(int $event_id, ?int $exclude_send_id = null): array
    {
        $q = WhatsAppSendRecipient::where('event_id', $event_id)
            ->where(fn ($w) => $w->where('credited', true)->orWhereIn('status', self::RESERVING));
        if ($exclude_send_id) {
            $q->where('send_id', '!=', $exclude_send_id);
        }

        return $q->distinct()->pluck('guest_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Split candidate guest ids into allowed / over quota, given the current usage.
     *
     * @return array{allowed: int[], over: int[], guest_cap: int[]}
     */
    public function filter(Event $event, ?EventWhatsAppSetting $settings, array $guest_ids, ?int $exclude_send_id = null): array
    {
        $limit = $this->perGuest($event, $settings);
        $usage = $this->usage($event->id, $exclude_send_id);
        $maxGuests = $settings?->pro_addon ? null : ($this->package($event)['max_guests'] ?? null);
        $messaged = $maxGuests ? array_flip($this->messagedGuestIds($event->id, $exclude_send_id)) : [];

        $allowed = $over = $cap = [];
        foreach ($guest_ids as $id) {
            $u = $usage[$id] ?? ['used' => 0, 'reserved' => 0];
            if ($u['used'] + $u['reserved'] >= $limit) {
                $over[] = $id;
                continue;
            }
            if ($maxGuests && !isset($messaged[$id])) {
                if (count($messaged) >= $maxGuests) {
                    $cap[] = $id;
                    continue;
                }
                $messaged[$id] = true;
            }
            $allowed[] = $id;
        }

        return ['allowed' => $allowed, 'over' => $over, 'guest_cap' => $cap];
    }

    public function summary(Event $event, ?EventWhatsAppSetting $settings, int $guests_with_phone): array
    {
        $limit = $this->perGuest($event, $settings);
        $usage = $this->usage($event->id);
        $used = array_sum(array_column($usage, 'used'));
        $reserved = array_sum(array_column($usage, 'reserved'));
        $package = $this->package($event);
        $capacity = $limit * $guests_with_phone;

        return [
            'package'           => ['id' => $package['id'], 'key' => $package['key'], 'label' => $package['label']],
            'per_guest'         => $limit,
            'max_guests'        => $settings?->pro_addon ? null : ($package['max_guests'] ?? null),
            'pro_addon'         => (bool) $settings?->pro_addon,
            'topups'            => (int) ($settings?->topups ?? 0),
            'guests_with_phone' => $guests_with_phone,
            'capacity'          => $capacity,
            'used'              => $used,
            'reserved'          => $reserved,
            'remaining'         => max(0, $capacity - $used - $reserved),
            'pricing'           => [
                'checkout_enabled' => (bool) config('whatsapp_messaging.pricing.enabled'), // TODO(pricing)
                'packages'         => array_values(array_map(fn ($p, $id) => ['id' => $id] + $p, config('whatsapp_messaging.pricing.packages'), array_keys(config('whatsapp_messaging.pricing.packages')))),
                'pro_addon'        => config('whatsapp_messaging.pricing.pro_addon'),
                'topup'            => config('whatsapp_messaging.pricing.topup'),
                'currency'         => config('whatsapp_messaging.pricing.currency'),
            ],
        ];
    }
}
