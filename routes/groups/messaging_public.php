<?php

use App\Http\Controllers\MessagingPublicController as P;
use Illuminate\Support\Facades\Route;

// Public RSVP (prefix api/public). Personal links come from WhatsApp / SMS; the hub form has no token.
Route::get('rsvp/{token}', [P::class, 'showRsvp'])->middleware('throttle:60,1');
Route::post('rsvp/{token}', [P::class, 'submitRsvp'])->middleware('throttle:20,1');
Route::get('hubs/{slug}/rsvp', [P::class, 'hubRsvpConfig'])->middleware('throttle:60,1');
Route::post('hubs/{slug}/rsvp', [P::class, 'hubRsvp'])->middleware('throttle:10,1');
