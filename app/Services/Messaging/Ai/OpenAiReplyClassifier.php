<?php

namespace App\Services\Messaging\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** OpenAI chat-completions in JSON mode (same pattern as OpenAiSeatingProvider). */
class OpenAiReplyClassifier implements ReplyClassifier
{
    public function __construct(private string $api_key, private string $model, private string $base_url, private int $timeout)
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function classify(string $text, array $context): array
    {
        $res = Http::withToken($this->api_key)->timeout($this->timeout)->acceptJson()
            ->post(rtrim($this->base_url, '/') . '/chat/completions', [
                'model'           => $this->model,
                'temperature'     => 0,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => ReplyClassifierPrompt::system()],
                    ['role' => 'user', 'content' => ReplyClassifierPrompt::user($text, $context)],
                ],
            ]);
        if (!$res->successful()) {
            throw new RuntimeException('AI classifier error (HTTP ' . $res->status() . ')');
        }
        $data = json_decode((string) data_get($res->json(), 'choices.0.message.content', ''), true);
        if (!is_array($data)) {
            throw new RuntimeException('AI classifier returned invalid JSON');
        }

        return ReplyClassifierPrompt::sanitize($data, $context);
    }
}
