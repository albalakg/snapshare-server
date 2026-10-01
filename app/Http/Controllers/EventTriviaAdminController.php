<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTriviaGameRequest;
use App\Http\Requests\UpdateTriviaQuestionsRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Trivia\TriviaService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class EventTriviaAdminController extends Controller
{
    public function show(int $event_id)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_FETCHED,
                $service->getAdmin($event_id, Auth::user()->id)
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function update(int $event_id, UpdateTriviaGameRequest $request)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_UPDATED,
                $service->updateSettings($event_id, Auth::user()->id, $request->validated())
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function updateQuestions(int $event_id, UpdateTriviaQuestionsRequest $request)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_QUESTIONS_UPDATED,
                $service->replaceQuestions($event_id, Auth::user()->id, $request->validated()['questions'] ?? [])
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function showNow(int $event_id)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_SHOWN,
                $service->showNow($event_id, Auth::user()->id)
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function showResults(int $event_id)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_RESULTS_SHOWN,
                $service->showResults($event_id, Auth::user()->id)
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function backToPhotos(int $event_id)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_PHOTOS_RESTORED,
                $service->backToPhotos($event_id, Auth::user()->id)
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function report(int $event_id)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_REPORT_FETCHED,
                $service->report($event_id, Auth::user()->id)
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
