<?php

use App\Http\Controllers\DiscountCodeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/', [DiscountCodeController::class, 'index']);
    Route::post('/', [DiscountCodeController::class, 'store']);
    Route::patch('{id}', [DiscountCodeController::class, 'update']);
});
