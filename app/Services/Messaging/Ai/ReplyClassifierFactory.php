<?php

namespace App\Services\Messaging\Ai;

/**
 * auto → OpenAI when an API key exists, otherwise rules only (IntentParser). mock → deterministic QA classifier.
 * Tests never reach OpenAI unless a classifier is bound in the container.
 */
class ReplyClassifierFactory
{
    public static function make(): ?ReplyClassifier
    {
        if (app()->bound(ReplyClassifier::class)) {
            return app(ReplyClassifier::class);
        }
        $cfg = (array) config('whatsapp_messaging.ai', []);
        $provider = (string) ($cfg['provider'] ?? 'auto');
        $key = (string) ($cfg['api_key'] ?? '');
        if ($provider === 'mock') {
            return new MockReplyClassifier();
        }
        if ($provider === 'rules' || app()->environment('testing')) {
            return null;
        }
        if (in_array($provider, ['auto', 'openai'], true) && $key !== '') {
            return new OpenAiReplyClassifier($key, (string) ($cfg['model'] ?? 'gpt-4o-mini'), (string) ($cfg['base_url'] ?? 'https://api.openai.com/v1'), (int) ($cfg['timeout'] ?? 8));
        }

        return null;
    }

    public static function name(): string
    {
        return self::make()?->name() ?? 'rules';
    }
}
