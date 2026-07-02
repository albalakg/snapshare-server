<?php

namespace App\Services\Enums;

class HubBlockTypeEnum extends BaseEnum
{
    const HERO_COUNTDOWN   = 'HERO_COUNTDOWN';
    const SNAPSHARE_CTA    = 'SNAPSHARE_CTA';
    const EVENT_TIMELINE   = 'EVENT_TIMELINE';
    const SMART_NAVIGATION = 'SMART_NAVIGATION';
    const WISHING_WELL     = 'WISHING_WELL';
    const LINK_LIST        = 'LINK_LIST';
    const RICH_TEXT        = 'RICH_TEXT';
    const MEDIA_STRIP      = 'MEDIA_STRIP';

    public static function validKeys(): array
    {
        return [
            self::HERO_COUNTDOWN,
            self::SNAPSHARE_CTA,
            self::EVENT_TIMELINE,
            self::SMART_NAVIGATION,
            self::WISHING_WELL,
            self::LINK_LIST,
            self::RICH_TEXT,
            self::MEDIA_STRIP,
        ];
    }
}
