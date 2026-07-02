<?php

namespace App\Services\Enums;

class MatchIntentEnum extends BaseEnum
{
    const ROMANCE     = 1,
          SOCIAL      = 2,
          CARPOOL     = 3,
          NETWORKING  = 4;

    public static function keyFromId(int $id): ?string
    {
        return match ($id) {
            self::ROMANCE    => 'ROMANCE',
            self::SOCIAL     => 'SOCIAL',
            self::CARPOOL    => 'CARPOOL',
            self::NETWORKING => 'NETWORKING',
            default          => null,
        };
    }

    public static function idFromKey(string $key): ?int
    {
        return match (strtoupper($key)) {
            'ROMANCE'    => self::ROMANCE,
            'SOCIAL'     => self::SOCIAL,
            'CARPOOL'    => self::CARPOOL,
            'NETWORKING' => self::NETWORKING,
            default      => null,
        };
    }

    public static function validKeys(): array
    {
        return ['ROMANCE', 'SOCIAL', 'CARPOOL', 'NETWORKING'];
    }
}
