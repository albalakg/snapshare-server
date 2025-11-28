<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventController;

// TODO: for some reason it works only when added in the api.php file as well, need to to check it
Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/assets', [EventController::class, 'assets']);
    Route::get('{event_id}/gallery-assets', [EventController::class, 'galleryAssets']);
    Route::get('{event_id}/assets/download/status', [EventController::class, 'getDownloadStatus']);
    Route::post('{event_id}/assets/download', [EventController::class, 'downloadAssets']);
    Route::post('{event_id}/assets/delete', [EventController::class, 'deleteAssets']);
    Route::post('{event_id}/assets/hide', [EventController::class, 'hideAssets']);
    Route::post('{event_id}/update', [EventController::class, 'update']);
    Route::post('{event_id}/ready', [EventController::class, 'ready']);
    Route::post('{event_id}/pending', [EventController::class, 'pending']);
    
});

Route::get('{event_path}/base-info', [EventController::class, 'getBaseInfo']);
Route::get('{event_path}/base-assets', [EventController::class, 'getBaseGallery']);
Route::post('{event_id}/upload', [EventController::class, 'uploadFile']);
Route::post('{event_id}/auth/upload', [EventController::class, 'authenticatedUploadFile'])->middleware("auth:api");
