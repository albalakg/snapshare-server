<?php

use App\Http\Controllers\EventIcebreakerConfigController;
use App\Http\Controllers\EventIcebreakerController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'icebreaker.session'], function () {
    Route::get('{event_id}/icebreaker/config', [EventIcebreakerController::class, 'getConfig']);
    Route::post('{event_id}/icebreaker/profiles', [EventIcebreakerController::class, 'upsertProfile']);
    Route::get('{event_id}/icebreaker/discover', [EventIcebreakerController::class, 'discover']);
    Route::post('{event_id}/icebreaker/interact', [EventIcebreakerController::class, 'interact']);
    Route::delete('{event_id}/icebreaker/profiles/me', [EventIcebreakerController::class, 'deactivateProfile']);
});

Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/icebreaker/admin/config', [EventIcebreakerConfigController::class, 'getAdminConfig']);
    Route::put('{event_id}/icebreaker/admin/config', [EventIcebreakerConfigController::class, 'updateConfig']);
});
