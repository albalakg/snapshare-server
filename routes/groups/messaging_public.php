<?php

use App\Http\Controllers\MessagingPublicController as P;
use Illuminate\Support\Facades\Route;

// Public RSVP (prefix api/public). Personal links come from WhatsApp / SMS; the hub form has no token.
Route::get('rsvp/{token}', [P::class, 'showRsvp'])->middleware('throttle:rsvp-read');
Route::post('rsvp/{token}', [P::class, 'submitRsvp'])->middleware('throttle:rsvp-submit');
Route::get('hubs/{slug}/rsvp', [P::class, 'hubRsvpConfig'])->middleware('throttle:rsvp-read');
Route::post('hubs/{slug}/rsvp', [P::class, 'hubRsvp'])->middleware('throttle:rsvp-hub-submit');

// Public "your table" page (seating). Own limiter bucket.
Route::get('table/{token}', [\App\Http\Controllers\SeatingPublicController::class, 'table'])->middleware('throttle:table-read');
