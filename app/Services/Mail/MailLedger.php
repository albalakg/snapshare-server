<?php

namespace App\Services\Mail;

use App\Models\MailDelivery;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Writes to mail_deliveries; fails open (never blocks a send) when the table is missing or the DB errors. */
class MailLedger
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = Schema::hasTable('mail_deliveries');
            } catch (Throwable) {
                self::$available = false;
            }
        }

        return self::$available;
    }

    public static function reset(): void
    {
        self::$available = null;
    }

    public static function seen(?string $dedupe): bool
    {
        if (!$dedupe || !self::available()) {
            return false;
        }
        try {
            return MailDelivery::where('dedupe_key', $dedupe)->whereIn('status', ['sent', 'deferred', 'sending'])->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public static function record(array $row): ?MailDelivery
    {
        if (!self::available()) {
            return null;
        }
        try {
            return MailDelivery::create($row);
        } catch (Throwable) {
            return null;
        }
    }
}
