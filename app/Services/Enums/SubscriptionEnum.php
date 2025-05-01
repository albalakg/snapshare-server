<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class SubscriptionEnum extends BaseEnum
{
    const NORMAL = 'בסיסי';
    const NORMAL_ID = 1;
    const PREMIUM = 'פרימיום';
    const PREMIUM_ID = 2;
    
    /**
     * @return array
    */
    public static function getAll(): array
    {
        return [
            self::NORMAL => self::NORMAL_ID,
            self::PREMIUM => self::PREMIUM_ID,
        ];         
    }
}