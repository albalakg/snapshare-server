<?php

namespace App\Services\OpenWa;

use App\Exceptions\OpenWaApiException;
use App\Services\Helpers\LogService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenWaClient
{
    private string $trackId;

    public function __construct()
    {
        $this->trackId = (string) Str::uuid();
    }

    public function request(string $method, string $path, ?array $body = null, bool $sessionScoped = true): array
    {
        $url = $this->buildUrl($path, $sessionScoped);

        $this->log('info', 'OpenWA request', [
            'method' => strtoupper($method),
            'url'    => $url,
        ]);

        $pending = Http::withHeaders($this->headers());

        $response = match (strtoupper($method)) {
            'GET'    => $pending->get($url),
            'POST'   => $pending->post($url, $body ?? []),
            'PUT'    => $pending->put($url, $body ?? []),
            'PATCH'  => $pending->patch($url, $body ?? []),
            'DELETE' => $pending->delete($url, $body ?? []),
            default  => throw new OpenWaApiException("Unsupported HTTP method: {$method}"),
        };

        if (!$response->successful()) {
            $this->handleErrorResponse($response, $url);
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function buildUrl(string $path, bool $sessionScoped): string
    {
        $path = ltrim($path, '/');
        $base = config('openwa.api_url');

        if ($sessionScoped) {
            $sessionId = config('openwa.session_id');

            if ($path === '') {
                return "{$base}/sessions/{$sessionId}";
            }

            return "{$base}/sessions/{$sessionId}/{$path}";
        }

        return $path === '' ? $base : "{$base}/{$path}";
    }

    private function headers(): array
    {
        return [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'X-API-Key'     => config('openwa.api_key'),
            'X-Request-ID'  => 'req_' . (int) (microtime(true) * 1000),
        ];
    }

    private function handleErrorResponse(Response $response, string $url): void
    {
        $status = $response->status();
        $body = $response->body();
        $decoded = $response->json();
        $errorCode = is_array($decoded) ? ($decoded['code'] ?? $decoded['errorCode'] ?? null) : null;

        $message = match (true) {
            $status === 401 => 'Invalid or missing OPENWA_API_KEY',
            $errorCode === 'SESSION_NOT_READY' => 'WhatsApp session is not ready',
            $errorCode === 'MESSAGE_INVALID_CHAT_ID' => 'Invalid chat ID format',
            $errorCode === 'MESSAGE_NUMBER_NOT_ON_WHATSAPP' => 'Phone number is not registered on WhatsApp',
            default => "OpenWA request failed with status {$status}",
        };

        $this->log('error', $message, [
            'url'        => $url,
            'status'     => $status,
            'error_code' => $errorCode,
            'body'       => $body,
        ]);

        throw new OpenWaApiException($message, $status, $body, is_string($errorCode) ? $errorCode : null);
    }

    private function log(string $level, string $content, array $context = []): void
    {
        $logger = LogService::init();
        $context = array_merge($context, [LogService::TRACK_ID => $this->trackId]);

        if ($level === 'error') {
            $logger->error($content, $context);
        } else {
            $logger->info($content, $context);
        }
    }
}
