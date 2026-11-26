<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp Business Cloud API (graph.facebook.com) — D4, no BSP.
 * Templates use NAMED parameters ({{first_name}}), the same syntax the SnapShare editor uses.
 * Requires WHATSAPP_ACCESS_TOKEN, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_WABA_ID (+ APP_SECRET / VERIFY_TOKEN for webhooks).
 */
class MetaCloudApiDriver implements WhatsAppProviderInterface
{
    public function name(): string
    {
        return 'meta';
    }

    public function sendTemplate(string $to, array $template, array $params, array $buttonPayloads = []): ProviderResult
    {
        $components = [];
        $headerVars = self::varsIn((string) ($template['header'] ?? ''));
        if ($headerVars) {
            $components[] = ['type' => 'header', 'parameters' => $this->namedParams($headerVars, $params)];
        }
        $bodyVars = self::varsIn((string) ($template['body'] ?? ''));
        if ($bodyVars) {
            $components[] = ['type' => 'body', 'parameters' => $this->namedParams($bodyVars, $params)];
        }
        foreach (array_values($template['buttons'] ?? []) as $i => $button) {
            if (($button['type'] ?? 'quick_reply') === 'quick_reply' && isset($buttonPayloads[$i])) {
                $components[] = [
                    'type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i,
                    'parameters' => [['type' => 'payload', 'payload' => $buttonPayloads[$i]]],
                ];
            }
        }

        return $this->message($to, [
            'type'     => 'template',
            'template' => [
                'name'       => $template['meta_name'],
                'language'   => ['code' => $template['language'] ?? 'he'],
                'components' => $components,
            ],
        ]);
    }

    public function sendText(string $to, string $body): ProviderResult
    {
        return $this->message($to, ['type' => 'text', 'text' => ['preview_url' => true, 'body' => $body]]);
    }

    public function sendButtons(string $to, string $body, array $buttons): ProviderResult
    {
        return $this->message($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type'   => 'button',
                'body'   => ['text' => $body],
                'action' => ['buttons' => array_map(fn ($b) => ['type' => 'reply', 'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)]], array_slice($buttons, 0, 3))],
            ],
        ]);
    }

    public function submitTemplate(array $template): array
    {
        $components = [];
        if (!empty($template['header'])) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $template['header']] + $this->examples('header', $template['header']);
        }
        $components[] = ['type' => 'BODY', 'text' => $template['body']] + $this->examples('body', $template['body']);
        if (!empty($template['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $template['footer']];
        }
        $buttons = [];
        foreach ($template['buttons'] ?? [] as $b) {
            $buttons[] = ($b['type'] ?? 'quick_reply') === 'url'
                ? ['type' => 'URL', 'text' => $b['text'], 'url' => $b['url'] ?? config('app.client_url')]
                : ['type' => 'QUICK_REPLY', 'text' => $b['text']];
        }
        if ($buttons) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        try {
            $res = $this->http()->post($this->graph(config('whatsapp_messaging.meta.waba_id') . '/message_templates'), [
                'name'             => $template['meta_name'],
                'language'         => $template['language'] ?? 'he',
                'category'         => strtoupper($template['category'] ?? 'marketing'),
                'parameter_format' => 'NAMED',
                'components'       => $components,
            ]);
            if (!$res->successful()) {
                return ['id' => null, 'status' => 'rejected', 'reason' => (string) ($res->json('error.error_user_msg') ?? $res->json('error.message') ?? 'Meta rejected the submission')];
            }

            return ['id' => (string) $res->json('id'), 'status' => strtolower((string) ($res->json('status') ?? 'pending')), 'reason' => null];
        } catch (Throwable $e) {
            Log::warning('[whatsapp-meta] submit failed: ' . $e->getMessage());

            return ['id' => null, 'status' => 'draft', 'reason' => 'Could not reach Meta, try again'];
        }
    }

    public function templateStatus(array $template): array
    {
        if (empty($template['meta_template_id'])) {
            return ['status' => $template['meta_status'] ?? 'draft', 'reason' => null];
        }
        try {
            $res = $this->http()->get($this->graph($template['meta_template_id']), ['fields' => 'status,rejected_reason']);
            if ($res->successful()) {
                $status = strtolower((string) $res->json('status'));

                return ['status' => in_array($status, ['approved', 'rejected', 'pending'], true) ? $status : 'pending', 'reason' => $res->json('rejected_reason')];
            }
        } catch (Throwable $e) {
            Log::warning('[whatsapp-meta] status failed: ' . $e->getMessage());
        }

        return ['status' => $template['meta_status'] ?? 'pending', 'reason' => null];
    }

    public static function varsIn(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    private function namedParams(array $vars, array $params): array
    {
        return array_map(fn ($v) => ['type' => 'text', 'parameter_name' => $v, 'text' => (string) (($params[$v] ?? '') !== '' ? $params[$v] : '-')], $vars);
    }

    private function examples(string $kind, string $text): array
    {
        $vars = self::varsIn($text);
        if (!$vars) {
            return [];
        }
        $named = array_map(fn ($v) => ['param_name' => $v, 'example' => 'דוגמה'], $vars);

        return ['example' => [$kind === 'header' ? 'header_text_named_params' : 'body_text_named_params' => $named]];
    }

    private function message(string $to, array $payload): ProviderResult
    {
        try {
            $res = $this->http()->post($this->graph(config('whatsapp_messaging.meta.phone_number_id') . '/messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
            ] + $payload);
            if ($res->successful() && $res->json('messages.0.id')) {
                return ProviderResult::ok((string) $res->json('messages.0.id'));
            }

            return ProviderResult::fail((string) ($res->json('error.code') ?? $res->status()), (string) ($res->json('error.message') ?? 'Meta error'));
        } catch (Throwable $e) {
            return ProviderResult::fail('network', $e->getMessage());
        }
    }

    private function http()
    {
        return Http::withToken((string) config('whatsapp_messaging.meta.access_token'))
            ->acceptJson()
            ->timeout((int) config('whatsapp_messaging.meta.timeout', 20));
    }

    private function graph(string $path): string
    {
        return rtrim((string) config('whatsapp_messaging.meta.graph_url'), '/') . '/' . config('whatsapp_messaging.meta.graph_version') . '/' . ltrim($path, '/');
    }
}
