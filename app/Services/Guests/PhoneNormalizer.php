<?php

namespace App\Services\Guests;

use Exception;

class PhoneNormalizer
{
    public static function normalize(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public static function hash(string $phone): string
    {
        return hash('sha256', self::normalize($phone));
    }

    /**
     * @throws Exception
     */
    public static function validate(string $phone): string
    {
        $normalized = self::normalize($phone);

        if ($normalized === '' || !preg_match('/^\d{7,15}$/', $normalized)) {
            throw new Exception('Invalid phone number');
        }

        return $normalized;
    }

    /**
     * Convert a stored phone to international format for WhatsApp (+972…).
     * Local Israeli numbers starting with 0 become 972…; numbers already in 972 form are unchanged.
     *
     * @throws Exception
     */
    public static function toWhatsApp(string $phone): string
    {
        $normalized = self::validate($phone);

        if (str_starts_with($normalized, '972')) {
            return $normalized;
        }

        if (str_starts_with($normalized, '0')) {
            return '972' . substr($normalized, 1);
        }

        return $normalized;
    }
}
