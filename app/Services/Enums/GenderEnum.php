<?php

namespace App\Services\Enums;

class GenderEnum extends BaseEnum
{
    const MALE   = 'MALE',
          FEMALE = 'FEMALE',
          OTHER  = 'OTHER';

    public static function validValues(): array
    {
        return [self::MALE, self::FEMALE, self::OTHER];
    }
}
