<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local / testing driver. Never contacts Meta and never delivers a real message.
 * - Phones ending with config('whatsapp_messaging.mock.no_whatsapp_suffix') fail with 131026 (not on WhatsApp).
 * - Submitted templates stay "pending" until approve_after_seconds passed; a body containing "[reject]" is rejected.
 */
class MockWhatsAppDriver implements WhatsAppProviderInterface
{
    /** @var array<int, array> sent messages (per PHP process) — used by tests */
    public static array $outbox = [];

    public function name(): string
    {
        return 'mock';
    }

    public function sendTemplate(string $to, array $template, array $params, array $buttonPayloads = []): ProviderResult
    {
        return $this->record('template', $to, ['template' => $template['meta_name'] ?? null, 'params' => $params, 'payloads' => $buttonPayloads]);
    }

    public function sendText(string $to, string $body): ProviderResult
    {
        return $this->record('text', $to, ['body' => $body]);
    }

    public function sendButtons(string $to, string $body, array $buttons): ProviderResult
    {
        return $this->record('buttons', $to, ['body' => $body, 'buttons' => $buttons]);
    }

    public function submitTemplate(array $template): array
    {
        return ['id' => 'mock-tpl-' . Str::lower(Str::random(10)), 'status' => 'pending', 'reason' => null];
    }

    public function templateStatus(array $template): array
    {
        if (str_contains((string) ($template['body'] ?? ''), '[reject]')) {
            return ['status' => 'rejected', 'reason' => 'Mock review: the body was marked [reject]'];
        }

        $submitted = $template['submitted_at'] ?? null;
        $after = (int) config('whatsapp_messaging.mock.approve_after_seconds', 30);
        if ($submitted && now()->diffInSeconds($submitted, true) >= $after) {
            return ['status' => 'approved', 'reason' => null];
        }

        return ['status' => 'pending', 'reason' => null];
    }

    private function record(string $type, string $to, array $data): ProviderResult
    {
        $suffix = (string) config('whatsapp_messaging.mock.no_whatsapp_suffix', '0000');
        if ($suffix !== '' && str_ends_with($to, $suffix)) {
            return ProviderResult::fail('131026', 'Mock: recipient is not on WhatsApp');
        }

        $id = 'wamid.mock.' . Str::lower(Str::random(16));
        self::$outbox[] = ['id' => $id, 'type' => $type, 'to' => $to] + $data;
        Log::info('[whatsapp-mock] ' . $type . ' accepted', ['id' => $id, 'to_suffix' => substr($to, -3)]);

        return ProviderResult::ok($id);
    }
}
