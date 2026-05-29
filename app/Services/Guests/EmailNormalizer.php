<?php

namespace App\Services\Guests;

use Exception;

class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function hash(string $email): string
    {
        return hash('sha256', self::normalize($email));
    }

    /**
     * @throws Exception
     */
    public static function validate(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $normalized = self::normalize($email);

        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }

        return $normalized;
    }
}
