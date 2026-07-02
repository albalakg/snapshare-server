<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Services\Enums\MessagesEnum;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EnsureIcebreakerSession
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        try {
            $eventId = (int) $request->route('event_id');
            $headerEventId = $request->header('X-Event-ID');
            $sessionToken = $request->header('X-User-Session-Token');

            if (!$headerEventId || !$sessionToken) {
                throw new Exception(MessagesEnum::ICEBREAKER_INVALID_SESSION, Response::HTTP_UNAUTHORIZED);
            }

            if ((int) $headerEventId !== $eventId) {
                throw new Exception(MessagesEnum::ICEBREAKER_EVENT_ID_MISMATCH, Response::HTTP_BAD_REQUEST);
            }

            if (!preg_match('/^[a-zA-Z0-9\-_]{32,255}$/', $sessionToken)) {
                throw new Exception(MessagesEnum::ICEBREAKER_INVALID_SESSION, Response::HTTP_UNAUTHORIZED);
            }

            if (!Event::where('id', $eventId)->exists()) {
                throw new Exception(MessagesEnum::EVENT_NOT_FOUND, Response::HTTP_NOT_FOUND);
            }

            $request->attributes->set('icebreaker_event_id', $eventId);
            $request->attributes->set('icebreaker_session_token', $sessionToken);

            return $next($request);
        } catch (Exception $ex) {
            $status = $ex->getCode() ?: Response::HTTP_BAD_REQUEST;

            return response()->json([
                'message' => $ex->getMessage(),
                'status'  => false,
                'data'    => null,
            ], $status);
        }
    }
}
