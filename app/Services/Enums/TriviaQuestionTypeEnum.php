<?php

namespace App\Services\Enums;

class TriviaQuestionTypeEnum extends BaseEnum
{
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

    public static function unscored(): array
    {
        return [self::POLL, self::THISORTHAT];
    }

    public static function usesOptions(string $type): bool
    {
        return $type !== self::NUMBER;
    }

    public static function requiresCorrectOption(string $type): bool
    {
        return !in_array($type, [self::POLL, self::THISORTHAT, self::NUMBER], true);
    }
}
