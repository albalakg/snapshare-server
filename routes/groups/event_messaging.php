<?php

use App\Http\Controllers\EventMessagingController as C;
use Illuminate\Support\Facades\Route;

/*
 * WhatsApp guest messaging (host). Gated by config('whatsapp_messaging.enabled') inside the services.
 * Legacy OpenWA campaigns stay under /{event_id}/whatsapp/*.
 */
Route::group(['middleware' => 'auth:api', 'prefix' => '{event_id}/messaging'], function () {
    Route::get('overview', [C::class, 'overview']);
    Route::get('settings', [C::class, 'settings']);
    Route::put('settings', [C::class, 'updateSettings']);

    Route::get('templates', [C::class, 'templates']);
    Route::post('templates', [C::class, 'storeTemplate'])->middleware('throttle:20,1');
    Route::put('templates/{template_id}', [C::class, 'resubmitTemplate'])->whereNumber('template_id')->middleware('throttle:20,1');

    Route::get('queue', [C::class, 'queue']);
    Route::post('sends/preview', [C::class, 'preview'])->middleware('throttle:120,1');
    Route::post('sends', [C::class, 'storeSend'])->middleware('throttle:30,1');
    Route::get('sends/{send_id}', [C::class, 'showSend'])->whereNumber('send_id');
    Route::patch('sends/{send_id}', [C::class, 'updateSend'])->whereNumber('send_id')->middleware('throttle:60,1');
    Route::get('sends/{send_id}/recipients', [C::class, 'recipients'])->whereNumber('send_id');
    Route::get('sends/{send_id}/audit', [C::class, 'audit'])->whereNumber('send_id');
    Route::get('guests/{guest_id}/messages', [C::class, 'guestPending'])->whereNumber('guest_id');
    Route::post('test-send', [C::class, 'testSend'])->middleware('throttle:10,1');

    Route::get('automations', [C::class, 'automations']);
    Route::put('automations/{automation_id}', [C::class, 'updateAutomation'])->whereNumber('automation_id');

    Route::get('analytics', [C::class, 'analytics']);
    Route::get('inbox', [C::class, 'inbox']);
    Route::get('inbox/{guest_id}', [C::class, 'thread'])->whereNumber('guest_id');
    Route::post('inbox/{guest_id}/reply', [C::class, 'reply'])->whereNumber('guest_id')->middleware('throttle:60,1');
    Route::post('inbox/messages/{message_id}/suggestion', [C::class, 'applySuggestion'])->whereNumber('message_id');

    // Mock-driver dev helpers (404 unless WHATSAPP driver is mock and dev endpoints are on; never production)
    Route::post('dev/inbound', [C::class, 'devInbound']);
    Route::post('dev/templates/{template_id}', [C::class, 'devTemplate'])->whereNumber('template_id');
    Route::post('dev/sends/{send_id}/delivery', [C::class, 'devDelivery'])->whereNumber('send_id');
    Route::post('dev/entitlement', [C::class, 'devEntitlement']);
});
