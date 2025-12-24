<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventController;

Route::get('/debug/php-upload-limits', function () {
    $keys = [
        'upload_max_filesize',
        'post_max_size',
        'memory_limit',
        'max_execution_time',
        'max_input_time',
        'file_uploads',
        'max_file_uploads',
        'default_socket_timeout',
    ];

    $data = [];
    foreach ($keys as $k) {
        $data[$k] = ini_get($k);
    }

    return response()->json([
        'php_sapi' => php_sapi_name(),
        'php_version' => PHP_VERSION,
        'loaded_ini' => php_ini_loaded_file(),        // which php.ini is used
        'scanned_ini' => php_ini_scanned_files(),     // extra .ini files loaded
        'ini' => $data,
    ]);
});


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
Route::get('{event_id}/gallery-guests-assets', [EventController::class, 'galleryGuestAssets']);
Route::post('{event_id}/upload', [EventController::class, 'uploadFile']);
Route::post('{event_id}/auth/upload', [EventController::class, 'authenticatedUploadFile'])->middleware("auth:api");
