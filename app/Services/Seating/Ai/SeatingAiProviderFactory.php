<?php

namespace App\Services\Seating\Ai;

class SeatingAiProviderFactory
{
    public static function make(): SeatingAiProvider
    {
        if (app()->bound(SeatingAiProvider::class)) {
            return app(SeatingAiProvider::class);
        }

        $provider = (string) config('seating.ai.provider', 'auto');
        $key = (string) config('seating.ai.api_key', '');

        if ($provider === 'openai' || ($provider === 'auto' && $key !== '')) {
            if ($key === '') {
                // Explicit openai without a key: fall back to mock rather than failing every run.
                return new MockSeatingProvider();
            }

            return new OpenAiSeatingProvider(
                $key,
                (string) config('seating.ai.model', 'gpt-4o-mini'),
                (string) config('seating.ai.base_url', 'https://api.openai.com/v1'),
                (int) config('seating.ai.timeout', 45)
            );
        }

        return new MockSeatingProvider();
    }
}
