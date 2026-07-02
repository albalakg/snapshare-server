<?php

use App\Http\Controllers\PublicEventHubController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:120,1')->group(function () {
    Route::get('hubs/{slug}', [PublicEventHubController::class, 'show']);
});
