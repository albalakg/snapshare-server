<?php

use App\Http\Controllers\EventSeatingController;
use Illuminate\Support\Facades\Route;

/*
 * Seating builder + smart arrange (host). Writes are gated by SeatingEntitlement
 * (feature flag + optional paid add-on paywall → 402; see SeatingEntitlement).
 */
Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/seating', [EventSeatingController::class, 'show']);
    Route::put('{event_id}/seating', [EventSeatingController::class, 'save']);

    Route::post('{event_id}/seating/constraints', [EventSeatingController::class, 'storeConstraint']);
    Route::put('{event_id}/seating/constraints/{constraint_id}', [EventSeatingController::class, 'updateConstraint'])->whereNumber('constraint_id');
    Route::delete('{event_id}/seating/constraints/{constraint_id}', [EventSeatingController::class, 'destroyConstraint'])->whereNumber('constraint_id');

    Route::get('{event_id}/seating/ai/quota', [EventSeatingController::class, 'quota']);
    Route::get('{event_id}/seating/ai/runs', [EventSeatingController::class, 'runs']);
    Route::post('{event_id}/seating/ai/runs', [EventSeatingController::class, 'run'])->middleware('throttle:20,1');
    Route::post('{event_id}/seating/ai/runs/{run_id}/apply', [EventSeatingController::class, 'apply'])->whereNumber('run_id');
    Route::post('{event_id}/seating/ai/runs/{run_id}/discard', [EventSeatingController::class, 'discard'])->whereNumber('run_id');
});
