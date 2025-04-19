<?php

namespace App\Services\Orders\Providers;

use App\Models\User;
use App\Models\Order;
use App\Models\Subscription;


interface IPaymentProvider
{
    public function getProviderID(): int;
    public function getTransactionResponse();
    public function getGeneratedPageToken(): string;
    public function getGeneratedPageLink(): string;
    public function buildPayment(Order $order, User $user, Subscription $subscription): self;
    public function startTransaction();
    public function sendInvoice(Order $order, User $user, Subscription $subscription);
    public function isTransactionValid(): bool;
    public function isInvoiceValid(): bool;
    public function isPaymentCallbackValid(array $response): bool;
}
