<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ContactController;

Route::get('user/profile', [UserController::class, 'profile']);
Route::post('contact', [ContactController::class, 'create']);