<?php

namespace App\Http\Controllers;

use App\Services\Enums\MessagesEnum;
use App\Services\Trivia\TriviaModerationService;
use App\Services\Trivia\TriviaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Live trivia moderation for the host, plus guest reactions and the text hint.
 */
class EventTriviaModerationController extends Controller
{
    public function live(int $event_id)
    {
        return $this->run(MessagesEnum::TRIVIA_LIVE_FETCHED, fn (TriviaModerationService $service) => $service->live(
            $event_id,
            Auth::user()->id
        ));
    }

    public function kick(int $event_id, int $player_id, Request $request)
    {
        $data = $request->validate(['block' => 'nullable|boolean']);

        return $this->run(MessagesEnum::TRIVIA_PLAYER_UPDATED, fn (TriviaModerationService $service) => $service->kickPlayer(
            $event_id,
            Auth::user()->id,
            $player_id,
            (bool) ($data['block'] ?? false)
        ));
    }

    public function restore(int $event_id, int $player_id)
    {
        return $this->run(MessagesEnum::TRIVIA_PLAYER_UPDATED, fn (TriviaModerationService $service) => $service->restorePlayer(
            $event_id,
            Auth::user()->id,
            $player_id
        ));
    }

    public function rename(int $event_id, int $player_id, Request $request)
    {
        $data = $request->validate(['nickname' => 'required|string|min:2|max:40']);

        return $this->run(MessagesEnum::TRIVIA_PLAYER_UPDATED, fn (TriviaModerationService $service) => $service->renamePlayer(
            $event_id,
            Auth::user()->id,
            $player_id,
            $data['nickname']
        ));
    }

    public function hideAnswers(int $event_id, Request $request)
    {
        $data = $request->validate([
            'answer_ids' => 'required|array|min:1|max:500',
            'answer_ids.*' => 'integer',
            'hidden' => 'required|boolean',
        ]);

        return $this->run(MessagesEnum::TRIVIA_ANSWERS_MODERATED, fn (TriviaModerationService $service) => $service->hideAnswers(
            $event_id,
            Auth::user()->id,
            $data['answer_ids'],
            (bool) $data['hidden']
        ));
    }

    public function approveAnswers(int $event_id, Request $request)
    {
        $data = $request->validate([
            'answer_ids' => 'required|array|min:1|max:500',
            'answer_ids.*' => 'integer',
            'approved' => 'required|boolean',
        ]);

        return $this->run(MessagesEnum::TRIVIA_ANSWERS_MODERATED, fn (TriviaModerationService $service) => $service->approveAnswers(
            $event_id,
            Auth::user()->id,
            $data['answer_ids'],
            (bool) $data['approved']
        ));
    }

    public function questionApproval(int $event_id, int $question_id, Request $request)
    {
        $data = $request->validate(['requires_approval' => 'required|boolean']);

        return $this->run(MessagesEnum::TRIVIA_QUESTION_SAVED, fn (TriviaModerationService $service) => $service->setQuestionApproval(
            $event_id,
            Auth::user()->id,
            $question_id,
            (bool) $data['requires_approval']
        ));
    }

    public function fx(int $event_id, Request $request)
    {
        $data = $request->validate([
            'sound_enabled' => 'sometimes|boolean',
            'sound_volume' => 'sometimes|integer|min:0|max:100',
            'reactions_enabled' => 'sometimes|boolean',
        ]);

        return $this->run(MessagesEnum::TRIVIA_FX_UPDATED, fn (TriviaModerationService $service) => $service->updateFx(
            $event_id,
            Auth::user()->id,
            $data
        ));
    }

    public function react(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(TriviaModerationService::REACTION_KINDS)],
        ]);

        return $this->run(MessagesEnum::TRIVIA_REACTION_SENT, fn (TriviaModerationService $service) => $service->react(
            (int) $request->attributes->get('trivia_event_id'),
            (string) $request->attributes->get('trivia_session_token'),
            $data['kind']
        ));
    }

    public function checkText(Request $request)
    {
        $data = $request->validate(['text' => 'nullable|string|max:200']);

        return $this->run(MessagesEnum::TRIVIA_TEXT_CHECKED, fn (TriviaModerationService $service) => $service->checkText(
            $data['text'] ?? ''
        ));
    }

    private function run(string $message, callable $action)
    {
        try {
            return $this->successResponse($message, $action(new TriviaModerationService()));
        } catch (Exception $ex) {
            return $this->errorResponse($ex, TriviaService::errorReason($ex), $ex->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
