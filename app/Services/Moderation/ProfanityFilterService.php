<?php

namespace App\Services\Moderation;

class ProfanityFilterService
{
    public function containsProfanity(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return false;
        }

        foreach ($this->wordLists() as $word) {
            $pattern = '/\b' . preg_quote(mb_strtolower($word), '/') . '\b/u';
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function wordLists(): array
    {
        return array_merge(
            config('moderation.profanity_en', []),
            config('moderation.profanity_he', []),
        );
    }
}
