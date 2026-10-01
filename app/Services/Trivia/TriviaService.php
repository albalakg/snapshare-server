<?php

namespace App\Services\Trivia;

use App\Models\Event;
use App\Models\TriviaAnswer;
use App\Models\TriviaGame;
use App\Models\TriviaPlayer;
use App\Models\TriviaQuestion;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\TriviaStatusEnum;
use App\Services\Events\EventService;
use App\Services\Helpers\FileService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TriviaService
{
    public function __construct(
        private ?EventService $event_service = null,
    ) {
        $this->event_service ??= new EventService();
    }

    public function publicScreen(Event $event, string $event_path): array
    {
        $game = TriviaGame::where('event_id', $event->id)->first();
        $phase = $this->phase($game);

        return [
            'phase' => $phase,
            'title' => $game?->title,
            'player_count' => $phase === 'photos' || !$game
                ? 0
                : TriviaPlayer::where('event_id', $event->id)->count(),
            'join_path' => '/event/trivia/' . $event_path,
            'top' => $phase === 'photos' ? [] : $this->topPlayers((int) $event->id, 3),
        ];
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

        $starts_at = !empty($data['starts_at']) ? $data['starts_at'] : null;
        $results_at = !empty($data['results_at']) ? $data['results_at'] : null;

        if ($starts_at && $results_at && strtotime($results_at) <= strtotime($starts_at)) {
            throw new Exception(MessagesEnum::TRIVIA_RESULTS_AFTER_START, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $game->enabled = (bool) $data['enabled'];
        $game->title = trim((string) ($data['title'] ?? '')) ?: 'טריוויה';
        $game->starts_at = $starts_at;
        $game->results_at = $results_at;

        if (!$game->enabled) {
            $game->status = TriviaStatusEnum::DRAFT;
        } elseif (!$was_on_screen) {
            $game->status = ($game->starts_at && $game->starts_at->isFuture())
                ? TriviaStatusEnum::SCHEDULED
                : TriviaStatusEnum::DRAFT;
        }

        $game->save();

        return $this->formatAdmin($game->load('questions.options'), $event);
    }

    public function replaceQuestions(int $event_id, int $user_id, array $questions): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = TriviaGame::firstOrNew(['event_id' => $event_id]);

        if ($game->exists && in_array((int) $game->status, [TriviaStatusEnum::LIVE, TriviaStatusEnum::RESULTS], true)) {
            throw new Exception(MessagesEnum::TRIVIA_QUESTIONS_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($questions as $question) {
            $correct = collect($question['options'])->contains(fn ($option) => !empty($option['is_correct']));
            if (!$correct) {
                throw new Exception(MessagesEnum::TRIVIA_OPTION_CORRECT_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (!$game->exists) {
            $game->enabled = false;
            $game->status = TriviaStatusEnum::DRAFT;
            $game->title = $game->title ?: 'טריוויה';
            $game->save();
        }

        DB::transaction(function () use ($game, $questions) {
            TriviaQuestion::where('event_id', $game->event_id)->delete();
            TriviaPlayer::where('event_id', $game->event_id)->update([
                'score' => 0,
                'finished_at' => null,
            ]);

            foreach (array_values($questions) as $index => $question) {
                $created = TriviaQuestion::create([
                    'event_id' => $game->event_id,
                    'prompt' => trim($question['prompt']),
                    'sort_order' => $index,
                    'time_limit_seconds' => (int) $question['time_limit_seconds'],
                    'max_points' => (int) $question['max_points'],
                ]);

                foreach (array_values($question['options']) as $option_index => $option) {
                    $created->options()->create([
                        'label' => trim($option['label']),
                        'is_correct' => (bool) $option['is_correct'],
                        'sort_order' => $option_index,
                    ]);
                }
            }
        });

        return $this->formatAdmin($game->fresh('questions.options'), $event);
    }

    public function showNow(int $event_id, int $user_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);
        $this->assertHasQuestions($game);

        $game->enabled = true;
        $game->status = TriviaStatusEnum::LIVE;
        $game->starts_at = $game->starts_at ?? now();
        $game->save();

        return $this->formatAdmin($game->load('questions.options'), $event);
    }

    public function showResults(int $event_id, int $user_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);

        if (!in_array((int) $game->status, [TriviaStatusEnum::LIVE, TriviaStatusEnum::RESULTS], true)) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_LIVE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $game->enabled = true;
        $game->status = TriviaStatusEnum::RESULTS;
        $game->results_at = $game->results_at ?? now();
        $game->save();

        return $this->formatAdmin($game->load('questions.options'), $event);
    }

    public function backToPhotos(int $event_id, int $user_id): array
    {
        $event = $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);

        $game->status = ($game->enabled && $game->starts_at && $game->starts_at->isFuture())
            ? TriviaStatusEnum::SCHEDULED
            : TriviaStatusEnum::DRAFT;
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

    public function join(string $event_path, string $nickname, $image = null): array
    {
        $event = $this->findEventByPath($event_path);
        $game = $this->liveGameOrFail((int) $event->id);

        $player = new TriviaPlayer([
            'event_id' => $game->event_id,
            'session_token' => Str::random(48),
            'nickname' => trim($nickname),
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
        ];
    }

    public function me(int $event_id, string $session_token): array
    {
        $player = $this->playerOrFail($event_id, $session_token);
        $game = TriviaGame::where('event_id', $event_id)->first();
        $total = TriviaQuestion::where('event_id', $event_id)->count();

        return [
            'phase' => $this->phase($game),
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
        $questions = $game->questions()->with('options')->get();

        if ($this->phase($game) === 'results') {
            return $this->finishedPayload($player, $questions->count());
        }

        if ($this->phase($game) !== 'live') {
            throw new Exception(MessagesEnum::TRIVIA_NOT_LIVE, Response::HTTP_FORBIDDEN);
        }

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

    public function submitAnswer(int $event_id, string $session_token, int $question_id, array $option_ids): array
    {
        $player = $this->playerOrFail($event_id, $session_token);
        $game = $this->liveGameOrFail($event_id);
        $question = TriviaQuestion::with('options')
            ->where('event_id', $event_id)
            ->where('id', $question_id)
            ->first();

        if (!$question) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return DB::transaction(function () use ($player, $game, $question, $option_ids) {
            $answer = TriviaAnswer::where('player_id', $player->id)
                ->where('question_id', $question->id)
                ->lockForUpdate()
                ->first();

            if (!$answer) {
                throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($answer->answered_at) {
                $player->refresh();

                return $this->answerResult($player, $answer, $game, $question);
            }

            $valid_ids = $question->options->pluck('id')->map(fn ($id) => (int) $id)->all();
            $selected = collect($option_ids)->map(fn ($id) => (int) $id)->unique()->sort()->values();

            if ($selected->diff($valid_ids)->isNotEmpty()) {
                throw new Exception(MessagesEnum::TRIVIA_ANSWER_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $correct = $question->options
                ->where('is_correct', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values();

            $elapsed = max(0, (int) round(now()->getTimestampMs() - $answer->served_at->getTimestampMs()));
            $exact = $correct->all() === $selected->all() && $correct->isNotEmpty();
            $points = $this->pointsFor($exact, $elapsed, (int) $question->time_limit_seconds, (int) $question->max_points);

            $answer->selected_option_ids = $selected->all();
            $answer->elapsed_ms = $elapsed;
            $answer->is_correct = $points > 0;
            $answer->points_awarded = $points;
            $answer->answered_at = now();
            $answer->save();

            $player->score = (int) TriviaAnswer::where('player_id', $player->id)->sum('points_awarded');

            $question_ids = TriviaQuestion::where('event_id', $game->event_id)->pluck('id');
            $answered_ids = TriviaAnswer::where('player_id', $player->id)
                ->whereNotNull('answered_at')
                ->pluck('question_id');

            if ($question_ids->diff($answered_ids)->isEmpty()) {
                $player->finished_at = $player->finished_at ?? now();
            }

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
                'updated_at' => $now,
            ]);

        TriviaGame::where('enabled', true)
            ->where('status', TriviaStatusEnum::LIVE)
            ->whereNotNull('results_at')
            ->where('results_at', '<=', $now)
            ->update([
                'status' => TriviaStatusEnum::RESULTS,
                'updated_at' => $now,
            ]);
    }

    public function pointsFor(bool $exact_match, int $elapsed_ms, int $time_limit_seconds, int $max_points): int
    {
        $limit_ms = max(1, $time_limit_seconds) * 1000;
        if (!$exact_match || $elapsed_ms > $limit_ms) {
            return 0;
        }

        $remaining_ratio = 1 - ($elapsed_ms / $limit_ms);

        return (int) round($max_points * (0.5 + (0.5 * $remaining_ratio)));
    }

    private function answerResult(TriviaPlayer $player, TriviaAnswer $answer, TriviaGame $game, TriviaQuestion $question): array
    {
        $total = TriviaQuestion::where('event_id', $game->event_id)->count();
        $correct_option_ids = $question->options
            ->where('is_correct', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'is_correct' => (bool) $answer->is_correct,
            'points_awarded' => (int) $answer->points_awarded,
            'score' => (int) $player->score,
            'correct_option_ids' => $correct_option_ids,
            'elapsed_ms' => (int) $answer->elapsed_ms,
            'finished' => (bool) $player->finished_at,
            'rank' => $this->rankOf($player),
            'correct_count' => TriviaAnswer::where('player_id', $player->id)->where('is_correct', true)->count(),
            'total_questions' => $total,
        ];
    }

    private function questionPayload(TriviaQuestion $question, TriviaAnswer $answer, int $index, int $total): array
    {
        $correct_count = $question->options->where('is_correct', true)->count();

        return [
            'finished' => false,
            'server_now' => now()->toIso8601String(),
            'question' => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'index' => $index + 1,
                'total' => $total,
                'time_limit_seconds' => (int) $question->time_limit_seconds,
                'max_points' => (int) $question->max_points,
                'served_at' => $answer->served_at->toIso8601String(),
                'selection_mode' => $correct_count > 1 ? 'multiple' : 'single',
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                ])->values(),
            ],
        ];
    }

    private function finishedPayload(TriviaPlayer $player, int $total): array
    {
        return [
            'finished' => true,
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
                'score' => (int) $player->score,
            ])
            ->all();
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

    private function phase(?TriviaGame $game): string
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

    private function formatAdmin(TriviaGame $game, Event $event): array
    {
        return [
            'event_id' => (int) $game->event_id,
            'enabled' => (bool) $game->enabled,
            'status' => TriviaStatusEnum::name((int) $game->status),
            'title' => $game->title,
            'starts_at' => $game->starts_at?->toIso8601String(),
            'results_at' => $game->results_at?->toIso8601String(),
            'join_path' => '/event/trivia/' . $event->path,
            'questions' => $game->questions->map(fn (TriviaQuestion $question) => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'sort_order' => (int) $question->sort_order,
                'time_limit_seconds' => (int) $question->time_limit_seconds,
                'max_points' => (int) $question->max_points,
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'is_correct' => (bool) $option->is_correct,
                    'sort_order' => (int) $option->sort_order,
                ])->values(),
            ])->values(),
        ];
    }

    private function emptyAdmin(Event $event): array
    {
        return [
            'event_id' => (int) $event->id,
            'enabled' => false,
            'status' => 'draft',
            'title' => 'טריוויה',
            'starts_at' => null,
            'results_at' => null,
            'join_path' => '/event/trivia/' . $event->path,
            'questions' => [],
        ];
    }

    private function playerPayload(TriviaPlayer $player): array
    {
        return [
            'nickname' => $player->nickname,
            'avatar_url' => $this->avatarUrl($player->avatar_path),
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

        if ($this->phase($game) !== 'live') {
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
