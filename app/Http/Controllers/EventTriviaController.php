<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinTriviaRequest;
use App\Http\Requests\SubmitTriviaAnswerRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Trivia\TriviaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventTriviaController extends Controller
{
    public function screen(string $event_path)
    {
        try {
            $service = new TriviaService();

            return $this->successResponse(MessagesEnum::TRIVIA_FETCHED, $service->screenByPath($event_path));
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function join(string $event_path, JoinTriviaRequest $request)
    {
        try {
            $service = new TriviaService();
            $response = $service->join($event_path, $request->validated()['nickname'], $request->file('image'));

            return $this->successResponse(MessagesEnum::TRIVIA_JOINED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function question(Request $request)
    {
        try {
            $service = new TriviaService();
            $response = $service->nextQuestion(
                (int) $request->attributes->get('trivia_event_id'),
                (string) $request->attributes->get('trivia_session_token')
            );

            return $this->successResponse(MessagesEnum::TRIVIA_QUESTION_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function answer(SubmitTriviaAnswerRequest $request)
    {
        try {
            $service = new TriviaService();
            $response = $service->submitAnswer(
                (int) $request->attributes->get('trivia_event_id'),
                (string) $request->attributes->get('trivia_session_token'),
                (int) $request->validated()['question_id'],
                $request->validated()['option_ids'] ?? []
            );

            return $this->successResponse(MessagesEnum::TRIVIA_ANSWER_RECORDED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function me(Request $request)
    {
        try {
            $service = new TriviaService();
            $response = $service->me(
                (int) $request->attributes->get('trivia_event_id'),
                (string) $request->attributes->get('trivia_session_token')
            );

            return $this->successResponse(MessagesEnum::TRIVIA_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex, null, $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
