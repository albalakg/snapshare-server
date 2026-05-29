<?php

use App\Http\Controllers\EventWhatsAppController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/whatsapp/guests/template', [EventWhatsAppController::class, 'importTemplate']);
    Route::get('{event_id}/whatsapp/guests/import-template', [EventWhatsAppController::class, 'importTemplate']);
    Route::post('{event_id}/whatsapp/guests/import', [EventWhatsAppController::class, 'importGuests']);
    Route::get('{event_id}/whatsapp/guests', [EventWhatsAppController::class, 'listGuests']);
    Route::post('{event_id}/whatsapp/guests', [EventWhatsAppController::class, 'storeGuest']);
    Route::delete('{event_id}/whatsapp/guests', [EventWhatsAppController::class, 'deleteGuests']);
    Route::put('{event_id}/whatsapp/guests/{guest_id}', [EventWhatsAppController::class, 'updateGuest']);
    Route::delete('{event_id}/whatsapp/guests/{guest_id}', [EventWhatsAppController::class, 'deleteGuest']);

    Route::get('{event_id}/whatsapp/quota', [EventWhatsAppController::class, 'quota']);
    Route::get('{event_id}/whatsapp/campaigns', [EventWhatsAppController::class, 'listCampaigns']);
    Route::post('{event_id}/whatsapp/campaigns', [EventWhatsAppController::class, 'storeCampaign']);
    Route::get('{event_id}/whatsapp/campaigns/{campaign_id}', [EventWhatsAppController::class, 'showCampaign']);
    Route::post('{event_id}/whatsapp/campaigns/{campaign_id}/cancel', [EventWhatsAppController::class, 'cancelCampaign']);
});
