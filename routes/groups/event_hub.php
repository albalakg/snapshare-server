<?php

use App\Http\Controllers\EventHubController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/hub', [EventHubController::class, 'show']);
    Route::put('{event_id}/hub', [EventHubController::class, 'update']);
});
