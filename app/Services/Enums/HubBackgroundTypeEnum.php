<?php

namespace App\Services\Enums;

class HubBackgroundTypeEnum extends BaseEnum
{
    const LIGHT = 'LIGHT';
    const DARK  = 'DARK';

    public static function validKeys(): array
    {
        return [self::LIGHT, self::DARK];
    }
}
