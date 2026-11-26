<?php

namespace App\Services\Addons;

use App\Models\AddonPurchase;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * No real charge. The "payment page" is the client's mock checkout (/addons/mock-checkout), which completes
 * through the dev endpoint (only when addons.mock_dev_endpoints is on and the driver is mock).
 */
class MockAddonGateway implements AddonPaymentGateway
{
    public function name(): string
    {
        return 'mock';
    }

    public function createPaymentPage(AddonPurchase $purchase, User $user): array
    {
        $client = rtrim((string) config('app.client_url'), '/');

        return [
            'page_request_uid'  => 'mock-' . Str::lower(Str::random(20)),
            'payment_page_link' => $client . '/addons/mock-checkout?purchase=' . urlencode($purchase->uid),
        ];
    }

    public function verifyCallback(array $data, string $raw_body): bool
    {
        // Mock purchases are only completed through AddonPurchaseService::devComplete().
        return false;
    }
}
