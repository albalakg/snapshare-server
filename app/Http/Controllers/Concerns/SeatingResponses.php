<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\SeatingHttpException;
use Closure;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Guest CRM / seating controllers: domain errors keep their status and a safe message
 * (they never contain internals); anything else falls back to the base errorResponse.
 */
trait SeatingResponses
{
    protected function respond(string $message, Closure $fn, int $status = 200): JsonResponse
    {
        try {
            return $this->successResponse($message, $fn(), $status);
        } catch (SeatingHttpException $ex) {
            return response()->json([
                'message' => $ex->getMessage(),
                'status'  => false,
                'data'    => $ex->payload() ?: null,
            ], $ex->status());
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
}
