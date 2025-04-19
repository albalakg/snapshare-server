<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class RoleEnum extends BaseEnum
{
    const ADMIN = 'admin';
    const ADMIN_ID = 10;
    const USER = 'user';
    const USER_ID = 20;
    
    /**
     * @return array
    */
    public static function getAll(): array
    {
        return [
            self::ADMIN => self::ADMIN_ID,
            self::USER => self::USER_ID,
        ];         
    }
}