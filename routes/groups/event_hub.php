<?php

use App\Http\Controllers\EventHubController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/hub', [EventHubController::class, 'show']);
    Route::put('{event_id}/hub', [EventHubController::class, 'update']);
    Route::patch('{event_id}/hub/publish', [EventHubController::class, 'publish']);
    Route::get('{event_id}/hub/slug-check', [EventHubController::class, 'checkSlug']);
    Route::get('{event_id}/hub/assets', [EventHubController::class, 'assets']);
});
