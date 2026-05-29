<?php

namespace App\Console\Commands;

use App\Services\OpenWa\OpenWaService;
use Illuminate\Console\Command;

class OpenWaStatusCommand extends Command
{
    protected $signature = 'openwa:status
                            {--register-webhook : Register the webhook with OpenWA}
                            {--start : Start the session and poll until ready}';

    protected $description = 'Check OpenWA WhatsApp session status';

    public function handle(OpenWaService $openWaService): int
    {
        if (empty(config('openwa.api_url')) || empty(config('openwa.session_id'))) {
            $this->error('OPENWA_API_URL and OPENWA_SESSION_ID must be set in .env');

            return self::FAILURE;
        }

        try {
            if ($this->option('start')) {
                $this->info('Starting OpenWA session...');
                $openWaService->startSession();
                $this->pollUntilReady($openWaService);
            }

            if ($this->option('register-webhook')) {
                $this->info('Registering webhook...');
                $result = $openWaService->registerWebhook();
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            }

            $session = $openWaService->getSessionStatus();
            $connected = $openWaService->isConnected();

            $this->newLine();
            $this->info('OpenWA Session Status');
            $this->table(
                ['Key', 'Value'],
                [
                    ['Session ID', config('openwa.session_id')],
                    ['Status', $session['status'] ?? 'unknown'],
                    ['Phone', $session['phone'] ?? '-'],
                    ['Connected', $connected ? 'yes' : 'no'],
                ]
            );

            if (!$connected) {
                $this->warn('WhatsApp is not connected. Scan QR at ' . config('openwa.dashboard_url'));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('OpenWA request failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    private function pollUntilReady(OpenWaService $openWaService, int $maxAttempts = 30, int $sleepSeconds = 2): void
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($openWaService->isConnected()) {
                $this->info("Session is ready (attempt {$attempt}).");

                return;
            }

            $this->line("Waiting for QR scan... ({$attempt}/{$maxAttempts})");
            sleep($sleepSeconds);
        }

        $this->warn('Session did not become ready. Open ' . config('openwa.dashboard_url') . ' to scan the QR code.');
    }
}
