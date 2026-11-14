<?php

namespace App\Http\Controllers;

use App\Http\Requests\FromTriviaTemplateRequest;
use App\Http\Requests\PatchTriviaQuestionRequest;
use App\Http\Requests\ReorderTriviaQuestionsRequest;
use App\Http\Requests\SetTriviaPhaseRequest;
use App\Http\Requests\StoreTriviaQuestionRequest;
use App\Http\Requests\UpdateTriviaGameRequest;
use App\Http\Requests\UpdateTriviaQuestionsRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Trivia\TriviaIncompleteQuestionsException;
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

    public function patchQuestion(int $event_id, int $question_id, PatchTriviaQuestionRequest $request)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_QUESTION_SAVED,
                $service->patchQuestion($event_id, Auth::user()->id, $question_id, $request->validated())
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function storeQuestion(int $event_id, StoreTriviaQuestionRequest $request)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_QUESTION_CREATED,
                $service->appendQuestion($event_id, Auth::user()->id, $request->validated())
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroyQuestion(int $event_id, int $question_id)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_QUESTION_DELETED,
                $service->deleteQuestion($event_id, Auth::user()->id, $question_id)
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function reorderQuestions(int $event_id, ReorderTriviaQuestionsRequest $request)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(
                MessagesEnum::TRIVIA_QUESTIONS_REORDERED,
                $service->reorderQuestions($event_id, Auth::user()->id, $request->validated()['ids'] ?? [])
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function setPhase(int $event_id, SetTriviaPhaseRequest $request)
    {
        try {
            $service = new TriviaService();
            $data = $request->validated();

            return $this->successResponse(
                MessagesEnum::TRIVIA_PHASE_UPDATED,
                $service->setPhase(
                    $event_id,
                    Auth::user()->id,
                    $data['phase'],
                    isset($data['question_id']) ? (int) $data['question_id'] : null
                )
            );
        } catch (TriviaIncompleteQuestionsException $ex) {
            return $this->incompleteQuestionsResponse($ex);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function fromTemplate(int $event_id, FromTriviaTemplateRequest $request)
    {
        try {
            $service = new TriviaService();
            $data = $request->validated();

            return $this->successResponse(
                MessagesEnum::TRIVIA_TEMPLATE_APPLIED,
                $service->applyTemplate(
                    $event_id,
                    Auth::user()->id,
                    $data['template_id'],
                    (bool) ($data['replace'] ?? false)
                )
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function templates(int $event_id)
    {
        try {
            $service = new TriviaService();
            // Access check via empty getAdmin-style call would require a game; use EventService through getAdmin path.
            $service->getAdmin($event_id, Auth::user()->id);

            return $this->successResponse(
                MessagesEnum::TRIVIA_FETCHED,
                ['templates' => $service->templates()]
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
        } catch (TriviaIncompleteQuestionsException $ex) {
            return $this->incompleteQuestionsResponse($ex);
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

    private function incompleteQuestionsResponse(TriviaIncompleteQuestionsException $ex)
    {
        return response()->json([
            'message' => $ex->getMessage(),
            'status' => false,
            'data' => ['errors' => $ex->errors],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
