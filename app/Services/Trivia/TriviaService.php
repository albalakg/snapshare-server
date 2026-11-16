<?php

namespace App\Services\Trivia;

use App\Models\Event;
use App\Models\EventAsset;
use App\Models\TriviaAnswer;
use App\Models\TriviaGame;
use App\Models\TriviaPlayer;
use App\Models\TriviaQuestion;
use App\Services\Enums\EventAssetTypeEnum;
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

    public const THEMES = ['elegant', 'neon', 'cinema', 'arcade', 'playful', 'festival', 'corporate'];

    public const TEAMS_MODES = ['none', 'sides', 'tables'];

    private const DEFAULT_DATE_SPAN_DAYS = 3650;

    private const MAP_SPAN = 141.4213562373095; // 100 * sqrt(2)

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
            'prompt' => array_key_exists('prompt', $data) ? $data['prompt'] : $question->prompt,
            'time_limit_seconds' => array_key_exists('time_limit_seconds', $data) && $data['time_limit_seconds'] !== null && $data['time_limit_seconds'] !== ''
                ? $data['time_limit_seconds']
                : $question->time_limit_seconds,
            'max_points' => $data['max_points'] ?? $question->max_points,
            'fun_fact' => array_key_exists('fun_fact', $data) ? $data['fun_fact'] : $question->fun_fact,
            'media_asset_id' => array_key_exists('media_asset_id', $data) ? $data['media_asset_id'] : $question->media_asset_id,
            'config' => array_key_exists('config', $data) ? $data['config'] : $question->config,
            'options' => array_key_exists('options', $data)
                ? $data['options']
                : $question->options->map(fn ($option) => [
                    'label' => $option->label,
                    'is_correct' => (bool) $option->is_correct,
                ])->all(),
        ];

        $old_type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        $type_changed = array_key_exists('type', $data) && $data['type'] !== null && $data['type'] !== $old_type;
        if ($type_changed) {
            $new_type = (string) $merged['type'];
            if (!array_key_exists('config', $data)) {
                $merged['config'] = $this->defaultConfigForType($new_type);
            }
            if (
                !array_key_exists('options', $data)
                && (!TriviaQuestionTypeEnum::usesOptions($old_type) || in_array($new_type, [
                    TriviaQuestionTypeEnum::ORDER,
                    TriviaQuestionTypeEnum::MATCH,
                ], true))
            ) {
                $merged['options'] = $this->defaultOptionsForType($new_type);
            }
            if (
                !array_key_exists('max_points', $data)
                && (int) $question->max_points === 0
                && !in_array($new_type, TriviaQuestionTypeEnum::unscored(), true)
            ) {
                $merged['max_points'] = (int) ($game->default_points ?: 1000);
            }
        }

        if (!TriviaQuestionTypeEnum::usesOptions((string) $merged['type'])) {
            $merged['options'] = [];
        }

        $this->assertQuestionPayload($merged);
        [$merged['options'], $merged['config']] = $this->shapeForType(
            (string) $merged['type'],
            is_array($merged['options'] ?? null) ? $merged['options'] : [],
            is_array($merged['config'] ?? null) ? $merged['config'] : null
        );
        $this->assertMediaBelongsToEvent($event_id, $merged['media_asset_id'] ?? null);

        DB::transaction(function () use ($question, $merged) {
            $question->type = $merged['type'];
            $question->prompt = trim((string) ($merged['prompt'] ?? ''));
            $question->time_limit_seconds = (int) ($merged['time_limit_seconds'] ?: 20);
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
                        'label' => trim((string) ($option['label'] ?? '')),
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
        $this->assertQuestionsReadyToGoLive($game);
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
            $this->finalizeRevealScores($game, $question);
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
        $this->assertQuestionsReadyToGoLive($game);

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

        $teams_mode = $game->teams_mode ?? 'none';
        if ($teams_mode === 'sides') {
            if (!in_array($team, ['a', 'b'], true)) {
                throw new Exception(MessagesEnum::TRIVIA_TEAM_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } elseif ($teams_mode === 'tables') {
            $team = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $team) ?? ''), 0, 40);
            if ($team === '') {
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
        $value_number = null,
        ?string $value_text = null,
        ?array $value_json = null
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
            $existing = TriviaAnswer::where('player_id', $player->id)
                ->where('question_id', $question->id)
                ->first();

            // Guest already answered: allow re-read during reveal so the phone
            // can fetch is_correct / points (host-paced feedback).
            if ($existing?->answered_at) {
                return $this->answerResult($player->fresh(), $existing, $game, $question);
            }

            if (
                $current_phase !== TriviaPhaseEnum::QUESTION
                || (int) $game->current_question_id !== (int) $question->id
            ) {
                throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return DB::transaction(function () use ($player, $game, $question, $option_ids, $value_number, $value_text, $value_json, $pace) {
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
            $shared = TriviaPaceEnum::isShared($pace);

            // Closest-wins types (number / date / map): store the guess, score on reveal.
            if (in_array($type, TriviaQuestionTypeEnum::closest(), true)) {
                $this->fillClosestGuess($answer, $question, $value_number, $value_json);
                $answer->selected_option_ids = [];
                $answer->elapsed_ms = $elapsed;
                $answer->is_correct = false;
                $answer->points_awarded = 0;
                $answer->answered_at = now();
                $answer->save();

                if (!$shared) {
                    $this->finalizeClosestScores($game, $question->fresh('options'));
                    $answer->refresh();
                    $player->score = (int) TriviaAnswer::where('player_id', $player->id)->sum('points_awarded');
                    $this->maybeFinishPlayer($player, $game);
                    $player->save();
                }

                return $this->answerResult($player->fresh(), $answer, $game, $question);
            }

            // Free-text, unscored (cloud / finish).
            if (in_array($type, TriviaQuestionTypeEnum::freeText(), true)) {
                $text = $this->cleanText($value_text);
                if ($text === '') {
                    throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return $this->storeSimpleAnswer($player, $game, $question, $answer, $elapsed, [
                    'value_text' => $text,
                ], false, 0);
            }

            // Rating 1..10 (configurable), unscored.
            if ($type === TriviaQuestionTypeEnum::RATE) {
                $config = is_array($question->config) ? $question->config : [];
                $min = is_numeric($config['min'] ?? null) ? (float) $config['min'] : 1.0;
                $max = is_numeric($config['max'] ?? null) ? (float) $config['max'] : 10.0;
                if ($value_number === null || !is_numeric($value_number)
                    || (float) $value_number < $min || (float) $value_number > $max) {
                    throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return $this->storeSimpleAnswer($player, $game, $question, $answer, $elapsed, [
                    'value_number' => (float) $value_number,
                ], false, 0);
            }

            // Fill in the blank: case/trim insensitive text match.
            if ($type === TriviaQuestionTypeEnum::FILLBLANK) {
                $text = $this->cleanText($value_text);
                if ($text === '') {
                    throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $exact = $this->fillBlankMatches($question, $text);

                return $this->storeSimpleAnswer($player, $game, $question, $answer, $elapsed, [
                    'value_text' => $text,
                ], $exact, $this->pointsFor(
                    $exact,
                    $elapsed,
                    (int) $question->time_limit_seconds,
                    (int) $question->max_points,
                    (bool) ($game->speed_bonus ?? true)
                ));
            }

            // Match pairs: guest submits value_json {matches:[{left_id,right_id}]}.
            if ($type === TriviaQuestionTypeEnum::MATCH) {
                $given = $this->normalizeMatchSubmission($question, $value_json);
                if ($given === null) {
                    throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $expected = $this->matchCorrectMap($question);
                ksort($given);
                ksort($expected);
                $exact = $expected !== [] && $given === $expected;

                $matches = [];
                foreach ($given as $left_id => $right_id) {
                    $matches[] = ['left_id' => (int) $left_id, 'right_id' => (int) $right_id];
                }

                return $this->storeSimpleAnswer($player, $game, $question, $answer, $elapsed, [
                    'value_json' => ['matches' => $matches],
                ], $exact, $this->pointsFor(
                    $exact,
                    $elapsed,
                    (int) $question->time_limit_seconds,
                    (int) $question->max_points,
                    (bool) ($game->speed_bonus ?? true)
                ));
            }

            $valid_ids = $question->options->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Order: guest submits every option id in their chosen sequence.
            if ($type === TriviaQuestionTypeEnum::ORDER) {
                $sequence = collect($option_ids)->map(fn ($id) => (int) $id)->values();
                $sorted = $sequence->sort()->values()->all();
                $expected_sorted = collect($valid_ids)->sort()->values()->all();
                if ($sequence->count() !== count($valid_ids) || $sorted !== $expected_sorted) {
                    throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $exact = $sequence->all() === $valid_ids;

                return $this->storeSimpleAnswer($player, $game, $question, $answer, $elapsed, [
                    'selected_option_ids' => $sequence->all(),
                ], $exact, $this->pointsFor(
                    $exact,
                    $elapsed,
                    (int) $question->time_limit_seconds,
                    (int) $question->max_points,
                    (bool) ($game->speed_bonus ?? true)
                ));
            }

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

                // Buzzer in a shared (host/auto) game: only the fastest correct answer
                // scores, decided on reveal. Hold points back until then.
                if ($type === TriviaQuestionTypeEnum::BUZZER && $shared) {
                    $points = 0;
                }
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

    /**
     * Persist a non-option answer (text / number / json / ordered ids) and refresh the player's score.
     */
    private function storeSimpleAnswer(
        TriviaPlayer $player,
        TriviaGame $game,
        TriviaQuestion $question,
        TriviaAnswer $answer,
        int $elapsed,
        array $fields,
        bool $is_correct,
        int $points
    ): array {
        $answer->selected_option_ids = $fields['selected_option_ids'] ?? [];
        $answer->value_number = $fields['value_number'] ?? null;
        $answer->value_text = $fields['value_text'] ?? null;
        $answer->value_json = $fields['value_json'] ?? null;
        $answer->elapsed_ms = $elapsed;
        $answer->is_correct = $points > 0 && $is_correct;
        $answer->points_awarded = $points;
        $answer->answered_at = now();
        $answer->save();

        $player->score = (int) TriviaAnswer::where('player_id', $player->id)->sum('points_awarded');
        $this->maybeFinishPlayer($player, $game);
        $player->save();

        return $this->answerResult($player, $answer, $game, $question);
    }

    private function cleanText(?string $value): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return mb_substr($text, 0, 200);
    }

    /**
     * Lower-case, collapse whitespace, strip Hebrew niqqud and punctuation edges.
     */
    private function normalizeAnswerText(string $value): string
    {
        $value = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B.,!?;:\"'");

        return mb_strtolower(trim($value));
    }

    private function fillBlankMatches(TriviaQuestion $question, string $text): bool
    {
        $config = is_array($question->config) ? $question->config : [];
        $accepted = [];
        if (isset($config['answer']) && is_scalar($config['answer'])) {
            $accepted[] = (string) $config['answer'];
        }
        foreach ((array) ($config['alternates'] ?? []) as $alt) {
            if (is_scalar($alt)) {
                $accepted[] = (string) $alt;
            }
        }

        $candidate = $this->normalizeAnswerText($text);
        foreach ($accepted as $item) {
            $normalized = $this->normalizeAnswerText($item);
            if ($normalized !== '' && $normalized === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate and store the raw guess for a closest-wins question.
     */
    private function fillClosestGuess(TriviaAnswer $answer, TriviaQuestion $question, $value_number, ?array $value_json): void
    {
        $type = $question->type ?? TriviaQuestionTypeEnum::NUMBER;
        $answer->value_number = null;
        $answer->value_json = null;
        $answer->value_text = null;

        if ($type === TriviaQuestionTypeEnum::MAP) {
            $x = $value_json['x'] ?? null;
            $y = $value_json['y'] ?? null;
            if (!is_numeric($x) || !is_numeric($y)
                || (float) $x < 0 || (float) $x > 100 || (float) $y < 0 || (float) $y > 100) {
                throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $answer->value_json = ['x' => (float) $x, 'y' => (float) $y];

            return;
        }

        if ($value_number === null || !is_numeric($value_number)) {
            throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($type === TriviaQuestionTypeEnum::DATE) {
            $n = (int) round((float) $value_number);
            if ($n < 10000101 || !checkdate(intdiv($n % 10000, 100), $n % 100, intdiv($n, 10000))) {
                throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $answer->value_number = (float) $n;

            return;
        }

        $answer->value_number = (float) $value_number;
    }

    /**
     * @return array<int,int>|null leftOptionId => rightIndex, null when the submission is malformed
     */
    private function normalizeMatchSubmission(TriviaQuestion $question, ?array $value_json): ?array
    {
        if (!is_array($value_json) || $value_json === []) {
            return null;
        }

        $valid_left = $question->options->pluck('id')->map(fn ($id) => (int) $id)->all();
        $right_count = count($this->matchRightLabels($question));

        $pairs = [];
        if (isset($value_json['matches']) && is_array($value_json['matches'])) {
            foreach ($value_json['matches'] as $row) {
                if (!is_array($row) || !isset($row['left_id'], $row['right_id'])
                    || !is_numeric($row['left_id']) || !is_numeric($row['right_id'])) {
                    return null;
                }
                $pairs[(int) $row['left_id']] = (int) $row['right_id'];
            }
        } else {
            foreach ($value_json as $left => $right) {
                if (!is_numeric($left) || !is_numeric($right)) {
                    return null;
                }
                $pairs[(int) $left] = (int) $right;
            }
        }

        if ($pairs === []) {
            return null;
        }

        foreach ($pairs as $left_id => $right_id) {
            if (!in_array($left_id, $valid_left, true) || $right_id < 0 || $right_id >= $right_count) {
                return null;
            }
        }

        return $pairs;
    }

    /**
     * @return array<int,string>
     */
    private function matchRightLabels(TriviaQuestion $question): array
    {
        $config = is_array($question->config) ? $question->config : [];

        return array_values(array_map(
            static fn ($label) => (string) $label,
            array_filter((array) ($config['right_labels'] ?? []), 'is_scalar')
        ));
    }

    /**
     * Resolve config.correct_map to leftOptionId => rightIndex.
     * Keys may be 0-based left indices (preferred) or real option ids.
     *
     * @return array<int,int>
     */
    private function matchCorrectMap(TriviaQuestion $question): array
    {
        $config = is_array($question->config) ? $question->config : [];
        $raw = (array) ($config['correct_map'] ?? []);
        $option_ids = $question->options->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $count = count($option_ids);
        if ($raw === [] || $count === 0) {
            return [];
        }

        $keys = array_map('intval', array_keys($raw));
        $as_index = true;
        foreach ($keys as $key) {
            if ($key < 0 || $key >= $count) {
                $as_index = false;
                break;
            }
        }

        $map = [];
        foreach ($raw as $key => $right) {
            if (!is_numeric($right)) {
                continue;
            }
            $left_id = $as_index ? ($option_ids[(int) $key] ?? null) : (int) $key;
            if ($left_id !== null && in_array($left_id, $option_ids, true)) {
                $map[(int) $left_id] = (int) $right;
            }
        }

        return $map;
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
                    $this->finalizeRevealScores($game, $question);
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
        $this->finalizeClosestScores($game, $question);
    }

    private function sharedNextQuestion(
        TriviaGame $game,
        TriviaPlayer $player,
        $questions,
        string $current_phase
    ): array {
        if ($current_phase !== TriviaPhaseEnum::QUESTION || !$game->current_question_id) {
            $answered = false;
            if ($game->current_question_id) {
                $answered = TriviaAnswer::where('player_id', $player->id)
                    ->where('question_id', $game->current_question_id)
                    ->whereNotNull('answered_at')
                    ->exists();
            }

            return [
                'waiting' => true,
                'phase' => $current_phase,
                'current_phase' => $current_phase,
                'answered' => $answered,
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
            $payload['correct_option_ids'] = $this->correctOptionIds($question);
            $type = $question->type ?? '';
            $config = is_array($question->config) ? $question->config : [];
            if ($type === TriviaQuestionTypeEnum::NUMBER) {
                $payload['correct_number'] = $config['correct'] ?? null;
            } elseif ($type === TriviaQuestionTypeEnum::DATE) {
                $payload['correct_date'] = $config['correct'] ?? null;
            } elseif ($type === TriviaQuestionTypeEnum::MAP) {
                $payload['correct_x'] = $config['correct_x'] ?? null;
                $payload['correct_y'] = $config['correct_y'] ?? null;
            } elseif ($type === TriviaQuestionTypeEnum::FILLBLANK) {
                $payload['correct_text'] = $config['answer'] ?? null;
            } elseif ($type === TriviaQuestionTypeEnum::MATCH) {
                $payload['correct_pairs'] = $this->matchCorrectPairs($question);
            }
        } else {
            $payload['is_correct'] = null;
            $payload['correct_option_ids'] = null;
        }

        return $payload;
    }

    /**
     * Correct option ids. For "order" this is the full list in correct sequence.
     *
     * @return array<int,int>
     */
    private function correctOptionIds(TriviaQuestion $question): array
    {
        if (($question->type ?? '') === TriviaQuestionTypeEnum::ORDER) {
            return $question->options->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return $question->options
            ->where('is_correct', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{left_id:int,right_id:int}>
     */
    private function matchCorrectPairs(TriviaQuestion $question): array
    {
        $pairs = [];
        foreach ($this->matchCorrectMap($question) as $left_id => $right_id) {
            $pairs[] = ['left_id' => (int) $left_id, 'right_id' => (int) $right_id];
        }

        return $pairs;
    }

    private function selectionMode(TriviaQuestion $question): string
    {
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        if ($type === TriviaQuestionTypeEnum::ORDER) {
            return 'order';
        }
        if ($type === TriviaQuestionTypeEnum::MATCH) {
            return 'match';
        }
        if (in_array($type, TriviaQuestionTypeEnum::freeText(), true) || $type === TriviaQuestionTypeEnum::FILLBLANK) {
            return 'text';
        }
        if ($type === TriviaQuestionTypeEnum::RATE) {
            return 'rate';
        }
        if (in_array($type, [TriviaQuestionTypeEnum::DATE, TriviaQuestionTypeEnum::MAP], true)) {
            return $type;
        }

        $correct_count = $question->options->where('is_correct', true)->count();

        return in_array($type, [TriviaQuestionTypeEnum::MULTI, TriviaQuestionTypeEnum::SPOTDIFF], true) || $correct_count > 1
            ? 'multiple'
            : 'single';
    }

    /**
     * Options as shown to players. "order" options are shuffled (stable per question)
     * so the stored sequence is not leaked before reveal.
     */
    private function guestOptions(TriviaQuestion $question, bool $reveal = false)
    {
        $options = $question->options;
        if (($question->type ?? '') !== TriviaQuestionTypeEnum::ORDER || $reveal || $options->count() < 2) {
            return $options->values();
        }

        $original = $options->pluck('id')->values()->all();
        $shuffled = $options->sortBy(fn ($option) => crc32($question->id . ':' . $option->id))->values();
        if ($shuffled->pluck('id')->all() === $original) {
            $shuffled = $shuffled->slice(1)->push($shuffled->first())->values();
        }

        return $shuffled;
    }

    private function questionPayload(
        TriviaQuestion $question,
        TriviaAnswer $answer,
        int $index,
        int $total,
        ?TriviaGame $game = null
    ): array {
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        $selection_mode = $this->selectionMode($question);

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
                'options' => $this->guestOptions($question)->map(fn ($option) => [
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

        $options = $this->guestOptions($question, $include_correct)->map(function ($option) use ($include_correct) {
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
            'selection_mode' => $this->selectionMode($question),
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

        if ($type === TriviaQuestionTypeEnum::MATCH) {
            $labels = $this->matchRightLabels($question);
            $public = $config;
            unset($public['pairs'], $public['correct_map'], $public['right_labels']);

            // Right-hand items are shuffled for players; id = index into right_labels.
            $items = [];
            foreach ($labels as $index => $label) {
                $items[] = ['id' => $index, 'label' => $label];
            }
            if (!$include_correct && count($items) > 1) {
                $original = array_column($items, 'id');
                usort($items, fn ($a, $b) => crc32($question->id . ':r' . $a['id']) <=> crc32($question->id . ':r' . $b['id']));
                if (array_column($items, 'id') === $original) {
                    $items[] = array_shift($items);
                }
            }
            $public['right_items'] = array_values($items);

            if ($include_correct) {
                $public['right_labels'] = $labels;
                $public['correct_pairs'] = $this->matchCorrectPairs($question);
            }

            return $public;
        }

        // Everything else: pass config through, but never leak answer keys before reveal.
        $secret_keys = ['correct', 'answer', 'alternates', 'correct_x', 'correct_y', 'correct_map', 'pairs'];
        $public = $config;
        if (!$include_correct) {
            foreach ($secret_keys as $key) {
                unset($public[$key]);
            }
        }

        return $public;
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

        $path = (string) $asset->path;
        $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'oga', 'opus', 'flac'], true)) {
            $kind = 'audio';
        } elseif (
            (int) $asset->asset_type === EventAssetTypeEnum::VIDEO_ID
            || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv', '3gp'], true)
        ) {
            $kind = 'video';
        } else {
            $kind = 'image';
        }

        return [
            'id' => (int) $asset->id,
            'url' => rtrim((string) config('app.storage_url'), '/') . '/' . ltrim($path, '/'),
            'kind' => $kind,
        ];
    }

    private function correctPayload(TriviaQuestion $question): array
    {
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        $config = is_array($question->config) ? $question->config : [];

        if ($type === TriviaQuestionTypeEnum::NUMBER) {
            return [
                'number' => $config['correct'] ?? null,
                'closest' => $this->closestPlayer($question),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::DATE) {
            return [
                'date' => $config['correct'] ?? null,
                'closest' => $this->closestPlayer($question),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::MAP) {
            return [
                'x' => $config['correct_x'] ?? null,
                'y' => $config['correct_y'] ?? null,
                'closest' => $this->closestPlayer($question),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::FILLBLANK) {
            return [
                'answer' => $config['answer'] ?? null,
                'alternates' => array_values((array) ($config['alternates'] ?? [])),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::MATCH) {
            return [
                'option_ids' => $this->correctOptionIds($question),
                'pairs' => $this->matchCorrectPairs($question),
                'right_labels' => $this->matchRightLabels($question),
            ];
        }

        $payload = [
            'option_ids' => $this->correctOptionIds($question),
        ];

        if ($type === TriviaQuestionTypeEnum::BUZZER) {
            $payload['winner'] = $this->buzzerWinnerPayload($question);
        }

        return $payload;
    }

    private function buzzerWinnerPayload(TriviaQuestion $question): ?array
    {
        $winner = TriviaAnswer::with('player')
            ->where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->where('points_awarded', '>', 0)
            ->orderBy('elapsed_ms')
            ->orderBy('id')
            ->first();

        if (!$winner?->player) {
            return null;
        }

        return [
            'nickname' => $winner->player->nickname,
            'elapsed_ms' => (int) $winner->elapsed_ms,
            'avatar_url' => $this->avatarUrl($winner->player->avatar_path),
        ];
    }

    private function closestPlayer(TriviaQuestion $question): ?array
    {
        $answers = TriviaAnswer::with('player')
            ->where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->get();

        $best = null;
        $best_distance = null;
        foreach ($answers as $answer) {
            $distance = $this->closestDistance($question, $answer);
            if ($distance === null) {
                continue;
            }
            if ($best_distance === null || $distance < $best_distance) {
                $best = $answer;
                $best_distance = $distance;
            }
        }

        if (!$best?->player) {
            return null;
        }

        $type = $question->type ?? TriviaQuestionTypeEnum::NUMBER;
        $row = [
            'nickname' => $best->player->nickname,
            'avatar_url' => $this->avatarUrl($best->player->avatar_path),
        ];

        if ($type === TriviaQuestionTypeEnum::MAP) {
            $row['x'] = (float) ($best->value_json['x'] ?? 0);
            $row['y'] = (float) ($best->value_json['y'] ?? 0);
            $row['distance'] = round((float) $best_distance, 2);
        } else {
            $row['value'] = (float) $best->value_number;
        }

        return $row;
    }

    private function talliesFor(TriviaQuestion $question): array
    {
        $type = $question->type ?? TriviaQuestionTypeEnum::CHOICE;
        $answers = TriviaAnswer::where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->get();

        if ($type === TriviaQuestionTypeEnum::NUMBER || $type === TriviaQuestionTypeEnum::DATE) {
            return [
                'mode' => $type === TriviaQuestionTypeEnum::DATE ? 'date' : 'number',
                'guesses' => $answers->map(fn (TriviaAnswer $answer) => [
                    'player_id' => (int) $answer->player_id,
                    'value' => $answer->value_number !== null
                        ? ($type === TriviaQuestionTypeEnum::DATE ? (int) $answer->value_number : (float) $answer->value_number)
                        : null,
                ])->values()->all(),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::MAP) {
            return [
                'mode' => 'map',
                'total_answers' => $answers->count(),
                'guesses' => $answers->filter(fn (TriviaAnswer $answer) => isset($answer->value_json['x'], $answer->value_json['y']))
                    ->map(fn (TriviaAnswer $answer) => [
                        'player_id' => (int) $answer->player_id,
                        'x' => (float) $answer->value_json['x'],
                        'y' => (float) $answer->value_json['y'],
                    ])->values()->all(),
            ];
        }

        if (in_array($type, TriviaQuestionTypeEnum::freeText(), true)) {
            $words = [];
            foreach ($answers as $answer) {
                $text = $this->cleanText($answer->value_text);
                if ($text === '') {
                    continue;
                }
                $key = $this->normalizeAnswerText($text);
                if ($key === '') {
                    $key = $text;
                }
                if (!isset($words[$key])) {
                    $words[$key] = ['text' => $text, 'count' => 0];
                }
                $words[$key]['count']++;
            }

            $rows = array_values($words);
            usort($rows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['text'], $b['text']));

            return [
                'mode' => 'words',
                'total_answers' => $answers->count(),
                'words' => array_slice($rows, 0, 80),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::RATE) {
            $config = is_array($question->config) ? $question->config : [];
            $min = is_numeric($config['min'] ?? null) ? (int) $config['min'] : 1;
            $max = is_numeric($config['max'] ?? null) ? (int) $config['max'] : 10;
            $values = $answers->whereNotNull('value_number')->pluck('value_number')->map(fn ($v) => (float) $v);

            $distribution = [];
            for ($i = $min; $i <= $max && $i - $min < 100; $i++) {
                $distribution[] = [
                    'value' => $i,
                    'count' => $values->filter(fn ($v) => (int) round($v) === $i)->count(),
                ];
            }

            return [
                'mode' => 'rate',
                'total_answers' => $values->count(),
                'average' => $values->isEmpty() ? null : round($values->avg(), 2),
                'min' => $min,
                'max' => $max,
                'distribution' => $distribution,
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

    /**
     * Scoring that can only be settled once the question closes (reveal).
     */
    private function finalizeRevealScores(TriviaGame $game, TriviaQuestion $question): void
    {
        $this->finalizeClosestScores($game, $question);
        $this->finalizeBuzzerScores($game, $question);
    }

    /**
     * Day number for a YYYYMMDD int (or an already-small unix-day value).
     */
    private function dateToDays(float $value): float
    {
        $n = (int) round($value);
        if ($n < 10000101) {
            return (float) $n;
        }

        $year = intdiv($n, 10000);
        $month = intdiv($n % 10000, 100);
        $day = $n % 100;

        return (float) floor(gmmktime(0, 0, 0, max(1, $month), max(1, $day), $year) / 86400);
    }

    /**
     * Reference value + span for closest-wins scoring, or null when the question is not configured.
     *
     * @return array{span:float, correct:float|array{0:float,1:float}}|null
     */
    private function closestSpec(TriviaQuestion $question): ?array
    {
        $type = $question->type ?? '';
        $config = is_array($question->config) ? $question->config : [];

        if ($type === TriviaQuestionTypeEnum::NUMBER) {
            if (!isset($config['correct'], $config['min'], $config['max'])) {
                return null;
            }

            return [
                'correct' => (float) $config['correct'],
                'span' => max(0.0001, abs((float) $config['max'] - (float) $config['min'])),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::DATE) {
            if (!isset($config['correct']) || !is_numeric($config['correct'])) {
                return null;
            }

            $span = (float) self::DEFAULT_DATE_SPAN_DAYS;
            if (isset($config['min'], $config['max']) && is_numeric($config['min']) && is_numeric($config['max'])) {
                $candidate = abs($this->dateToDays((float) $config['max']) - $this->dateToDays((float) $config['min']));
                if ($candidate > 0) {
                    $span = $candidate;
                }
            }

            return [
                'correct' => $this->dateToDays((float) $config['correct']),
                'span' => max(1.0, $span),
            ];
        }

        if ($type === TriviaQuestionTypeEnum::MAP) {
            if (!isset($config['correct_x'], $config['correct_y']) || !is_numeric($config['correct_x']) || !is_numeric($config['correct_y'])) {
                return null;
            }

            return [
                'correct' => [(float) $config['correct_x'], (float) $config['correct_y']],
                'span' => self::MAP_SPAN,
            ];
        }

        return null;
    }

    private function closestDistance(TriviaQuestion $question, TriviaAnswer $answer): ?float
    {
        $spec = $this->closestSpec($question);
        if ($spec === null) {
            return null;
        }

        $type = $question->type ?? '';

        if ($type === TriviaQuestionTypeEnum::MAP) {
            $json = $answer->value_json;
            if (!is_array($json) || !isset($json['x'], $json['y']) || !is_numeric($json['x']) || !is_numeric($json['y'])) {
                return null;
            }

            return sqrt((((float) $json['x']) - $spec['correct'][0]) ** 2 + (((float) $json['y']) - $spec['correct'][1]) ** 2);
        }

        if ($answer->value_number === null) {
            return null;
        }

        $guess = (float) $answer->value_number;
        if ($type === TriviaQuestionTypeEnum::DATE) {
            $guess = $this->dateToDays($guess);
        }

        return abs($guess - $spec['correct']);
    }

    /**
     * Closest-wins scoring for number, date and map questions (SPEC §3).
     * Full points for the minimum distance among on-time answers (ties share).
     * Others: max_points * max(0, 1 - d/span). With speed_bonus, mix a 50% speed term.
     */
    private function finalizeClosestScores(TriviaGame $game, TriviaQuestion $question): void
    {
        if (!in_array($question->type ?? '', TriviaQuestionTypeEnum::closest(), true)) {
            return;
        }

        $spec = $this->closestSpec($question);
        if ($spec === null) {
            return;
        }

        $span = $spec['span'];
        $limit_ms = max(1, (int) $question->time_limit_seconds) * 1000;
        $max_points = (int) $question->max_points;
        $speed_bonus = (bool) ($game->speed_bonus ?? true);

        $answers = TriviaAnswer::where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->get();

        $distances = [];
        $min_distance = null;
        foreach ($answers as $answer) {
            $distance = $this->closestDistance($question, $answer);
            $distances[$answer->id] = $distance;
            if ($distance !== null && (int) $answer->elapsed_ms <= $limit_ms
                && ($min_distance === null || $distance < $min_distance)) {
                $min_distance = $distance;
            }
        }

        $touched_players = [];

        foreach ($answers as $answer) {
            $elapsed = (int) ($answer->elapsed_ms ?? 0);
            $distance = $distances[$answer->id] ?? null;
            if ($distance === null || $elapsed > $limit_ms) {
                $points = 0;
                $is_correct = false;
            } else {
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

        $this->refreshPlayerScores(array_keys($touched_players), $game);
    }

    /**
     * Buzzer: only the fastest on-time correct answer scores (winner-take-all).
     */
    private function finalizeBuzzerScores(TriviaGame $game, TriviaQuestion $question): void
    {
        if (($question->type ?? '') !== TriviaQuestionTypeEnum::BUZZER) {
            return;
        }

        $question->loadMissing('options');
        $correct = $question->options
            ->where('is_correct', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        if ($correct === []) {
            return;
        }

        $limit_ms = max(1, (int) $question->time_limit_seconds) * 1000;
        $answers = TriviaAnswer::where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->orderBy('elapsed_ms')
            ->orderBy('answered_at')
            ->orderBy('id')
            ->get();

        $winner_id = null;
        foreach ($answers as $answer) {
            $selected = collect($answer->selected_option_ids ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if ($selected === $correct && (int) $answer->elapsed_ms <= $limit_ms) {
                $winner_id = $answer->id;
                break;
            }
        }

        $touched_players = [];
        foreach ($answers as $answer) {
            if ($winner_id !== null && $answer->id === $winner_id) {
                $points = $this->pointsFor(
                    true,
                    (int) $answer->elapsed_ms,
                    (int) $question->time_limit_seconds,
                    (int) $question->max_points,
                    (bool) ($game->speed_bonus ?? true)
                );
            } else {
                $points = 0;
            }

            $answer->points_awarded = $points;
            $answer->is_correct = $points > 0;
            $answer->save();
            $touched_players[(int) $answer->player_id] = true;
        }

        $this->refreshPlayerScores(array_keys($touched_players), $game);
    }

    /**
     * @param array<int,int> $player_ids
     */
    private function refreshPlayerScores(array $player_ids, TriviaGame $game): void
    {
        foreach ($player_ids as $player_id) {
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
        $mode = $game->teams_mode ?? 'none';

        if ($mode === 'tables') {
            $rows = TriviaPlayer::where('event_id', $game->event_id)
                ->whereNotNull('team')
                ->where('team', '!=', '')
                ->selectRaw('team, COALESCE(SUM(score), 0) as total, COUNT(*) as players')
                ->groupBy('team')
                ->get();

            $tables = $rows
                ->map(fn ($row) => [
                    'team' => (string) $row->team,
                    'score' => (int) $row->total,
                    'players' => (int) $row->players,
                ])
                ->sort(fn ($a, $b) => [$b['score'], $a['team']] <=> [$a['score'], $b['team']])
                ->values()
                ->all();

            return [
                'mode' => 'tables',
                'labels' => null,
                'tables' => $tables,
            ];
        }

        if ($mode !== 'sides') {
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
            $game->theme = in_array($data['theme'], self::THEMES, true) ? $data['theme'] : 'elegant';
        }
        if (array_key_exists('pace', $data)) {
            $game->pace = in_array($data['pace'], TriviaPaceEnum::all(), true)
                ? $data['pace']
                : TriviaPaceEnum::HOST;
        }
        if (array_key_exists('teams_mode', $data)) {
            $game->teams_mode = in_array($data['teams_mode'], self::TEAMS_MODES, true)
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
        $normalized = $this->normalizeDraftQuestionPayload($question, $game);

        $created = TriviaQuestion::create([
            'event_id' => $game->event_id,
            'prompt' => $normalized['prompt'],
            'type' => $normalized['type'],
            'fun_fact' => $normalized['fun_fact'],
            'media_asset_id' => $normalized['media_asset_id'],
            'config' => $normalized['config'],
            'sort_order' => $index,
            'time_limit_seconds' => $normalized['time_limit_seconds'],
            'max_points' => $normalized['max_points'],
        ]);

        foreach (array_values($normalized['options']) as $option_index => $option) {
            $created->options()->create([
                'label' => $option['label'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => $option_index,
            ]);
        }

        return $created;
    }

    /**
     * Draft-friendly shape check only. Completeness is enforced when going live.
     */
    private function assertQuestionPayload(array $question): void
    {
        $type = $question['type'] ?? TriviaQuestionTypeEnum::CHOICE;
        if (!in_array($type, TriviaQuestionTypeEnum::playable(), true)) {
            throw new Exception(MessagesEnum::TRIVIA_INVALID_TYPE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('options', $question) && $question['options'] !== null) {
            $max_options = $type === TriviaQuestionTypeEnum::MATCH ? 4 : 6;
            if (!is_array($question['options']) || count($question['options']) > $max_options) {
                throw new Exception(MessagesEnum::TRIVIA_OPTION_CORRECT_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (array_key_exists('config', $question) && $question['config'] !== null && !is_array($question['config'])) {
            throw new Exception(MessagesEnum::TRIVIA_INVALID_TYPE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function normalizeDraftQuestionPayload(array $question, ?TriviaGame $game = null): array
    {
        $type = $question['type'] ?? TriviaQuestionTypeEnum::CHOICE;
        $default_time = (int) ($game->default_time ?? 20);
        $default_points = (int) ($game->default_points ?? 1000);

        $options = $question['options'] ?? null;
        if (!TriviaQuestionTypeEnum::usesOptions($type)) {
            $options = [];
        } elseif (!is_array($options)) {
            $options = $this->defaultOptionsForType($type);
        }

        $config = $question['config'] ?? null;
        if ($config === null) {
            $config = $this->defaultConfigForType($type);
        }

        [$options, $config] = $this->shapeForType($type, $options, $config);

        $max_points = in_array($type, TriviaQuestionTypeEnum::unscored(), true)
            ? 0
            : (int) ($question['max_points'] ?? $default_points);

        $time_limit = $question['time_limit_seconds'] ?? null;
        if ($time_limit === null || $time_limit === '') {
            $time_limit = $default_time;
        }

        return [
            'type' => $type,
            'prompt' => trim((string) ($question['prompt'] ?? '')),
            'fun_fact' => isset($question['fun_fact']) && $question['fun_fact'] !== ''
                ? trim((string) $question['fun_fact'])
                : null,
            'media_asset_id' => $question['media_asset_id'] ?? null,
            'config' => $config,
            'time_limit_seconds' => (int) $time_limit,
            'max_points' => $max_points,
            'options' => array_map(static function ($option) {
                return [
                    'label' => trim((string) ($option['label'] ?? '')),
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                ];
            }, array_values($options)),
        ];
    }

    /**
     * Type-specific normalisation of options + config (e.g. match pairs -> options/right_labels/correct_map).
     *
     * @return array{0:array,1:?array}
     */
    private function shapeForType(string $type, array $options, ?array $config): array
    {
        if ($type !== TriviaQuestionTypeEnum::MATCH) {
            return [$options, $config];
        }

        $config = is_array($config) ? $config : [];

        $pairs = [];
        foreach ((array) ($config['pairs'] ?? []) as $pair) {
            if (is_array($pair) && (isset($pair['left']) || isset($pair['right']))) {
                $pairs[] = [
                    'left' => trim((string) ($pair['left'] ?? '')),
                    'right' => trim((string) ($pair['right'] ?? '')),
                ];
            }
        }
        $pairs = array_slice($pairs, 0, 4);

        $options_blank = true;
        foreach ($options as $option) {
            if (trim((string) ($option['label'] ?? '')) !== '') {
                $options_blank = false;
                break;
            }
        }

        if ($pairs !== []) {
            if ($options === [] || $options_blank) {
                $options = array_map(
                    static fn ($pair) => ['label' => $pair['left'], 'is_correct' => false],
                    $pairs
                );
                $config['right_labels'] = array_column($pairs, 'right');
                unset($config['correct_map']);
            } elseif (empty($config['right_labels'])) {
                $config['right_labels'] = array_column($pairs, 'right');
            }
        }

        if (!empty($config['right_labels']) && is_array($config['right_labels'])) {
            $config['right_labels'] = array_values(array_map(
                static fn ($label) => trim((string) $label),
                $config['right_labels']
            ));
            if (empty($config['correct_map'])) {
                $map = [];
                $limit = min(count($options), count($config['right_labels']));
                for ($i = 0; $i < $limit; $i++) {
                    $map[$i] = $i;
                }
                $config['correct_map'] = $map;
            }
        }

        return [$options, $config];
    }

    private function defaultOptionsForType(string $type): array
    {
        if (!TriviaQuestionTypeEnum::usesOptions($type)) {
            return [];
        }

        return match ($type) {
            TriviaQuestionTypeEnum::TRUEFALSE => [
                ['label' => 'נכון', 'is_correct' => true],
                ['label' => 'לא נכון', 'is_correct' => false],
            ],
            TriviaQuestionTypeEnum::WHO, TriviaQuestionTypeEnum::WHOSAID => [
                ['label' => 'עמית', 'is_correct' => true],
                ['label' => 'גל', 'is_correct' => false],
            ],
            TriviaQuestionTypeEnum::THISORTHAT, TriviaQuestionTypeEnum::TUGOFWAR => [
                ['label' => 'אפשרות א', 'is_correct' => false],
                ['label' => 'אפשרות ב', 'is_correct' => false],
            ],
            TriviaQuestionTypeEnum::TWOTRUTHS => [
                ['label' => 'אמת ראשונה', 'is_correct' => false],
                ['label' => 'השקר', 'is_correct' => true],
                ['label' => 'אמת שנייה', 'is_correct' => false],
            ],
            TriviaQuestionTypeEnum::POLL => [
                ['label' => '', 'is_correct' => false],
                ['label' => '', 'is_correct' => false],
                ['label' => '', 'is_correct' => false],
                ['label' => '', 'is_correct' => false],
            ],
            TriviaQuestionTypeEnum::ORDER, TriviaQuestionTypeEnum::MATCH => [
                ['label' => '', 'is_correct' => false],
                ['label' => '', 'is_correct' => false],
                ['label' => '', 'is_correct' => false],
            ],
            default => [
                ['label' => '', 'is_correct' => true],
                ['label' => '', 'is_correct' => false],
                ['label' => '', 'is_correct' => false],
                ['label' => '', 'is_correct' => false],
            ],
        };
    }

    private function defaultConfigForType(string $type): ?array
    {
        return match ($type) {
            TriviaQuestionTypeEnum::NUMBER => [
                'min' => 0,
                'max' => 100,
                'correct' => 50,
                'unit' => '',
            ],
            TriviaQuestionTypeEnum::DATE => [
                'min' => 20000101,
                'max' => 20301231,
                'correct' => 20260101,
            ],
            TriviaQuestionTypeEnum::RATE => [
                'min' => 1,
                'max' => 10,
            ],
            TriviaQuestionTypeEnum::FINISH => [
                'prefix' => '',
            ],
            TriviaQuestionTypeEnum::FILLBLANK => [
                'answer' => '',
            ],
            TriviaQuestionTypeEnum::MAP => [
                'correct_x' => 50,
                'correct_y' => 50,
            ],
            TriviaQuestionTypeEnum::MATCH => [
                'right_labels' => ['', '', ''],
                'correct_map' => [0 => 0, 1 => 1, 2 => 2],
            ],
            TriviaQuestionTypeEnum::WHO, TriviaQuestionTypeEnum::WHOSAID => [
                'sides' => [
                    ['name' => 'עמית', 'color' => '#1F6F6A', 'initial' => 'ע'],
                    ['name' => 'גל', 'color' => '#DC5B66', 'initial' => 'ג'],
                ],
            ],
            TriviaQuestionTypeEnum::THISORTHAT, TriviaQuestionTypeEnum::TUGOFWAR => [
                'sides' => [
                    ['name' => 'אפשרות א', 'color' => '#1F6F6A', 'initial' => 'א'],
                    ['name' => 'אפשרות ב', 'color' => '#6E4A8A', 'initial' => 'ב'],
                ],
            ],
            default => null,
        };
    }

    private function assertQuestionsReadyToGoLive(TriviaGame $game): void
    {
        $questions = TriviaQuestion::with('options')
            ->where('event_id', $game->event_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $errors = [];

        foreach ($questions as $index => $question) {
            $errors = array_merge($errors, $this->completenessErrorsForQuestion($question, $index));
        }

        if ($errors !== []) {
            throw new TriviaIncompleteQuestionsException($errors);
        }
    }

    /**
     * @return array<int, array{question_id:int, index:int, field:string, message:string}>
     */
    private function completenessErrorsForQuestion(TriviaQuestion $question, int $index): array
    {
        $errors = [];
        $qid = (int) $question->id;
        $type = $question->type ?: TriviaQuestionTypeEnum::CHOICE;
        $config = is_array($question->config) ? $question->config : [];

        $add = function (string $field, string $message) use (&$errors, $qid, $index) {
            $errors[] = [
                'question_id' => $qid,
                'index' => $index,
                'field' => $field,
                'message' => $message,
            ];
        };

        if (trim((string) $question->prompt) === '') {
            $add('prompt', 'Prompt is required');
        }

        if ($type === TriviaQuestionTypeEnum::NUMBER) {
            if (!isset($config['correct']) || !is_numeric($config['correct'])) {
                $add('config.correct', 'Number answer is required');
            }
            if (
                !isset($config['min'], $config['max'])
                || !is_numeric($config['min'])
                || !is_numeric($config['max'])
                || (float) $config['max'] <= (float) $config['min']
            ) {
                $add('config', 'Number range is invalid');
            }

            return $errors;
        }

        if ($type === TriviaQuestionTypeEnum::DATE) {
            $correct = $config['correct'] ?? null;
            $valid = is_numeric($correct) && (
                ((int) $correct >= 10000101
                    && checkdate(intdiv((int) $correct % 10000, 100), (int) $correct % 100, intdiv((int) $correct, 10000)))
                || ((int) $correct >= 0 && (int) $correct < 1000000)
            );
            if (!$valid) {
                $add('config.correct', 'Date answer is required (YYYYMMDD)');
            }

            return $errors;
        }

        if ($type === TriviaQuestionTypeEnum::MAP) {
            foreach (['correct_x', 'correct_y'] as $key) {
                if (!isset($config[$key]) || !is_numeric($config[$key]) || (float) $config[$key] < 0 || (float) $config[$key] > 100) {
                    $add("config.{$key}", 'Map answer must be between 0 and 100');
                }
            }

            return $errors;
        }

        if ($type === TriviaQuestionTypeEnum::FILLBLANK) {
            if (!isset($config['answer']) || !is_scalar($config['answer']) || trim((string) $config['answer']) === '') {
                $add('config.answer', 'Answer is required');
            }

            return $errors;
        }

        if ($type === TriviaQuestionTypeEnum::RATE) {
            if (
                isset($config['min'], $config['max'])
                && (!is_numeric($config['min']) || !is_numeric($config['max']) || (float) $config['max'] <= (float) $config['min'])
            ) {
                $add('config', 'Rating range is invalid');
            }

            return $errors;
        }

        if (!TriviaQuestionTypeEnum::usesOptions($type)) {
            // cloud / finish: prompt only.
            return $errors;
        }

        $options = $question->options ?? collect();
        [$min_options, $max_options] = match ($type) {
            TriviaQuestionTypeEnum::TWOTRUTHS => [3, 6],
            TriviaQuestionTypeEnum::ORDER => [3, 6],
            TriviaQuestionTypeEnum::MATCH => [2, 4],
            default => [2, 6],
        };

        if ($options->count() < $min_options) {
            $add('options', "At least {$min_options} options are required");
        } elseif ($options->count() > $max_options) {
            $add('options', "At most {$max_options} options are allowed");
        }

        foreach ($options->values() as $option_index => $option) {
            if (trim((string) $option->label) === '') {
                $add("options.{$option_index}.label", 'Option label is required');
            }
        }

        if ($type === TriviaQuestionTypeEnum::MATCH) {
            $labels = $this->matchRightLabels($question);
            if (count($labels) < $options->count()) {
                $add('config.right_labels', 'A right-hand label is required for every item');
            }
            foreach ($labels as $label_index => $label) {
                if (trim($label) === '') {
                    $add("config.right_labels.{$label_index}", 'Right-hand label is required');
                }
            }

            $map = $this->matchCorrectMap($question);
            $valid_map = count($map) === $options->count();
            foreach ($map as $right_id) {
                if ($right_id < 0 || $right_id >= count($labels)) {
                    $valid_map = false;
                }
            }
            if (!$valid_map) {
                $add('config.correct_map', 'Every item needs a matching right-hand label');
            }
        }

        if (TriviaQuestionTypeEnum::requiresCorrectOption($type)) {
            $has_correct = $options->contains(fn ($option) => (bool) $option->is_correct);
            if (!$has_correct) {
                $add('options', 'A correct answer is required');
            }
        }

        return $errors;
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
