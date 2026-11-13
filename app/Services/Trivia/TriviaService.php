<?php

namespace App\Services\Trivia;

use App\Models\Event;
use App\Models\EventAsset;
use App\Models\TriviaAnswer;
use App\Models\TriviaGame;
use App\Models\TriviaPlayer;
use App\Models\TriviaQuestion;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\TriviaPaceEnum;
use App\Services\Enums\TriviaPhaseEnum;
use App\Services\Enums\TriviaQuestionTypeEnum;
use App\Services\Enums\TriviaStatusEnum;
use App\Services\Events\EventService;
use App\Services\Helpers\FileService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TriviaService
{
    public const MAX_QUESTIONS = 40;

    public function __construct(
        private ?EventService $event_service = null,
    ) {
        $this->event_service ??= new EventService();
    }

    public function publicScreen(Event $event, string $event_path): array
    {
        $game = TriviaGame::with(['currentQuestion.options', 'currentQuestion.mediaAsset'])
            ->where('event_id', $event->id)
            ->first();
        $status_phase = $this->statusPhase($game);
        $current_phase = $this->currentPhase($game, $status_phase);

        $payload = [
            'phase' => $status_phase,
            'current_phase' => $current_phase,
            'theme' => $game?->theme ?? 'elegant',
            'pace' => $game?->pace ?? TriviaPaceEnum::HOST,
            'title' => $game?->title,
            'player_count' => $status_phase === 'photos' || !$game
                ? 0
                : TriviaPlayer::where('event_id', $event->id)->count(),
            'join_path' => '/event/trivia/' . $event_path,
            'top' => [],
            'teams' => null,
            'prize_text' => null,
            'question' => null,
            'tallies' => null,
            'fun_fact' => null,
            'correct' => null,
            'joining' => [],
            'server_now' => now()->toIso8601String(),
        ];

        if (!$game || $status_phase === 'photos') {
            return $payload;
        }

        $top_limit = $current_phase === TriviaPhaseEnum::LEADERBOARD ? 8 : 3;
        $payload['top'] = $this->topPlayers((int) $event->id, $top_limit);
        $payload['teams'] = $this->teamTotals($game);
        $payload['joining'] = $this->joiningPlayers((int) $event->id);

        if ($current_phase === TriviaPhaseEnum::PODIUM || $status_phase === 'results') {
            $payload['prize_text'] = $game->prize_text;
            $payload['top'] = $this->topPlayers((int) $event->id, 3);
        }

        if (
            $game->current_question_id
            && in_array($current_phase, [TriviaPhaseEnum::QUESTION, TriviaPhaseEnum::REVEAL], true)
        ) {
            $question = $game->currentQuestion ?? TriviaQuestion::with(['options', 'mediaAsset'])
                ->where('event_id', $event->id)
                ->where('id', $game->current_question_id)
                ->first();

            if ($question) {
                $include_correct = $current_phase === TriviaPhaseEnum::REVEAL;
                $payload['question'] = $this->publicQuestionPayload($game, $question, $include_correct);

                $show_tallies = $include_correct
                    || in_array($question->type, TriviaQuestionTypeEnum::unscored(), true);
                if ($show_tallies) {
                    $payload['tallies'] = $this->talliesFor($question);
                }

                if ($include_correct) {
                    $payload['fun_fact'] = $question->fun_fact;
                    $payload['correct'] = $this->correctPayload($question);
                }
            }
        }

        return $payload;
    }

    public function screenByPath(string $event_path): array
    {
        $event = $this->findEventByPath($event_path);

        return $this->publicScreen($event, $event_path);
    }

    public function getAdmin(int $event_id, int $user_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = TriviaGame::with('questions.options')->where('event_id', $event_id)->first();

        if (!$game) {
            return $this->emptyAdmin($event);
        }

        return $this->formatAdmin($game, $event);
    }

    public function updateSettings(int $event_id, int $user_id, array $data): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = TriviaGame::firstOrNew(['event_id' => $event_id]);
        $was_on_screen = $game->exists && in_array((int) $game->status, [
            TriviaStatusEnum::LIVE,
            TriviaStatusEnum::RESULTS,
        ], true);

        $starts_at = array_key_exists('starts_at', $data)
            ? (!empty($data['starts_at']) ? $data['starts_at'] : null)
            : $game->starts_at;
        $results_at = array_key_exists('results_at', $data)
            ? (!empty($data['results_at']) ? $data['results_at'] : null)
            : $game->results_at;

        if ($starts_at && $results_at && strtotime((string) $results_at) <= strtotime((string) $starts_at)) {
            throw new Exception(MessagesEnum::TRIVIA_RESULTS_AFTER_START, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('enabled', $data)) {
            $game->enabled = (bool) $data['enabled'];
        } elseif (!$game->exists) {
            $game->enabled = false;
        }

        if (array_key_exists('title', $data)) {
            $game->title = trim((string) ($data['title'] ?? '')) ?: 'טריוויה';
        } elseif (!$game->exists) {
            $game->title = 'טריוויה';
        }

        $game->starts_at = $starts_at;
        $game->results_at = $results_at;

        $this->applySettingsFields($game, $data);

        if (!$game->enabled) {
            $game->status = TriviaStatusEnum::DRAFT;
            $game->current_phase = TriviaPhaseEnum::PHOTOS;
            $game->current_question_id = null;
            $game->question_started_at = null;
        } elseif (!$was_on_screen) {
            $game->status = ($game->starts_at && $game->starts_at->isFuture())
                ? TriviaStatusEnum::SCHEDULED
                : TriviaStatusEnum::DRAFT;
        }

        if (!$game->exists) {
            $game->theme = $game->theme ?: 'elegant';
            $game->pace = $game->pace ?: TriviaPaceEnum::HOST;
            $game->teams_mode = $game->teams_mode ?: 'none';
            $game->speed_bonus = $game->speed_bonus ?? true;
            $game->reveal_seconds = $game->reveal_seconds ?: 6;
            $game->default_time = $game->default_time ?: 20;
            $game->default_points = $game->default_points ?: 1000;
        }

        $game->save();

        return $this->formatAdmin($game->load('questions.options'), $event);
    }

    public function replaceQuestions(int $event_id, int $user_id, array $questions): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->ensureGame($event_id);
        $this->assertQuestionsEditable($game);

        if (count($questions) > self::MAX_QUESTIONS) {
            throw new Exception(MessagesEnum::TRIVIA_QUESTION_CAP, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($questions as $question) {
            $this->assertQuestionPayload($question);
        }

        DB::transaction(function () use ($game, $questions) {
            $game->current_question_id = null;
            $game->save();

            TriviaQuestion::where('event_id', $game->event_id)->delete();
            TriviaPlayer::where('event_id', $game->event_id)->update([
                'score' => 0,
                'finished_at' => null,
            ]);

            foreach (array_values($questions) as $index => $question) {
                $this->createQuestionFromPayload($game, $question, $index);
            }
        });

        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function patchQuestion(int $event_id, int $user_id, int $question_id, array $data): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);
        $this->assertQuestionsEditable($game);

        $question = TriviaQuestion::with('options')
            ->where('event_id', $event_id)
            ->where('id', $question_id)
            ->first();

        if (!$question) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        $merged = [
            'type' => $data['type'] ?? $question->type ?? TriviaQuestionTypeEnum::CHOICE,
            'prompt' => $data['prompt'] ?? $question->prompt,
            'time_limit_seconds' => $data['time_limit_seconds'] ?? $question->time_limit_seconds,
            'max_points' => $data['max_points'] ?? $question->max_points,
            'fun_fact' => array_key_exists('fun_fact', $data) ? $data['fun_fact'] : $question->fun_fact,
            'media_asset_id' => array_key_exists('media_asset_id', $data) ? $data['media_asset_id'] : $question->media_asset_id,
            'config' => array_key_exists('config', $data) ? $data['config'] : $question->config,
            'options' => $data['options'] ?? $question->options->map(fn ($option) => [
                'label' => $option->label,
                'is_correct' => (bool) $option->is_correct,
            ])->all(),
        ];

        $this->assertQuestionPayload($merged);
        $this->assertMediaBelongsToEvent($event_id, $merged['media_asset_id'] ?? null);

        DB::transaction(function () use ($question, $merged) {
            $question->type = $merged['type'];
            $question->prompt = trim((string) $merged['prompt']);
            $question->time_limit_seconds = (int) $merged['time_limit_seconds'];
            $question->max_points = in_array($merged['type'], TriviaQuestionTypeEnum::unscored(), true)
                ? 0
                : (int) $merged['max_points'];
            $question->fun_fact = isset($merged['fun_fact']) && $merged['fun_fact'] !== ''
                ? trim((string) $merged['fun_fact'])
                : null;
            $question->media_asset_id = $merged['media_asset_id'] ?? null;
            $question->config = $merged['config'] ?? null;
            $question->save();

            if (array_key_exists('options', $merged)) {
                $question->options()->delete();
                foreach (array_values($merged['options'] ?? []) as $option_index => $option) {
                    $question->options()->create([
                        'label' => trim($option['label']),
                        'is_correct' => (bool) ($option['is_correct'] ?? false),
                        'sort_order' => $option_index,
                    ]);
                }
            }
        });

        // Intentionally do not touch player scores or answers.
        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function appendQuestion(int $event_id, int $user_id, array $data): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->ensureGame($event_id);
        $this->assertQuestionsEditable($game);

        $count = TriviaQuestion::where('event_id', $event_id)->count();
        if ($count >= self::MAX_QUESTIONS) {
            throw new Exception(MessagesEnum::TRIVIA_QUESTION_CAP, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->assertQuestionPayload($data);
        $this->assertMediaBelongsToEvent($event_id, $data['media_asset_id'] ?? null);

        $this->createQuestionFromPayload($game, $data, $count);

        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function deleteQuestion(int $event_id, int $user_id, int $question_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);
        $this->assertQuestionsEditable($game);

        $question = TriviaQuestion::where('event_id', $event_id)->where('id', $question_id)->first();
        if (!$question) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($game, $question, $event_id) {
            if ((int) $game->current_question_id === (int) $question->id) {
                $game->current_question_id = null;
                $game->save();
            }
            $question->delete();
            $this->reindexQuestions($event_id);
        });

        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function reorderQuestions(int $event_id, int $user_id, array $ids): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);
        $this->assertQuestionsEditable($game);

        $existing = TriviaQuestion::where('event_id', $event_id)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $incoming = collect($ids)->map(fn ($id) => (int) $id)->values();

        if ($existing->all() !== $incoming->sort()->values()->all()) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($incoming) {
            foreach ($incoming as $index => $id) {
                TriviaQuestion::where('id', $id)->update(['sort_order' => $index]);
            }
        });

        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function setPhase(int $event_id, int $user_id, string $phase, ?int $question_id = null): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);

        if (!in_array($phase, TriviaPhaseEnum::all(), true)) {
            throw new Exception(MessagesEnum::TRIVIA_PHASE_INVALID, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($phase === TriviaPhaseEnum::PHOTOS) {
            return $this->backToPhotos($event_id, $user_id);
        }

        if ($phase === TriviaPhaseEnum::PODIUM) {
            $game->enabled = true;
            $game->status = TriviaStatusEnum::RESULTS;
            $game->results_at = $game->results_at ?? now();
            $game->current_phase = TriviaPhaseEnum::PODIUM;
            $game->current_question_id = null;
            $game->question_started_at = null;
            $game->save();

            return $this->formatAdmin($game->load('questions.options'), $event);
        }

        $this->assertHasQuestions($game);
        $game->enabled = true;
        $game->status = TriviaStatusEnum::LIVE;

        if ($phase === TriviaPhaseEnum::LOBBY) {
            $game->current_phase = TriviaPhaseEnum::LOBBY;
            $game->current_question_id = null;
            $game->question_started_at = null;
            $game->starts_at = $game->starts_at ?? now();
            $game->save();

            return $this->formatAdmin($game->load('questions.options'), $event);
        }

        if ($phase === TriviaPhaseEnum::LEADERBOARD) {
            $game->current_phase = TriviaPhaseEnum::LEADERBOARD;
            $game->save();

            return $this->formatAdmin($game->load('questions.options'), $event);
        }

        $question = $this->resolvePhaseQuestion($game, $question_id);

        if ($phase === TriviaPhaseEnum::QUESTION) {
            $this->enterQuestionPhase($game, $question);
        } elseif ($phase === TriviaPhaseEnum::REVEAL) {
            $game->current_question_id = $question->id;
            $game->current_phase = TriviaPhaseEnum::REVEAL;
            if (!$game->question_started_at) {
                $game->question_started_at = now();
            }
            $game->save();
            $this->finalizeNumberScores($game, $question);
        } else {
            throw new Exception(MessagesEnum::TRIVIA_PHASE_INVALID, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function applyTemplate(int $event_id, int $user_id, string $template_id, bool $replace = false): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $pack = TriviaTemplates::find($template_id);
        if (!$pack) {
            throw new Exception(MessagesEnum::TRIVIA_TEMPLATE_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        $game = $this->ensureGame($event_id);
        $this->assertQuestionsEditable($game);

        $existing_count = TriviaQuestion::where('event_id', $event_id)->count();
        if ($existing_count > 0 && !$replace) {
            throw new Exception(MessagesEnum::TRIVIA_TEMPLATE_HAS_QUESTIONS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $questions = $pack['questions'];
        if (count($questions) > self::MAX_QUESTIONS) {
            throw new Exception(MessagesEnum::TRIVIA_QUESTION_CAP, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($game, $pack, $questions) {
            foreach ($pack['game'] ?? [] as $key => $value) {
                $game->{$key} = $value;
            }
            $game->current_question_id = null;
            $game->current_phase = null;
            $game->question_started_at = null;
            $game->save();

            TriviaQuestion::where('event_id', $game->event_id)->delete();
            TriviaPlayer::where('event_id', $game->event_id)->update([
                'score' => 0,
                'finished_at' => null,
            ]);

            foreach (array_values($questions) as $index => $question) {
                $this->createQuestionFromPayload($game, $question, $index);
            }
        });

        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function templates(): array
    {
        return TriviaTemplates::catalog();
    }

    public function showNow(int $event_id, int $user_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);
        $this->assertHasQuestions($game);

        $game->enabled = true;
        $game->status = TriviaStatusEnum::LIVE;
        $game->starts_at = $game->starts_at ?? now();
        $game->current_phase = TriviaPhaseEnum::LOBBY;
        $game->current_question_id = null;
        $game->question_started_at = null;
        $game->save();

        return $this->formatAdmin($game->load('questions.options'), $event);
    }

    public function showResults(int $event_id, int $user_id): array
    {
        $game = $this->gameOrFail($event_id);

        if (!in_array((int) $game->status, [TriviaStatusEnum::LIVE, TriviaStatusEnum::RESULTS], true)) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_LIVE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->setPhase($event_id, $user_id, TriviaPhaseEnum::PODIUM);
    }

    public function backToPhotos(int $event_id, int $user_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);

        $game->status = ($game->enabled && $game->starts_at && $game->starts_at->isFuture())
            ? TriviaStatusEnum::SCHEDULED
            : TriviaStatusEnum::DRAFT;
        $game->current_phase = TriviaPhaseEnum::PHOTOS;
        $game->current_question_id = null;
        $game->question_started_at = null;
        $game->save();

        return $this->formatAdmin($game->load('questions.options'), $event);
    }

    public function report(int $event_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $game = TriviaGame::with(['questions.answers', 'players.answers'])->where('event_id', $event_id)->first();

        if (!$game) {
            return [
                'summary' => [
                    'player_count' => 0,
                    'average_score' => 0,
                    'average_accuracy' => null,
                ],
                'players' => [],
                'questions' => [],
            ];
        }

        $players = $this->rankedPlayers($event_id);
        $player_count = $players->count();
        $average_score = $player_count ? (int) round($players->avg('score')) : 0;
        $accuracies = [];

        $questions = $game->questions->map(function (TriviaQuestion $question) use (&$accuracies) {
            $answers = $question->answers->whereNotNull('answered_at');
            $answered = $answers->count();
            $correct = $answers->where('is_correct', true)->count();
            $accuracy = $answered ? round($correct / $answered, 2) : null;
            if ($accuracy !== null) {
                $accuracies[] = $accuracy;
            }

            return [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'type' => $question->type ?? TriviaQuestionTypeEnum::CHOICE,
                'answered_count' => $answered,
                'correct_count' => $correct,
                'accuracy' => $accuracy,
            ];
        })->values();

        return [
            'summary' => [
                'player_count' => $player_count,
                'average_score' => $average_score,
                'average_accuracy' => count($accuracies)
                    ? round(array_sum($accuracies) / count($accuracies), 2)
                    : null,
            ],
            'players' => $players->values()->map(function (TriviaPlayer $player, int $index) {
                $answers = $player->answers->whereNotNull('answered_at');

                return [
                    'rank' => $index + 1,
                    'id' => $player->id,
                    'nickname' => $player->nickname,
                    'avatar_url' => $this->avatarUrl($player->avatar_path),
                    'team' => $player->team,
                    'score' => (int) $player->score,
                    'correct_count' => $answers->where('is_correct', true)->count(),
                    'total_time_ms' => (int) $answers->sum('elapsed_ms'),
                    'finished_at' => $player->finished_at?->toIso8601String(),
                    'answers' => $answers->map(fn (TriviaAnswer $answer) => [
                        'question_id' => $answer->question_id,
                        'is_correct' => (bool) $answer->is_correct,
                        'points_awarded' => (int) $answer->points_awarded,
                        'elapsed_ms' => (int) $answer->elapsed_ms,
                    ])->values(),
                ];
            })->all(),
            'questions' => $questions,
        ];
    }

    public function join(string $event_path, string $nickname, $image = null, ?string $team = null): array
    {
        $event = $this->findEventByPath($event_path);
        $game = $this->liveGameOrFail((int) $event->id);

        if (($game->teams_mode ?? 'none') === 'sides') {
            if (!in_array($team, ['a', 'b'], true)) {
                throw new Exception(MessagesEnum::TRIVIA_TEAM_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $team = null;
        }

        $player = new TriviaPlayer([
            'event_id' => $game->event_id,
            'session_token' => Str::random(48),
            'nickname' => trim($nickname),
            'team' => $team,
            'score' => 0,
        ]);

        if ($image) {
            $stored = FileService::create($image, "events/{$event->id}/trivia");
            if (!$stored) {
                throw new Exception(MessagesEnum::TRIVIA_AVATAR_FAILED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $player->avatar_path = $stored;
        }

        $player->save();

        return [
            'session_token' => $player->session_token,
            'player' => $this->playerPayload($player),
            'phase' => 'live',
            'current_phase' => $this->currentPhase($game, 'live'),
            'pace' => $game->pace ?? TriviaPaceEnum::HOST,
            'teams_mode' => $game->teams_mode ?? 'none',
            'team_labels' => $game->team_labels,
        ];
    }

    public function me(int $event_id, string $session_token): array
    {
        $player = $this->playerOrFail($event_id, $session_token);
        $game = TriviaGame::where('event_id', $event_id)->first();
        $total = TriviaQuestion::where('event_id', $event_id)->count();
        $status_phase = $this->statusPhase($game);

        return [
            'phase' => $status_phase,
            'current_phase' => $this->currentPhase($game, $status_phase),
            'pace' => $game?->pace ?? TriviaPaceEnum::HOST,
            'player' => $this->playerPayload($player),
            'rank' => $this->rankOf($player),
            'correct_count' => $player->answers()->where('is_correct', true)->count(),
            'total_questions' => $total,
        ];
    }

    public function nextQuestion(int $event_id, string $session_token): array
    {
        $player = $this->playerOrFail($event_id, $session_token);
        $game = $this->gameOrFail($event_id);
        $questions = $game->questions()->with(['options', 'mediaAsset'])->get();
        $status_phase = $this->statusPhase($game);
        $current_phase = $this->currentPhase($game, $status_phase);

        if ($status_phase === 'results' || $current_phase === TriviaPhaseEnum::PODIUM) {
            return $this->finishedPayload($player, $questions->count());
        }

        if ($status_phase !== 'live') {
            throw new Exception(MessagesEnum::TRIVIA_NOT_LIVE, Response::HTTP_FORBIDDEN);
        }

        $pace = $game->pace ?? TriviaPaceEnum::HOST;

        if (TriviaPaceEnum::isShared($pace)) {
            return $this->sharedNextQuestion($game, $player, $questions, $current_phase);
        }

        return $this->freeNextQuestion($player, $questions);
    }

    public function submitAnswer(
        int $event_id,
        string $session_token,
        int $question_id,
        array $option_ids = [],
        $value_number = null
    ): array {
        $player = $this->playerOrFail($event_id, $session_token);
        $game = $this->liveGameOrFail($event_id);
        $question = TriviaQuestion::with('options')
            ->where('event_id', $event_id)
            ->where('id', $question_id)
            ->first();

        if (!$question) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        $pace = $game->pace ?? TriviaPaceEnum::HOST;
        if (TriviaPaceEnum::isShared($pace)) {
            $current_phase = $this->currentPhase($game, 'live');
            if (
                $current_phase !== TriviaPhaseEnum::QUESTION
                || (int) $game->current_question_id !== (int) $question->id
            ) {
                throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return DB::transaction(function () use ($player, $game, $question, $option_ids, $value_number, $pace) {
            $answer = TriviaAnswer::where('player_id', $player->id)
                ->where('question_id', $question->id)
                ->lockForUpdate()
                ->first();

            if (!$answer) {
                if (TriviaPaceEnum::isShared($pace) && $game->question_started_at) {
                    $answer = TriviaAnswer::create([
                        'player_id' => $player->id,
                        'question_id' => $question->id,
                        'served_at' => $game->question_started_at,
                    ]);
                } else {
                    throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            if ($answer->answered_at) {
                $player->refresh();

                return $this->answerResult($player, $answer, $game, $question);
            }

            $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
            $elapsed = max(0, (int) round(now()->getTimestampMs() - $answer->served_at->getTimestampMs()));

            if ($type === TriviaQuestionTypeEnum::NUMBER) {
                if ($value_number === null || !is_numeric($value_number)) {
                    throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $answer->value_number = (float) $value_number;
                $answer->selected_option_ids = [];
                $answer->elapsed_ms = $elapsed;
                $answer->is_correct = false;
                $answer->points_awarded = 0;
                $answer->answered_at = now();
                $answer->save();

                if (!TriviaPaceEnum::isShared($pace)) {
                    $this->finalizeNumberScores($game, $question->fresh('options'));
                    $answer->refresh();
                    $player->score = (int) TriviaAnswer::where('player_id', $player->id)->sum('points_awarded');
                    $this->maybeFinishPlayer($player, $game);
                    $player->save();
                }

                return $this->answerResult($player->fresh(), $answer, $game, $question);
            }

            $valid_ids = $question->options->pluck('id')->map(fn ($id) => (int) $id)->all();
            $selected = collect($option_ids)->map(fn ($id) => (int) $id)->unique()->sort()->values();

            if ($selected->diff($valid_ids)->isNotEmpty()) {
                throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (in_array($type, TriviaQuestionTypeEnum::unscored(), true)) {
                $exact = false;
                $points = 0;
            } else {
                $correct = $question->options
                    ->where('is_correct', true)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->sort()
                    ->values();
                $exact = $correct->all() === $selected->all() && $correct->isNotEmpty();
                $points = $this->pointsFor(
                    $exact,
                    $elapsed,
                    (int) $question->time_limit_seconds,
                    (int) $question->max_points,
                    (bool) ($game->speed_bonus ?? true)
                );
            }

            $answer->selected_option_ids = $selected->all();
            $answer->value_number = null;
            $answer->elapsed_ms = $elapsed;
            $answer->is_correct = $points > 0;
            $answer->points_awarded = $points;
            $answer->answered_at = now();
            $answer->save();

            $player->score = (int) TriviaAnswer::where('player_id', $player->id)->sum('points_awarded');
            $this->maybeFinishPlayer($player, $game);
            $player->save();

            return $this->answerResult($player, $answer, $game, $question);
        });
    }

    public function tick(): void
    {
        $now = now();

        TriviaGame::where('enabled', true)
            ->where('status', TriviaStatusEnum::SCHEDULED)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->whereHas('questions')
            ->update([
                'status' => TriviaStatusEnum::LIVE,
                'current_phase' => TriviaPhaseEnum::LOBBY,
                'updated_at' => $now,
            ]);

        TriviaGame::where('enabled', true)
            ->where('status', TriviaStatusEnum::LIVE)
            ->whereNotNull('results_at')
            ->where('results_at', '<=', $now)
            ->update([
                'status' => TriviaStatusEnum::RESULTS,
                'current_phase' => TriviaPhaseEnum::PODIUM,
                'current_question_id' => null,
                'question_started_at' => null,
                'updated_at' => $now,
            ]);

        $auto_games = TriviaGame::with('currentQuestion')
            ->where('enabled', true)
            ->where('status', TriviaStatusEnum::LIVE)
            ->where('pace', TriviaPaceEnum::AUTO)
            ->whereIn('current_phase', [TriviaPhaseEnum::QUESTION, TriviaPhaseEnum::REVEAL])
            ->whereNotNull('question_started_at')
            ->get();

        foreach ($auto_games as $game) {
            $question = $game->currentQuestion;
            if (!$question) {
                continue;
            }

            $limit = (int) $question->time_limit_seconds;
            $reveal = (int) ($game->reveal_seconds ?: 6);
            $started = $game->question_started_at;

            if ($game->current_phase === TriviaPhaseEnum::QUESTION) {
                if ($started->copy()->addSeconds($limit)->lte($now)) {
                    $game->current_phase = TriviaPhaseEnum::REVEAL;
                    $game->save();
                    $this->finalizeNumberScores($game, $question);
                }
                continue;
            }

            if ($game->current_phase === TriviaPhaseEnum::REVEAL) {
                if ($started->copy()->addSeconds($limit + $reveal)->lte($now)) {
                    $next = TriviaQuestion::where('event_id', $game->event_id)
                        ->where('sort_order', '>', $question->sort_order)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first();

                    if ($next) {
                        $this->enterQuestionPhase($game, $next);
                    } else {
                        $game->status = TriviaStatusEnum::RESULTS;
                        $game->current_phase = TriviaPhaseEnum::PODIUM;
                        $game->current_question_id = null;
                        $game->question_started_at = null;
                        $game->results_at = $game->results_at ?? $now;
                        $game->save();
                    }
                }
            }
        }
    }

    /**
     * Exact-match speed scoring. When speed_bonus is off, award full max_points on a correct on-time answer.
     */
    public function pointsFor(
        bool $exact_match,
        int $elapsed_ms,
        int $time_limit_seconds,
        int $max_points,
        bool $speed_bonus = true
    ): int {
        $limit_ms = max(1, $time_limit_seconds) * 1000;
        if (!$exact_match || $elapsed_ms > $limit_ms || $max_points <= 0) {
            return 0;
        }

        if (!$speed_bonus) {
            return $max_points;
        }

        $remaining_ratio = 1 - ($elapsed_ms / $limit_ms);

        return (int) round($max_points * (0.5 + (0.5 * $remaining_ratio)));
    }

    /**
     * Closest-wins for number questions (SPEC §3).
     * Full points for the minimum distance among on-time answers (ties share).
     * Others: max_points * max(0, 1 - d/span). With speed_bonus, mix a 50% speed term.
     */
    public function scoreNumberAnswers(TriviaGame $game, TriviaQuestion $question): void
    {
        $this->finalizeNumberScores($game, $question);
    }

    private function sharedNextQuestion(
        TriviaGame $game,
        TriviaPlayer $player,
        $questions,
        string $current_phase
    ): array {
        if ($current_phase !== TriviaPhaseEnum::QUESTION || !$game->current_question_id) {
            return [
                'waiting' => true,
                'phase' => $current_phase,
                'current_phase' => $current_phase,
                'finished' => false,
                'server_now' => now()->toIso8601String(),
            ];
        }

        $question = $questions->firstWhere('id', $game->current_question_id);
        if (!$question) {
            return [
                'waiting' => true,
                'phase' => $current_phase,
                'current_phase' => $current_phase,
                'finished' => false,
                'server_now' => now()->toIso8601String(),
            ];
        }

        $index = $questions->search(fn ($item) => (int) $item->id === (int) $question->id);
        $answer = TriviaAnswer::where('player_id', $player->id)
            ->where('question_id', $question->id)
            ->first();

        if ($answer && $answer->answered_at) {
            return [
                'waiting' => true,
                'phase' => $current_phase,
                'current_phase' => $current_phase,
                'answered' => true,
                'finished' => false,
                'server_now' => now()->toIso8601String(),
            ];
        }

        if (!$answer) {
            try {
                $answer = TriviaAnswer::create([
                    'player_id' => $player->id,
                    'question_id' => $question->id,
                    'served_at' => $game->question_started_at ?? now(),
                ]);
            } catch (Exception $ex) {
                $answer = TriviaAnswer::where('player_id', $player->id)
                    ->where('question_id', $question->id)
                    ->first();
            }
        } elseif (
            $game->question_started_at
            && $answer->served_at->getTimestamp() !== $game->question_started_at->getTimestamp()
        ) {
            $answer->served_at = $game->question_started_at;
            $answer->save();
        }

        return $this->questionPayload($question, $answer, (int) $index, $questions->count(), $game);
    }

    private function freeNextQuestion(TriviaPlayer $player, $questions): array
    {
        $answers = TriviaAnswer::where('player_id', $player->id)->get()->keyBy('question_id');

        foreach ($questions as $index => $question) {
            $answer = $answers->get($question->id);
            if ($answer && $answer->answered_at) {
                continue;
            }

            if (!$answer) {
                try {
                    $answer = TriviaAnswer::create([
                        'player_id' => $player->id,
                        'question_id' => $question->id,
                        'served_at' => now(),
                    ]);
                } catch (Exception $ex) {
                    $answer = TriviaAnswer::where('player_id', $player->id)
                        ->where('question_id', $question->id)
                        ->first();
                }
            }

            return $this->questionPayload($question, $answer, $index, $questions->count());
        }

        if (!$player->finished_at) {
            $player->finished_at = now();
            $player->save();
        }

        return $this->finishedPayload($player, $questions->count());
    }

    private function answerResult(
        TriviaPlayer $player,
        TriviaAnswer $answer,
        TriviaGame $game,
        TriviaQuestion $question
    ): array {
        $total = TriviaQuestion::where('event_id', $game->event_id)->count();
        $pace = $game->pace ?? TriviaPaceEnum::HOST;
        $current_phase = $this->currentPhase($game, $this->statusPhase($game));
        $reveal = !TriviaPaceEnum::isShared($pace) || $current_phase === TriviaPhaseEnum::REVEAL;

        $payload = [
            'points_awarded' => $reveal ? (int) $answer->points_awarded : null,
            'score' => (int) $player->score,
            'elapsed_ms' => (int) $answer->elapsed_ms,
            'finished' => (bool) $player->finished_at,
            'rank' => $this->rankOf($player),
            'correct_count' => $reveal
                ? TriviaAnswer::where('player_id', $player->id)->where('is_correct', true)->count()
                : null,
            'total_questions' => $total,
            'waiting_for_reveal' => !$reveal,
            'current_phase' => $current_phase,
        ];

        if ($reveal) {
            $payload['is_correct'] = (bool) $answer->is_correct;
            $payload['correct_option_ids'] = $question->options
                ->where('is_correct', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            if (($question->type ?? '') === TriviaQuestionTypeEnum::NUMBER) {
                $payload['correct_number'] = $question->config['correct'] ?? null;
            }
        } else {
            $payload['is_correct'] = null;
            $payload['correct_option_ids'] = null;
        }

        return $payload;
    }

    private function questionPayload(
        TriviaQuestion $question,
        TriviaAnswer $answer,
        int $index,
        int $total,
        ?TriviaGame $game = null
    ): array {
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        $correct_count = $question->options->where('is_correct', true)->count();
        $selection_mode = $type === TriviaQuestionTypeEnum::MULTI || $correct_count > 1
            ? 'multiple'
            : 'single';

        $payload = [
            'finished' => false,
            'waiting' => false,
            'server_now' => now()->toIso8601String(),
            'question' => [
                'id' => $question->id,
                'type' => $type,
                'prompt' => $question->prompt,
                'index' => $index + 1,
                'total' => $total,
                'time_limit_seconds' => (int) $question->time_limit_seconds,
                'max_points' => (int) $question->max_points,
                'served_at' => $answer->served_at->toIso8601String(),
                'started_at' => ($game?->question_started_at ?? $answer->served_at)->toIso8601String(),
                'selection_mode' => $selection_mode,
                'config' => $this->publicConfig($question),
                'media' => $this->mediaPayload($question),
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                ])->values(),
            ],
        ];

        return $payload;
    }

    private function publicQuestionPayload(TriviaGame $game, TriviaQuestion $question, bool $include_correct): array
    {
        $questions = TriviaQuestion::where('event_id', $game->event_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order']);
        $index = $questions->search(fn ($item) => (int) $item->id === (int) $question->id);
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        $correct_count = $question->options->where('is_correct', true)->count();

        $options = $question->options->map(function ($option) use ($include_correct) {
            $row = [
                'id' => $option->id,
                'label' => $option->label,
            ];
            if ($include_correct) {
                $row['is_correct'] = (bool) $option->is_correct;
            }

            return $row;
        })->values();

        return [
            'id' => $question->id,
            'type' => $type,
            'prompt' => $question->prompt,
            'index' => $index === false ? 1 : $index + 1,
            'total' => $questions->count(),
            'time_limit_seconds' => (int) $question->time_limit_seconds,
            'max_points' => (int) $question->max_points,
            'started_at' => $game->question_started_at?->toIso8601String(),
            'selection_mode' => $type === TriviaQuestionTypeEnum::MULTI || $correct_count > 1 ? 'multiple' : 'single',
            'config' => $this->publicConfig($question, $include_correct),
            'media' => $this->mediaPayload($question),
            'options' => $options,
        ];
    }

    private function publicConfig(TriviaQuestion $question, bool $include_correct = false): ?array
    {
        $config = $question->config;
        if (!is_array($config)) {
            return null;
        }

        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        if ($type === TriviaQuestionTypeEnum::NUMBER) {
            $public = [
                'min' => $config['min'] ?? null,
                'max' => $config['max'] ?? null,
                'unit' => $config['unit'] ?? null,
            ];
            if ($include_correct) {
                $public['correct'] = $config['correct'] ?? null;
            }

            return $public;
        }

        if (in_array($type, [TriviaQuestionTypeEnum::WHO, TriviaQuestionTypeEnum::WHOSAID, TriviaQuestionTypeEnum::THISORTHAT], true)) {
            return ['sides' => $config['sides'] ?? null];
        }

        if ($type === TriviaQuestionTypeEnum::TWOTRUTHS) {
            $truths = $config['truths'] ?? null;
            if (!$include_correct && is_array($truths)) {
                $truths = array_map(function ($row) {
                    return ['label' => $row['label'] ?? ''];
                }, $truths);
            }

            return ['truths' => $truths];
        }

        return $config;
    }

    private function mediaPayload(TriviaQuestion $question): ?array
    {
        if (!$question->media_asset_id) {
            return null;
        }

        $asset = $question->relationLoaded('mediaAsset')
            ? $question->mediaAsset
            : EventAsset::find($question->media_asset_id);

        if (!$asset) {
            return ['id' => (int) $question->media_asset_id, 'url' => null];
        }

        return [
            'id' => (int) $asset->id,
            'url' => rtrim((string) config('app.storage_url'), '/') . '/' . ltrim((string) $asset->path, '/'),
        ];
    }

    private function correctPayload(TriviaQuestion $question): array
    {
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        if ($type === TriviaQuestionTypeEnum::NUMBER) {
            $closest = $this->closestNumberPlayer($question);

            return [
                'number' => $question->config['correct'] ?? null,
                'closest' => $closest,
            ];
        }

        return [
            'option_ids' => $question->options
                ->where('is_correct', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        ];
    }

    private function closestNumberPlayer(TriviaQuestion $question): ?array
    {
        $correct = isset($question->config['correct']) ? (float) $question->config['correct'] : null;
        if ($correct === null) {
            return null;
        }

        $answers = TriviaAnswer::with('player')
            ->where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->whereNotNull('value_number')
            ->get();

        if ($answers->isEmpty()) {
            return null;
        }

        $best = $answers->sortBy(fn (TriviaAnswer $answer) => abs((float) $answer->value_number - $correct))->first();
        if (!$best?->player) {
            return null;
        }

        return [
            'nickname' => $best->player->nickname,
            'value' => (float) $best->value_number,
            'avatar_url' => $this->avatarUrl($best->player->avatar_path),
        ];
    }

    private function talliesFor(TriviaQuestion $question): array
    {
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        $answers = TriviaAnswer::where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->get();

        if ($type === TriviaQuestionTypeEnum::NUMBER) {
            return [
                'mode' => 'number',
                'guesses' => $answers->map(fn (TriviaAnswer $answer) => [
                    'player_id' => (int) $answer->player_id,
                    'value' => $answer->value_number !== null ? (float) $answer->value_number : null,
                ])->values()->all(),
            ];
        }

        $counts = [];
        foreach ($question->options as $option) {
            $counts[(int) $option->id] = 0;
        }

        foreach ($answers as $answer) {
            foreach ($answer->selected_option_ids ?? [] as $option_id) {
                $id = (int) $option_id;
                if (array_key_exists($id, $counts)) {
                    $counts[$id]++;
                }
            }
        }

        $total = max(1, $answers->count());

        return [
            'mode' => 'options',
            'total_answers' => $answers->count(),
            'options' => collect($counts)->map(fn ($count, $id) => [
                'option_id' => (int) $id,
                'count' => (int) $count,
                'percent' => round(($count / $total) * 100),
            ])->values()->all(),
        ];
    }

    private function finalizeNumberScores(TriviaGame $game, TriviaQuestion $question): void
    {
        if (($question->type ?? '') !== TriviaQuestionTypeEnum::NUMBER) {
            return;
        }

        $config = $question->config ?? [];
        if (!isset($config['correct'], $config['min'], $config['max'])) {
            return;
        }

        $correct = (float) $config['correct'];
        $span = max(0.0001, abs((float) $config['max'] - (float) $config['min']));
        $limit_ms = max(1, (int) $question->time_limit_seconds) * 1000;
        $max_points = (int) $question->max_points;
        $speed_bonus = (bool) ($game->speed_bonus ?? true);

        $answers = TriviaAnswer::where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->get();

        $on_time = $answers->filter(function (TriviaAnswer $answer) use ($limit_ms) {
            return $answer->value_number !== null && (int) $answer->elapsed_ms <= $limit_ms;
        });

        $min_distance = null;
        foreach ($on_time as $answer) {
            $distance = abs((float) $answer->value_number - $correct);
            if ($min_distance === null || $distance < $min_distance) {
                $min_distance = $distance;
            }
        }

        $touched_players = [];

        foreach ($answers as $answer) {
            $elapsed = (int) ($answer->elapsed_ms ?? 0);
            if ($answer->value_number === null || $elapsed > $limit_ms) {
                $points = 0;
                $is_correct = false;
            } else {
                $distance = abs((float) $answer->value_number - $correct);
                $is_closest = $min_distance !== null && abs($distance - $min_distance) < 0.00001;
                if ($is_closest) {
                    $base = $max_points;
                } else {
                    $base = (int) round($max_points * max(0, 1 - ($distance / $span)));
                }

                if ($speed_bonus && $base > 0) {
                    $remaining_ratio = 1 - ($elapsed / $limit_ms);
                    $points = (int) round($base * (0.5 + (0.5 * $remaining_ratio)));
                } else {
                    $points = $base;
                }
                $is_correct = $is_closest && $points > 0;
            }

            $answer->points_awarded = $points;
            $answer->is_correct = $is_correct;
            $answer->save();
            $touched_players[(int) $answer->player_id] = true;
        }

        foreach (array_keys($touched_players) as $player_id) {
            $player = TriviaPlayer::find($player_id);
            if (!$player) {
                continue;
            }
            $player->score = (int) TriviaAnswer::where('player_id', $player->id)->sum('points_awarded');
            $this->maybeFinishPlayer($player, $game);
            $player->save();
        }
    }

    private function enterQuestionPhase(TriviaGame $game, TriviaQuestion $question): void
    {
        $game->current_question_id = $question->id;
        $game->current_phase = TriviaPhaseEnum::QUESTION;
        $game->question_started_at = now();
        $game->status = TriviaStatusEnum::LIVE;
        $game->enabled = true;
        $game->starts_at = $game->starts_at ?? now();
        $game->save();
    }

    private function resolvePhaseQuestion(TriviaGame $game, ?int $question_id): TriviaQuestion
    {
        if ($question_id) {
            $question = TriviaQuestion::where('event_id', $game->event_id)->where('id', $question_id)->first();
            if (!$question) {
                throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
            }

            return $question;
        }

        if ($game->current_question_id) {
            $question = TriviaQuestion::where('event_id', $game->event_id)
                ->where('id', $game->current_question_id)
                ->first();
            if ($question) {
                return $question;
            }
        }

        $question = TriviaQuestion::where('event_id', $game->event_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (!$question) {
            throw new Exception(MessagesEnum::TRIVIA_QUESTIONS_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $question;
    }

    private function finishedPayload(TriviaPlayer $player, int $total): array
    {
        return [
            'finished' => true,
            'waiting' => false,
            'score' => (int) $player->score,
            'rank' => $this->rankOf($player),
            'correct_count' => TriviaAnswer::where('player_id', $player->id)->where('is_correct', true)->count(),
            'total_questions' => $total,
        ];
    }

    private function topPlayers(int $event_id, int $limit): array
    {
        return $this->rankedPlayers($event_id)
            ->take($limit)
            ->values()
            ->map(fn (TriviaPlayer $player, int $index) => [
                'rank' => $index + 1,
                'nickname' => $player->nickname,
                'avatar_url' => $this->avatarUrl($player->avatar_path),
                'team' => $player->team,
                'score' => (int) $player->score,
            ])
            ->all();
    }

    private function joiningPlayers(int $event_id): array
    {
        return TriviaPlayer::where('event_id', $event_id)
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->map(fn (TriviaPlayer $player) => [
                'nickname' => $player->nickname,
                'avatar_url' => $this->avatarUrl($player->avatar_path),
                'team' => $player->team,
            ])
            ->all();
    }

    private function teamTotals(TriviaGame $game): ?array
    {
        if (($game->teams_mode ?? 'none') !== 'sides') {
            return null;
        }

        $labels = $game->team_labels ?? ['a' => 'צד עמית', 'b' => 'צד גל'];
        $totals = TriviaPlayer::where('event_id', $game->event_id)
            ->selectRaw('team, COALESCE(SUM(score), 0) as total')
            ->whereIn('team', ['a', 'b'])
            ->groupBy('team')
            ->pluck('total', 'team');

        return [
            'mode' => 'sides',
            'labels' => $labels,
            'scores' => [
                'a' => (int) ($totals['a'] ?? 0),
                'b' => (int) ($totals['b'] ?? 0),
            ],
        ];
    }

    private function rankedPlayers(int $event_id)
    {
        return TriviaPlayer::where('event_id', $event_id)
            ->with('answers')
            ->withSum('answers as total_time_ms', 'elapsed_ms')
            ->orderByDesc('score')
            ->orderByRaw('finished_at IS NULL')
            ->orderBy('finished_at')
            ->orderByRaw('total_time_ms IS NULL')
            ->orderBy('total_time_ms')
            ->orderBy('id')
            ->get();
    }

    private function rankOf(TriviaPlayer $player): int
    {
        $ids = $this->rankedPlayers((int) $player->event_id)->pluck('id');
        $index = $ids->search($player->id);

        return $index === false ? $ids->count() + 1 : $index + 1;
    }

    private function statusPhase(?TriviaGame $game): string
    {
        if (!$game || !$game->enabled) {
            return 'photos';
        }

        return match ((int) $game->status) {
            TriviaStatusEnum::LIVE => 'live',
            TriviaStatusEnum::RESULTS => 'results',
            default => 'photos',
        };
    }

    private function currentPhase(?TriviaGame $game, string $status_phase): string
    {
        if ($status_phase === 'photos') {
            return TriviaPhaseEnum::PHOTOS;
        }

        if ($status_phase === 'results') {
            return $game?->current_phase ?: TriviaPhaseEnum::PODIUM;
        }

        return $game?->current_phase ?: TriviaPhaseEnum::LOBBY;
    }

    private function formatAdmin(TriviaGame $game, Event $event): array
    {
        $status_phase = $this->statusPhase($game);

        return [
            'event_id' => (int) $game->event_id,
            'enabled' => (bool) $game->enabled,
            'status' => TriviaStatusEnum::name((int) $game->status),
            'phase' => $status_phase,
            'current_phase' => $this->currentPhase($game, $status_phase),
            'title' => $game->title,
            'theme' => $game->theme ?? 'elegant',
            'pace' => $game->pace ?? TriviaPaceEnum::HOST,
            'teams_mode' => $game->teams_mode ?? 'none',
            'team_labels' => $game->team_labels,
            'speed_bonus' => (bool) ($game->speed_bonus ?? true),
            'prize_text' => $game->prize_text,
            'reveal_seconds' => (int) ($game->reveal_seconds ?? 6),
            'default_time' => (int) ($game->default_time ?? 20),
            'default_points' => (int) ($game->default_points ?? 1000),
            'current_question_id' => $game->current_question_id ? (int) $game->current_question_id : null,
            'question_started_at' => $game->question_started_at?->toIso8601String(),
            'starts_at' => $game->starts_at?->toIso8601String(),
            'results_at' => $game->results_at?->toIso8601String(),
            'join_path' => '/event/trivia/' . $event->path,
            'question_cap' => self::MAX_QUESTIONS,
            'questions' => $game->questions->map(fn (TriviaQuestion $question) => $this->adminQuestionPayload($question))->values(),
        ];
    }

    private function adminQuestionPayload(TriviaQuestion $question): array
    {
        return [
            'id' => $question->id,
            'type' => $question->type ?? TriviaQuestionTypeEnum::CHOICE,
            'prompt' => $question->prompt,
            'fun_fact' => $question->fun_fact,
            'media_asset_id' => $question->media_asset_id ? (int) $question->media_asset_id : null,
            'media' => $this->mediaPayload($question),
            'config' => $question->config,
            'sort_order' => (int) $question->sort_order,
            'time_limit_seconds' => (int) $question->time_limit_seconds,
            'max_points' => (int) $question->max_points,
            'options' => $question->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'is_correct' => (bool) $option->is_correct,
                'sort_order' => (int) $option->sort_order,
            ])->values(),
        ];
    }

    private function emptyAdmin(Event $event): array
    {
        return [
            'event_id' => (int) $event->id,
            'enabled' => false,
            'status' => 'draft',
            'phase' => 'photos',
            'current_phase' => TriviaPhaseEnum::PHOTOS,
            'title' => 'טריוויה',
            'theme' => 'elegant',
            'pace' => TriviaPaceEnum::HOST,
            'teams_mode' => 'none',
            'team_labels' => ['a' => 'צד עמית', 'b' => 'צד גל'],
            'speed_bonus' => true,
            'prize_text' => null,
            'reveal_seconds' => 6,
            'default_time' => 20,
            'default_points' => 1000,
            'current_question_id' => null,
            'question_started_at' => null,
            'starts_at' => null,
            'results_at' => null,
            'join_path' => '/event/trivia/' . $event->path,
            'question_cap' => self::MAX_QUESTIONS,
            'questions' => [],
        ];
    }

    private function playerPayload(TriviaPlayer $player): array
    {
        return [
            'nickname' => $player->nickname,
            'avatar_url' => $this->avatarUrl($player->avatar_path),
            'team' => $player->team,
            'score' => (int) $player->score,
            'finished' => (bool) $player->finished_at,
        ];
    }

    private function avatarUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return rtrim((string) config('app.storage_url'), '/') . '/' . ltrim($path, '/');
    }

    private function applySettingsFields(TriviaGame $game, array $data): void
    {
        if (array_key_exists('theme', $data)) {
            $game->theme = in_array($data['theme'], ['elegant', 'neon'], true) ? $data['theme'] : 'elegant';
        }
        if (array_key_exists('pace', $data)) {
            $game->pace = in_array($data['pace'], TriviaPaceEnum::all(), true)
                ? $data['pace']
                : TriviaPaceEnum::HOST;
        }
        if (array_key_exists('teams_mode', $data)) {
            $game->teams_mode = in_array($data['teams_mode'], ['none', 'sides'], true)
                ? $data['teams_mode']
                : 'none';
        }
        if (array_key_exists('team_labels', $data)) {
            $game->team_labels = $data['team_labels'];
        }
        if (array_key_exists('speed_bonus', $data)) {
            $game->speed_bonus = (bool) $data['speed_bonus'];
        }
        if (array_key_exists('prize_text', $data)) {
            $value = $data['prize_text'];
            $game->prize_text = $value !== null && $value !== '' ? trim((string) $value) : null;
        }
        if (array_key_exists('reveal_seconds', $data)) {
            $game->reveal_seconds = (int) $data['reveal_seconds'];
        }
        if (array_key_exists('default_time', $data)) {
            $game->default_time = (int) $data['default_time'];
        }
        if (array_key_exists('default_points', $data)) {
            $game->default_points = (int) $data['default_points'];
        }
    }

    private function createQuestionFromPayload(TriviaGame $game, array $question, int $index): TriviaQuestion
    {
        $type = $question['type'] ?? TriviaQuestionTypeEnum::CHOICE;
        $max_points = in_array($type, TriviaQuestionTypeEnum::unscored(), true)
            ? 0
            : (int) ($question['max_points'] ?? $game->default_points ?? 1000);

        $created = TriviaQuestion::create([
            'event_id' => $game->event_id,
            'prompt' => trim($question['prompt']),
            'type' => $type,
            'fun_fact' => isset($question['fun_fact']) && $question['fun_fact'] !== ''
                ? trim((string) $question['fun_fact'])
                : null,
            'media_asset_id' => $question['media_asset_id'] ?? null,
            'config' => $question['config'] ?? null,
            'sort_order' => $index,
            'time_limit_seconds' => (int) ($question['time_limit_seconds'] ?? $game->default_time ?? 20),
            'max_points' => $max_points,
        ]);

        foreach (array_values($question['options'] ?? []) as $option_index => $option) {
            $created->options()->create([
                'label' => trim($option['label']),
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => $option_index,
            ]);
        }

        return $created;
    }

    private function assertQuestionPayload(array $question): void
    {
        $type = $question['type'] ?? TriviaQuestionTypeEnum::CHOICE;
        if (!in_array($type, TriviaQuestionTypeEnum::mvp(), true)) {
            throw new Exception(MessagesEnum::TRIVIA_INVALID_TYPE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($type === TriviaQuestionTypeEnum::NUMBER) {
            $config = $question['config'] ?? null;
            if (
                !is_array($config)
                || !isset($config['min'], $config['max'], $config['correct'])
                || !is_numeric($config['min'])
                || !is_numeric($config['max'])
                || !is_numeric($config['correct'])
                || (float) $config['max'] <= (float) $config['min']
            ) {
                throw new Exception(MessagesEnum::TRIVIA_INVALID_TYPE, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return;
        }

        $options = $question['options'] ?? [];
        if (!is_array($options) || count($options) < 2 || count($options) > 6) {
            throw new Exception(MessagesEnum::TRIVIA_OPTION_CORRECT_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (TriviaQuestionTypeEnum::requiresCorrectOption($type)) {
            $correct = collect($options)->contains(fn ($option) => !empty($option['is_correct']));
            if (!$correct) {
                throw new Exception(MessagesEnum::TRIVIA_OPTION_CORRECT_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
    }

    private function assertMediaBelongsToEvent(int $event_id, $media_asset_id): void
    {
        if ($media_asset_id === null || $media_asset_id === '') {
            return;
        }

        $exists = EventAsset::where('event_id', $event_id)->where('id', (int) $media_asset_id)->exists();
        if (!$exists) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function assertQuestionsEditable(TriviaGame $game): void
    {
        if ($game->exists && in_array((int) $game->status, [TriviaStatusEnum::LIVE, TriviaStatusEnum::RESULTS], true)) {
            throw new Exception(MessagesEnum::TRIVIA_QUESTIONS_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function ensureGame(int $event_id): TriviaGame
    {
        $game = TriviaGame::firstOrNew(['event_id' => $event_id]);
        if (!$game->exists) {
            $game->enabled = false;
            $game->status = TriviaStatusEnum::DRAFT;
            $game->title = 'טריוויה';
            $game->theme = 'elegant';
            $game->pace = TriviaPaceEnum::HOST;
            $game->teams_mode = 'none';
            $game->team_labels = ['a' => 'צד עמית', 'b' => 'צד גל'];
            $game->speed_bonus = true;
            $game->reveal_seconds = 6;
            $game->default_time = 20;
            $game->default_points = 1000;
            $game->save();
        }

        return $game;
    }

    private function reindexQuestions(int $event_id): void
    {
        $questions = TriviaQuestion::where('event_id', $event_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($questions as $index => $question) {
            if ((int) $question->sort_order !== $index) {
                $question->sort_order = $index;
                $question->save();
            }
        }
    }

    private function maybeFinishPlayer(TriviaPlayer $player, TriviaGame $game): void
    {
        $question_ids = TriviaQuestion::where('event_id', $game->event_id)->pluck('id');
        $answered_ids = TriviaAnswer::where('player_id', $player->id)
            ->whereNotNull('answered_at')
            ->pluck('question_id');

        if ($question_ids->diff($answered_ids)->isEmpty()) {
            $player->finished_at = $player->finished_at ?? now();
        }
    }

    private function findEventByPath(string $event_path): Event
    {
        $event = Event::where('path', $event_path)->first();

        if (!$event) {
            throw new Exception(MessagesEnum::EVENT_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return $event;
    }

    private function gameOrFail(int $event_id): TriviaGame
    {
        $game = TriviaGame::where('event_id', $event_id)->first();

        if (!$game) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return $game;
    }

    private function liveGameOrFail(int $event_id): TriviaGame
    {
        $game = $this->gameOrFail($event_id);

        if ($this->statusPhase($game) !== 'live') {
            throw new Exception(MessagesEnum::TRIVIA_NOT_LIVE, Response::HTTP_FORBIDDEN);
        }

        return $game;
    }

    private function assertHasQuestions(TriviaGame $game): void
    {
        if (!$game->questions()->exists()) {
            throw new Exception(MessagesEnum::TRIVIA_QUESTIONS_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function playerOrFail(int $event_id, string $session_token): TriviaPlayer
    {
        $player = TriviaPlayer::where('event_id', $event_id)
            ->where('session_token', $session_token)
            ->first();

        if (!$player) {
            throw new Exception(MessagesEnum::TRIVIA_PLAYER_NOT_FOUND, Response::HTTP_UNAUTHORIZED);
        }

        return $player;
    }
}
