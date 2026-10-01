<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Services\Enums\MessagesEnum;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EnsureTriviaSession
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        try {
            $eventPath = (string) $request->route('event_path');
            $sessionToken = $request->header('X-Trivia-Session');

            if (!$sessionToken || !preg_match('/^[a-zA-Z0-9\-_]{32,255}$/', $sessionToken)) {
                throw new Exception(MessagesEnum::TRIVIA_INVALID_SESSION, Response::HTTP_UNAUTHORIZED);
            }

            $event = Event::where('path', $eventPath)->first();

            if (!$event) {
                throw new Exception(MessagesEnum::EVENT_NOT_FOUND, Response::HTTP_NOT_FOUND);
            }

            $request->attributes->set('trivia_event_id', (int) $event->id);
            $request->attributes->set('trivia_session_token', $sessionToken);

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
