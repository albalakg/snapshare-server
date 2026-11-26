<?php

namespace App\Services\Addons;

class AddonGatewayFactory
{
    /** auto → PayPlus only in production with PayPlus keys; otherwise the mock gateway (no real charge). */
    public static function driver(): string
    {
        $d = (string) config('addons.driver', 'auto');
        if ($d === 'payplus' || $d === 'mock') {
            return $d;
        }

        return app()->environment('production') && PayPlusAddonGateway::configured() ? 'payplus' : 'mock';
    }

    public static function make(): AddonPaymentGateway
    {
        if (app()->bound(AddonPaymentGateway::class)) {
            return app(AddonPaymentGateway::class);
        }

        return self::driver() === 'payplus' ? new PayPlusAddonGateway() : new MockAddonGateway();
    }
}
