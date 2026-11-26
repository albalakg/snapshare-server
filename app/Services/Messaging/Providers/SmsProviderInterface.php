<?php

namespace App\Services\Messaging\Providers;

/**
 * D6 SMS fallback for guests without WhatsApp. Only the mock driver is wired; a real Israeli provider
 * (recommendation in the t134u report) plugs in here once Gal opens an account.
 */
interface SmsProviderInterface
{
    public function name(): string;

    public function send(string $to, string $body): ProviderResult;
}
