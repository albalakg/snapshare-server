<?php

use App\Http\Controllers\MessagingPublicController as P;
use Illuminate\Support\Facades\Route;

// Meta WhatsApp Cloud API webhook (signature-verified with WHATSAPP_APP_SECRET).
Route::get('/webhooks/whatsapp/meta', [P::class, 'verify']);
Route::post('/webhooks/whatsapp/meta', [P::class, 'webhook'])->middleware('throttle:600,1');
