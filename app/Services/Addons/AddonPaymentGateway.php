<?php

namespace App\Services\Addons;

use App\Models\AddonPurchase;
use App\Models\User;

interface AddonPaymentGateway
{
    public function name(): string;

    /**
     * Create a hosted payment page for the purchase.
     *
     * @return array{page_request_uid: string, payment_page_link: string, payload?: array}
     */
    public function createPaymentPage(AddonPurchase $purchase, User $user): array;

    /**
     * Validate a provider callback (signature + server-side verification). Must never trust the client.
     *
     * @param array{page_request_uid: ?string, approval_number: ?string, browser: ?string, hash: ?string} $data
     */
    public function verifyCallback(array $data, string $raw_body): bool;
}
