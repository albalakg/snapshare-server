<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Dev-only email preview (t141u) — 404 unless EMAILS_PREVIEW_ENABLED, never in production. Sample data, never sends.
Route::get('/dev/emails', [\App\Http\Controllers\Dev\EmailPreviewController::class, 'index']);
Route::get('/dev/emails/{key}', [\App\Http\Controllers\Dev\EmailPreviewController::class, 'show'])->where('key', '[a-z0-9_]+');
