<?php

namespace App\Services\Enums;

class TriviaQuestionTypeEnum extends BaseEnum
{
    // MVP types
    public const CHOICE = 'choice';
    public const MULTI = 'multi';
    public const TRUEFALSE = 'truefalse';
    public const WHO = 'who';
    public const WHOSAID = 'whosaid';
    public const POLL = 'poll';
    public const THISORTHAT = 'thisorthat';
    public const TWOTRUTHS = 'twothruths';
    public const NUMBER = 'number';
    public const PHOTO = 'photo';

    // v4 / phase-2 types
    public const BUZZER = 'buzzer';
    public const TUGOFWAR = 'tugofwar';
    public const BLUR = 'blur';
    public const SPOTDIFF = 'spotdiff';
    public const VIDEO = 'video';
    public const CLOUD = 'cloud';
    public const RATE = 'rate';
    public const FINISH = 'finish';
    public const DATE = 'date';
    public const ORDER = 'order';
    public const MAP = 'map';
    public const MATCH = 'match';
    public const FILLBLANK = 'fillblank';
    public const EMOJI = 'emoji';
    public const AUDIO = 'audio';

    /**
     * The original MVP set (kept for backwards compatibility).
     */
    public static function mvp(): array
    {
        return [
            self::CHOICE,
            self::MULTI,
            self::TRUEFALSE,
            self::WHO,
            self::WHOSAID,
            self::POLL,
            self::THISORTHAT,
            self::TWOTRUTHS,
            self::NUMBER,
            self::PHOTO,
        ];
    }

    /**
     * Every type a host may create and a guest may play (MVP + v4).
     */
    public static function playable(): array
    {
        return array_merge(self::mvp(), [
            self::BUZZER,
            self::TUGOFWAR,
            self::BLUR,
            self::SPOTDIFF,
            self::VIDEO,
            self::CLOUD,
            self::RATE,
            self::FINISH,
            self::DATE,
            self::ORDER,
            self::MAP,
            self::MATCH,
            self::FILLBLANK,
            self::EMOJI,
            self::AUDIO,
        ]);
    }

    /**
     * Types that never award points.
     */
    public static function unscored(): array
    {
        return [
            self::POLL,
            self::THISORTHAT,
            self::TUGOFWAR,
            self::CLOUD,
            self::RATE,
            self::FINISH,
        ];
    }

    /**
     * Types scored by "closest wins" (distance from a configured correct value).
     */
    public static function closest(): array
    {
        return [self::NUMBER, self::DATE, self::MAP];
    }

    /**
     * Free-text types without scoring (stored in value_text).
     */
    public static function freeText(): array
    {
        return [self::CLOUD, self::FINISH];
    }

    /**
     * Types that have no options at all (answer lives in config / value_*).
     */
    public static function optionless(): array
    {
        return [
            self::NUMBER,
            self::DATE,
            self::CLOUD,
            self::RATE,
            self::FINISH,
            self::FILLBLANK,
            self::MAP,
        ];
    }

    public static function usesOptions(string $type): bool
    {
        return !in_array($type, self::optionless(), true);
    }

    /**
     * Types where at least one option must be flagged is_correct.
     */
    public static function requiresCorrectOption(string $type): bool
    {
        return self::usesOptions($type)
            && !in_array($type, [
                self::POLL,
                self::THISORTHAT,
                self::TUGOFWAR,
                self::ORDER,
                self::MATCH,
            ], true);
    }
}
