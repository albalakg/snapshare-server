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
        // Only the mock is wired (D6). A real provider is added here once chosen.
        return new MockSmsDriver();
    }

    public static function isMock(): bool
    {
        return self::whatsappName() === 'mock';
    }
}
