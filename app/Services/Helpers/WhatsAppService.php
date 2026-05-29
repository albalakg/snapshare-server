<?php

namespace App\Services\Helpers;

use App\Services\OpenWa\OpenWaService;
use Exception;
use Illuminate\Support\Str;

class WhatsAppService
{
    private string $track_id;

    private bool $isMock = false;

    public function __construct(
        private OpenWaService $openWaService,
    ) {
        $this->track_id = (string) Str::uuid();
    }

    public function mock(): self
    {
        $this->isMock = true;
        $this->info('Mock triggered');

        return $this;
    }

    public function sendText(string $phone, string $message): bool
    {
        try {
            $chat_id = $this->formatChatId($phone);
            $this->info('Sending WhatsApp message', ['chat_id' => $chat_id]);

            if (!$this->isActive() || $this->isMock) {
                $this->info('WhatsApp service is not active');
                return true;
            }

            $this->openWaService->sendText($phone, $message);

            $this->info('WhatsApp message sent successfully', ['chat_id' => $chat_id]);

            return true;
        } catch (Exception $ex) {
            $this->error($ex->__toString(), ['phone' => $phone]);

            return false;
        }
    }

    public function formatChatId(string $phone): string
    {
        return $this->openWaService->formatChatId($phone);
    }

    private function isActive(): bool
    {
        return Str::lower(trim((string) config('openwa.status'))) === 'active';
    }

    private function info(string $content, array $context = []): void
    {
        LogService::init()->info($content, array_merge($context, [LogService::TRACK_ID => $this->track_id]));
    }

    private function error(string $content, array $context = []): void
    {
        LogService::init()->error($content, array_merge($context, [LogService::TRACK_ID => $this->track_id]));
    }
}
