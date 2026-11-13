<?php

namespace App\Services\Enums;

class TriviaPhaseEnum extends BaseEnum
{
    public const LOBBY = 'lobby';
    public const QUESTION = 'question';
    public const REVEAL = 'reveal';
    public const LEADERBOARD = 'leaderboard';
    public const PHOTOS = 'photos';
    public const PODIUM = 'podium';

    public static function all(): array
    {
        return [
            self::LOBBY,
            self::QUESTION,
            self::REVEAL,
            self::LEADERBOARD,
            self::PHOTOS,
            self::PODIUM,
        ];
    }
}
