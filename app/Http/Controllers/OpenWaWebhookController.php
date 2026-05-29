<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOpenWaWebhookJob;
use App\Services\Helpers\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class OpenWaWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->verifySignature($request, $payload, $rawBody)) {
            LogService::init()->warning('OpenWA webhook signature verification failed', [
                'delivery_id' => $request->header('X-OpenWA-Delivery-Id'),
            ]);

            return response()->json(['ok' => false, 'error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        $idempotencyKey = $payload['idempotencyKey']
            ?? $request->header('X-OpenWA-Idempotency-Key');

        if ($idempotencyKey && $this->isDuplicate($idempotencyKey)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        if ($idempotencyKey) {
            $this->markProcessed($idempotencyKey);
        }

        try {
            ProcessOpenWaWebhookJob::dispatch([
                'payload'     => $payload,
                'delivery_id' => $request->header('X-OpenWA-Delivery-Id'),
                'retry_count' => $request->header('X-OpenWA-Retry-Count'),
            ]);
        } catch (\Throwable $exception) {
            LogService::init()->error($exception, [
                'event'       => $payload['event'] ?? null,
                'delivery_id' => $request->header('X-OpenWA-Delivery-Id'),
            ]);

            return response()->json(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['ok' => true]);
    }

    private function verifySignature(Request $request, array $payload, string $rawBody): bool
    {
        $secret = config('openwa.webhook_secret');

        if (empty($secret)) {
            return true;
        }

        $signature = $payload['signature'] ?? $request->header('X-OpenWA-Signature');

        if (!is_string($signature) || $signature === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    private function isDuplicate(string $idempotencyKey): bool
    {
        return Cache::has($this->idempotencyCacheKey($idempotencyKey));
    }

    private function markProcessed(string $idempotencyKey): void
    {
        Cache::put(
            $this->idempotencyCacheKey($idempotencyKey),
            true,
            config('openwa.idempotency_ttl')
        );
    }

    private function idempotencyCacheKey(string $idempotencyKey): string
    {
        return 'openwa:idempotency:' . $idempotencyKey;
    }
}
