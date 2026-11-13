<?php

namespace App\Services\Enums;

class TriviaPaceEnum extends BaseEnum
{
    public const HOST = 'host';
    public const AUTO = 'auto';
    public const FREE = 'free';

    public static function all(): array
    {
        return [self::HOST, self::AUTO, self::FREE];
    }

    public static function isShared(string $pace): bool
    {
        return in_array($pace, [self::HOST, self::AUTO], true);
    }
}
