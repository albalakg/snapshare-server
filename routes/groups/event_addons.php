<?php

use App\Http\Controllers\EventAddonController as C;
use Illuminate\Support\Facades\Route;

// Per-event paid add-ons (prefix api/events). Gated by config('addons.enabled') inside the service.
Route::group(['middleware' => 'auth:api', 'prefix' => '{event_id}/addons'], function () {
    Route::get('/', [C::class, 'catalog']);
    Route::post('checkout', [C::class, 'checkout'])->middleware('throttle:addon-checkout');
    Route::get('purchases/{uid}', [C::class, 'show'])->where('uid', 'ad_[a-z0-9]{24}');
    // Mock driver only (404 otherwise; never production).
    Route::post('purchases/{uid}/dev-complete', [C::class, 'devComplete'])->where('uid', 'ad_[a-z0-9]{24}');
});
