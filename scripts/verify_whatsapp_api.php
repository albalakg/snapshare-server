<?php

/**
 * One-off verification script for WhatsApp API endpoints and scheduled queue flow.
 * Usage: php scripts/verify_whatsapp_api.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$eventId = 3;
$user = User::find(4);

if (!$user) {
    fwrite(STDERR, "Test user not found.\n");
    exit(1);
}

$token = $user->createToken('whatsapp-api-verify')->accessToken;
$base = rtrim(config('app.url'), '/');
$headers = [
    'Authorization' => 'Bearer ' . $token,
    'Accept'        => 'application/json',
];

$results = [];

function record(array &$results, string $name, int $status, mixed $body): void
{
    $ok = $status >= 200 && $status < 300;
    $results[] = [
        'name'   => $name,
        'status' => $status,
        'ok'     => $ok,
        'body'   => is_array($body) ? $body : ['raw' => $body],
    ];
    $flag = $ok ? 'OK' : 'FAIL';
    echo sprintf("[%s] %s -> HTTP %d\n", $flag, $name, $status);
}

// Guests
$r = Http::withHeaders($headers)->get("{$base}/api/events/{$eventId}/whatsapp/guests");
record($results, 'GET guests', $r->status(), $r->json() ?? $r->body());

$r = Http::withHeaders($headers)->get("{$base}/api/events/{$eventId}/whatsapp/guests/template");
record($results, 'GET import template', $r->status(), $r->successful() ? ['content_type' => $r->header('Content-Type')] : ($r->json() ?? $r->body()));

$r = Http::withHeaders($headers)->get("{$base}/api/events/{$eventId}/whatsapp/quota");
record($results, 'GET quota', $r->status(), $r->json() ?? $r->body());

$r = Http::withHeaders($headers)->get("{$base}/api/events/{$eventId}/whatsapp/campaigns");
record($results, 'GET campaigns', $r->status(), $r->json() ?? $r->body());

$campaignId = $r->json('data.campaigns.0.id') ?? $r->json('data.campaigns.0.id');

if ($campaignId) {
    $r = Http::withHeaders($headers)->get("{$base}/api/events/{$eventId}/whatsapp/campaigns/{$campaignId}");
    record($results, 'GET campaign detail', $r->status(), $r->json() ?? $r->body());
}

// Create temp guest for CRUD test
$testPhone = '972528458600';
$r = Http::withHeaders($headers)->post("{$base}/api/events/{$eventId}/whatsapp/guests", [
    'full_name' => 'API Verify Guest',
    'phone'     => $testPhone,
]);
record($results, 'POST guest', $r->status(), $r->json() ?? $r->body());
$guestId = $r->json('data.id');

if ($guestId) {
    $r = Http::withHeaders($headers)->put("{$base}/api/events/{$eventId}/whatsapp/guests/{$guestId}", [
        'full_name' => 'API Verify Guest Updated',
    ]);
    record($results, 'PUT guest', $r->status(), $r->json() ?? $r->body());

    $r = Http::withHeaders($headers)->delete("{$base}/api/events/{$eventId}/whatsapp/guests/{$guestId}");
    record($results, 'DELETE guest', $r->status(), $r->json() ?? $r->body());
}

// Scheduled campaign test (1 minute from now, single guest if available)
$guestList = Http::withHeaders($headers)->get("{$base}/api/events/{$eventId}/whatsapp/guests");
$firstGuestId = $guestList->json('data.guests.0.id');
$scheduledAt = now()->addMinute()->format('Y-m-d H:i:s');

if ($firstGuestId) {
    $r = Http::withHeaders($headers)->post("{$base}/api/events/{$eventId}/whatsapp/campaigns", [
        'message'      => 'Scheduled API verify test - ' . now()->toDateTimeString(),
        'send_mode'    => 'scheduled',
        'scheduled_at' => $scheduledAt,
        'guest_ids'    => [$firstGuestId],
    ]);
    record($results, 'POST scheduled campaign', $r->status(), $r->json() ?? $r->body());
    $scheduledCampaignId = $r->json('data.campaign.id');

    if ($scheduledCampaignId) {
        $r = Http::withHeaders($headers)->post("{$base}/api/events/{$eventId}/whatsapp/campaigns/{$scheduledCampaignId}/cancel");
        record($results, 'POST cancel scheduled campaign', $r->status(), $r->json() ?? $r->body());
    }
}

// Webhook signature test
$webhookSecret = config('openwa.webhook_secret');
$payload = json_encode(['event' => 'test.ping', 'idempotencyKey' => 'verify-' . uniqid()]);
$signature = 'sha256=' . hash_hmac('sha256', $payload, $webhookSecret);
$r = Http::withHeaders([
    'Content-Type'           => 'application/json',
    'X-OpenWA-Signature'     => $signature,
    'X-OpenWA-Idempotency-Key' => json_decode($payload, true)['idempotencyKey'],
])->withBody($payload, 'application/json')->post("{$base}/webhooks/openwa");
record($results, 'POST openwa webhook', $r->status(), $r->json() ?? $r->body());

$failed = array_filter($results, fn ($row) => !$row['ok']);
echo "\nSummary: " . count($results) - count($failed) . '/' . count($results) . " passed\n";

if ($failed) {
    echo "\nFailures:\n";
    foreach ($failed as $row) {
        echo "- {$row['name']}: " . json_encode($row['body'], JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(1);
}

exit(0);
