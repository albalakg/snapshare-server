<?php

namespace App\Services\Messaging\Providers;

/**
 * D6 SMS fallback for guests without WhatsApp. Drivers: MockSmsDriver (default) and MicropaySmsDriver
 * (SMS_DRIVER=micropay + MICROPAY_SMS_TOKEN), selected in ProviderFactory::sms().
 */
interface SmsProviderInterface
{
    public function name(): string;

    public function send(string $to, string $body): ProviderResult;
}
