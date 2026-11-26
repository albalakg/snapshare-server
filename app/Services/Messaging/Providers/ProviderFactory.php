<?php

namespace App\Services\Messaging\Providers;

class ProviderFactory
{
    public static function whatsapp(): WhatsAppProviderInterface
    {
        return self::whatsappName() === 'meta' ? new MetaCloudApiDriver() : new MockWhatsAppDriver();
    }

    public static function whatsappName(): string
    {
        // Tests never reach Meta.
        if (app()->environment('testing')) {
            return 'mock';
        }
        $driver = (string) config('whatsapp_messaging.driver', 'auto');
        if ($driver === 'meta' || $driver === 'mock') {
            return $driver;
        }

        return config('whatsapp_messaging.meta.access_token') && config('whatsapp_messaging.meta.phone_number_id') ? 'meta' : 'mock';
    }

    public static function sms(): SmsProviderInterface
    {
        if (app()->bound(SmsProviderInterface::class)) {
            return app(SmsProviderInterface::class);
        }

        return self::smsName() === 'micropay' ? new MicropaySmsDriver() : new MockSmsDriver();
    }

    /** Mock by default. Micropay only when explicitly selected AND a token exists; tests never reach it. */
    public static function smsName(): string
    {
        if (app()->environment('testing')) {
            return 'mock';
        }

        return config('whatsapp_messaging.sms.driver') === 'micropay' && MicropaySmsDriver::configured() ? 'micropay' : 'mock';
    }

    /**
     * SMS fallback may run: a real SMS provider is wired, or the whole stack is in mock mode (local QA).
     * Prevents "sent" SMS rows in production while only the mock SMS driver exists.
     */
    public static function smsAvailable(): bool
    {
        return self::isMock() || self::sms()->name() !== 'mock';
    }

    public static function isMock(): bool
    {
        return self::whatsappName() === 'mock';
    }
}
