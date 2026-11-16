<?php

namespace App\Services\Enums;

class HubBlockTypeEnum extends BaseEnum
{
    // Legacy (always valid without template_id)
    const HERO_COUNTDOWN   = 'HERO_COUNTDOWN';
    const SNAPSHARE_CTA    = 'SNAPSHARE_CTA';
    const EVENT_TIMELINE   = 'EVENT_TIMELINE';
    const SMART_NAVIGATION = 'SMART_NAVIGATION';
    const WISHING_WELL     = 'WISHING_WELL';
    const LINK_LIST        = 'LINK_LIST';
    const RICH_TEXT        = 'RICH_TEXT';
    const MEDIA_STRIP      = 'MEDIA_STRIP';

    // Template-mode sections
    const STORY       = 'STORY';
    const ABOUT       = 'ABOUT';
    const PEOPLE      = 'PEOPLE';
    const CARDS       = 'CARDS';
    const GALLERY     = 'GALLERY';
    const STEPS       = 'STEPS';
    const MAP         = 'MAP';
    const RSVP        = 'RSVP';
    const FAQ         = 'FAQ';
    const QUOTE       = 'QUOTE';
    const TRIVIA_CTA  = 'TRIVIA_CTA';
    const GIFT        = 'GIFT';
    const MILESTONES  = 'MILESTONES';
    const PLAYLIST    = 'PLAYLIST';
    const SPEAKERS    = 'SPEAKERS';
    const SPONSORS    = 'SPONSORS';
    const NETWORKING  = 'NETWORKING';
    const HASHTAG     = 'HASHTAG';
    const LINEUP      = 'LINEUP';
    const BEEN_THERE  = 'BEEN_THERE';
    const GROUNDS     = 'GROUNDS';
    const ZONES       = 'ZONES';
    const RULES       = 'RULES';
    const FOOTER      = 'FOOTER';

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
            self::STORY,
            self::ABOUT,
            self::PEOPLE,
            self::CARDS,
            self::GALLERY,
            self::STEPS,
            self::MAP,
            self::RSVP,
            self::FAQ,
            self::QUOTE,
            self::TRIVIA_CTA,
            self::GIFT,
            self::MILESTONES,
            self::PLAYLIST,
            self::SPEAKERS,
            self::SPONSORS,
            self::NETWORKING,
            self::HASHTAG,
            self::LINEUP,
            self::BEEN_THERE,
            self::GROUNDS,
            self::ZONES,
            self::RULES,
            self::FOOTER,
        ];
    }
}
