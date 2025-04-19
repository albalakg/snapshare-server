<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

// TODO: for some reason it works only when added in the api.php file as well, need to to check it
Route::get('profile', [UserController::class, 'profile']);
Route::post('profile', [UserController::class, 'update']);
Route::post('password', [UserController::class, 'updatePassword']);
Route::post('delete', [UserController::class, 'delete']);
Route::post('logout', [UserController::class, 'logout']);