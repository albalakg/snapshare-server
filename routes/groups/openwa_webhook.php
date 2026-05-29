<?php

use App\Http\Controllers\OpenWaWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/openwa', [OpenWaWebhookController::class, 'handle']);
