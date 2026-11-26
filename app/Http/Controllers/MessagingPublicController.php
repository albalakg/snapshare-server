<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeatingResponses;
use App\Services\Messaging\InboundService;
use App\Services\Messaging\MessagingContext;
use App\Services\Messaging\RsvpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Public RSVP (personal token + hub section) and the Meta webhook. */
class MessagingPublicController extends Controller
{
    use SeatingResponses;

    private function rsvpRules(Request $request): array
    {
        return $request->validate([
            'status' => 'required|string|in:yes,no,maybe', 'party_size' => 'sometimes|integer|min:1|max:30',
            'meal' => 'sometimes|nullable|string|max:60', 'allergies' => 'sometimes|nullable|string|max:200',
            'shuttle' => 'sometimes|boolean', 'note' => 'sometimes|nullable|string|max:500',
        ]);
    }

    public function showRsvp(string $token)
    {
        return $this->respond('RSVP fetched', fn () => (new RsvpService())->publicShow($token));
    }

    public function submitRsvp(string $token, Request $request)
    {
        $data = $this->rsvpRules($request);

        return $this->respond('RSVP saved', fn () => (new RsvpService())->publicSubmit($token, $data));
    }

    public function hubRsvpConfig(string $slug)
    {
        return $this->respond('RSVP config fetched', fn () => (new RsvpService())->hubConfig($slug));
    }

    public function hubRsvp(string $slug, Request $request)
    {
        $data = $this->rsvpRules($request) + $request->validate(['full_name' => 'required|string|max:120', 'phone' => 'required|string|max:32']);

        return $this->respond('RSVP saved', fn () => (new RsvpService())->hubSubmit($slug, $data));
    }

    /** GET verification handshake (hub.mode=subscribe). */
    public function verify(Request $request)
    {
        $token = (string) config('whatsapp_messaging.meta.verify_token');
        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function webhook(Request $request)
    {
        if (!MessagingContext::enabled()) {
            return response()->json(['ok' => true]);
        }
        $secret = (string) config('whatsapp_messaging.meta.app_secret');
        $sig = (string) $request->header('X-Hub-Signature-256', '');
        if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $sig)) {
            Log::warning('[whatsapp-webhook] bad signature');

            return response()->json(['ok' => false], 401);
        }
        try {
            $stats = (new InboundService())->handleMetaPayload((array) $request->json()->all());
        } catch (\Throwable $e) {
            Log::error('[whatsapp-webhook] ' . $e->getMessage());
            $stats = ['error' => true];
        }

        // Always 200 so Meta does not retry storms; errors are logged.
        return response()->json(['ok' => true, 'stats' => $stats]);
    }
}
