<?php

namespace App\Services\Seating\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI chat-completions (JSON mode). Used only when OPENAI_API_KEY is configured.
 */
class OpenAiSeatingProvider implements SeatingAiProvider
{
    public function __construct(private string $api_key, private string $model, private string $base_url, private int $timeout)
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function propose(array $input): array
    {
        $response = Http::withToken($this->api_key)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post(rtrim($this->base_url, '/') . '/chat/completions', [
                'model'           => $this->model,
                'temperature'     => 0,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => SeatingAiPrompt::system()],
                    ['role' => 'user', 'content' => SeatingAiPrompt::user($input)],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('AI provider error (HTTP ' . $response->status() . ')');
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['assignments']) || !is_array($data['assignments'])) {
            throw new RuntimeException('AI provider returned an invalid proposal');
        }

        return [
            'assignments' => $data['assignments'],
            'unplaced'    => is_array($data['unplaced'] ?? null) ? $data['unplaced'] : [],
            'tokens_in'   => (int) data_get($response->json(), 'usage.prompt_tokens', 0),
            'tokens_out'  => (int) data_get($response->json(), 'usage.completion_tokens', 0),
            'model'       => (string) data_get($response->json(), 'model', $this->model),
        ];
    }
}
