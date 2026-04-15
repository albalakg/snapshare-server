<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StoreController;

Route::post('order-confirmation', [StoreController::class, 'orderCallback']);

Route::group(['middleware' => 'auth:api'], function () {
    Route::post('order', [StoreController::class, 'createOrder']);
    Route::post('demo', [StoreController::class, 'createDemo']);
});