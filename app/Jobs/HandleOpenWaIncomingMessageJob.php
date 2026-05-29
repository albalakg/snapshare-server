<?php

namespace App\Jobs;

use App\Services\Helpers\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class HandleOpenWaIncomingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected array $messageData,
    ) {}

    public function handle(): void
    {
        $data = $this->messageData['data'] ?? [];

        LogService::init()->info('OpenWA incoming message queued for processing', [
            'session_id' => $this->messageData['session_id'] ?? null,
            'from'       => $data['from'] ?? $data['chatId'] ?? null,
            'body'       => $data['body'] ?? $data['text'] ?? null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        LogService::init()->error($exception, [
            'session_id' => $this->messageData['session_id'] ?? null,
            'message'    => 'OpenWA incoming message job failed after all retries',
        ]);
    }
}
