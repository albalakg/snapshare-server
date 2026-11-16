<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized',
                'status' => false,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
