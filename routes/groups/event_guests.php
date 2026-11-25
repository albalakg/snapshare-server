<?php

use App\Http\Controllers\EventGuestCrmController;
use Illuminate\Support\Facades\Route;

/* Guest CRM (host). Shares event_guests with WhatsApp. Not behind the seating flag. */
Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/guests', [EventGuestCrmController::class, 'index']);
    Route::post('{event_id}/guests', [EventGuestCrmController::class, 'store']);
    Route::delete('{event_id}/guests', [EventGuestCrmController::class, 'destroyMany']);
    Route::post('{event_id}/guests/bulk-update', [EventGuestCrmController::class, 'bulkUpdate']);
    Route::post('{event_id}/guests/import/preview', [EventGuestCrmController::class, 'importPreview']);
    Route::post('{event_id}/guests/import/commit', [EventGuestCrmController::class, 'importCommit']);
    Route::get('{event_id}/guests/export', [EventGuestCrmController::class, 'export']);
    Route::put('{event_id}/guests/{guest_id}', [EventGuestCrmController::class, 'update'])->whereNumber('guest_id');
    Route::delete('{event_id}/guests/{guest_id}', [EventGuestCrmController::class, 'destroy'])->whereNumber('guest_id');

    Route::get('{event_id}/guest-groups', [EventGuestCrmController::class, 'groups']);
    Route::post('{event_id}/guest-groups', [EventGuestCrmController::class, 'storeGroup']);
    Route::put('{event_id}/guest-groups/{group_id}', [EventGuestCrmController::class, 'updateGroup'])->whereNumber('group_id');
    Route::delete('{event_id}/guest-groups/{group_id}', [EventGuestCrmController::class, 'destroyGroup'])->whereNumber('group_id');
});
