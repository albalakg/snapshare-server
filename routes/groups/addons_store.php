<?php

use App\Http\Controllers\EventAddonController as C;
use Illuminate\Support\Facades\Route;

// prefix api/store. Plan orders stay on store.php (order-confirmation); add-ons have their own callback.
Route::post('addon-confirmation', [C::class, 'callback'])->middleware('throttle:addon-callback');
Route::get('addons/{uid}', [C::class, 'showByUid'])->middleware('auth:api')->where('uid', 'ad_[a-z0-9]{24}');
