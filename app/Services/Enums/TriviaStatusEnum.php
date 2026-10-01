<?php

namespace App\Services\Enums;

class TriviaStatusEnum extends BaseEnum
{
    const DRAFT = 0,
          SCHEDULED = 1,
          LIVE = 2,
          RESULTS = 3;

    public static function name(int $status): string
    {
        return match ($status) {
            self::SCHEDULED => 'scheduled',
            self::LIVE => 'live',
            self::RESULTS => 'results',
            default => 'draft',
        };
    }
}
