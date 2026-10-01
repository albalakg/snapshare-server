<?php

use App\Http\Controllers\EventTriviaAdminController;
use App\Http\Controllers\EventTriviaController;
use Illuminate\Support\Facades\Route;

Route::get('{event_path}/trivia/screen', [EventTriviaController::class, 'screen']);
Route::post('{event_path}/trivia/join', [EventTriviaController::class, 'join'])->middleware('throttle:30,1');

Route::group(['middleware' => 'trivia.session'], function () {
    Route::get('{event_path}/trivia/me', [EventTriviaController::class, 'me']);
    Route::get('{event_path}/trivia/question', [EventTriviaController::class, 'question']);
    Route::post('{event_path}/trivia/answer', [EventTriviaController::class, 'answer']);
});

Route::group(['middleware' => 'auth:api'], function () {
    Route::get('{event_id}/trivia/admin', [EventTriviaAdminController::class, 'show']);
    Route::put('{event_id}/trivia/admin', [EventTriviaAdminController::class, 'update']);
    Route::put('{event_id}/trivia/admin/questions', [EventTriviaAdminController::class, 'updateQuestions']);
    Route::post('{event_id}/trivia/admin/show', [EventTriviaAdminController::class, 'showNow']);
    Route::post('{event_id}/trivia/admin/results', [EventTriviaAdminController::class, 'showResults']);
    Route::post('{event_id}/trivia/admin/photos', [EventTriviaAdminController::class, 'backToPhotos']);
    Route::get('{event_id}/trivia/admin/report', [EventTriviaAdminController::class, 'report']);
});
