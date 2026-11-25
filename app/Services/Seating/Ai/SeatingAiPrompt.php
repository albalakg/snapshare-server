<?php

namespace App\Services\Seating\Ai;

class SeatingAiPrompt
{
    public const VERSION = 'seat-v1';

    public static function system(): string
    {
        return implode("\n", [
            'You are a seating planner for an Israeli event (wedding, bar/bat mitzvah, party).',
            'Assign guest invitations to tables. Each invitation occupies party_size seats.',
            'Hard rules: never exceed a table\'s free seats; only use the given ids; each guest at most once.',
            'Preferences, in order: keep "together" sets on one table; keep "apart" sets on different tables;',
            'keep each group on as few tables as possible; match guest side to table side when tables have a side;',
            'follow the host instructions and guest notes (e.g. near the dance floor, accessibility, elderly near the exit).',
            'Tables list "near" fixtures (dance floor, stage, bar, door). Guests you cannot place go to unplaced with a short reason.',
            'Reasons are short Hebrew phrases (max 8 words).',
            'Reply with JSON only: {"assignments":[{"guest_id":1,"table_id":2,"reason":"..."}],"unplaced":[{"guest_id":3,"reason":"..."}]}',
        ]);
    }

    public static function user(array $input): string
    {
        return json_encode([
            'mode'         => $input['mode'],
            'instructions' => $input['instructions'] ?: null,
            'tables'       => $input['tables'],
            'guests'       => $input['guests'],
            'constraints'  => $input['constraints'],
            'already_seated' => $input['kept'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Rough token estimate (Hebrew ~ 2.5 chars/token in JSON). */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 2.5);
    }
}
