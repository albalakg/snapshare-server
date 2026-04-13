<?php

namespace App\Services\Enums;

class SubscriptionEnum extends BaseEnum
{
    const TRIAL = 'נסיון';

    const TRIAL_ID = 1;

    const CLASSIC = 'קלאסי';

    const CLASSIC_ID = 2;

    const PREMIUM = 'פרימיום';

    const PREMIUM_ID = 3;

    /** @deprecated Use CLASSIC */
    const NORMAL = self::CLASSIC;

    /** @deprecated Use CLASSIC_ID */
    const NORMAL_ID = self::CLASSIC_ID;

    /**
     * @return array<string, int>
     */
    public static function getAll(): array
    {
        return [
            self::TRIAL => self::TRIAL_ID,
            self::CLASSIC => self::CLASSIC_ID,
            self::PREMIUM => self::PREMIUM_ID,
        ];
    }
}
