<?php

namespace App\Jobs;

use App\Services\Helpers\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessOpenWaWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected array $webhookData,
    ) {}

    public function handle(): void
    {
        $payload = $this->webhookData['payload'] ?? [];
        $event = $payload['event'] ?? 'unknown';

        LogService::init()->info('Processing OpenWA webhook event', [
            'event'       => $event,
            'delivery_id' => $this->webhookData['delivery_id'] ?? null,
            'session_id'  => $payload['sessionId'] ?? null,
        ]);

        match ($event) {
            'message.received' => $this->handleMessageReceived($payload),
            'message.sent'     => $this->handleMessageSent($payload),
            'message.ack'      => $this->handleMessageAck($payload),
            'session.status'   => $this->handleSessionStatus($payload),
            default            => LogService::init()->info('Unhandled OpenWA webhook event', ['event' => $event]),
        };
    }

    public function failed(Throwable $exception): void
    {
        LogService::init()->error($exception, [
            'delivery_id' => $this->webhookData['delivery_id'] ?? null,
            'event'       => $this->webhookData['payload']['event'] ?? null,
            'message'     => 'OpenWA webhook job failed after all retries',
        ]);
    }

    private function handleMessageReceived(array $payload): void
    {
        $data = $payload['data'] ?? [];

        LogService::init()->info('OpenWA message received', [
            'from'       => $data['from'] ?? $data['chatId'] ?? null,
            'message_id' => $data['messageId'] ?? $data['id'] ?? null,
        ]);

        HandleOpenWaIncomingMessageJob::dispatch([
            'event'      => $payload['event'],
            'session_id' => $payload['sessionId'] ?? null,
            'data'       => $data,
            'timestamp'  => $payload['timestamp'] ?? null,
        ]);
    }

    private function handleMessageSent(array $payload): void
    {
        $data = $payload['data'] ?? [];

        LogService::init()->info('OpenWA message sent', [
            'chat_id'    => $data['chatId'] ?? null,
            'message_id' => $data['messageId'] ?? null,
        ]);
    }

    private function handleMessageAck(array $payload): void
    {
        $data = $payload['data'] ?? [];

        LogService::init()->info('OpenWA message ack', [
            'message_id' => $data['messageId'] ?? null,
            'ack'        => $data['ack'] ?? null,
        ]);
    }

    private function handleSessionStatus(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $status = $data['status'] ?? null;

        if ($status !== null) {
            Cache::put('openwa:session:status', $status, now()->addDay());
        }

        LogService::init()->info('OpenWA session status changed', [
            'status' => $status,
            'phone'  => $data['phone'] ?? null,
        ]);
    }
}
