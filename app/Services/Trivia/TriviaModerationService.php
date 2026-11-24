<?php

namespace App\Services\Trivia;

use App\Models\TriviaAnswer;
use App\Models\TriviaGame;
use App\Models\TriviaPlayer;
use App\Models\TriviaQuestion;
use App\Models\TriviaReaction;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\TriviaQuestionTypeEnum;
use App\Services\Enums\TriviaStatusEnum;
use App\Services\Events\EventService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Live trivia moderation (kick / rejoin block / rename / hide / approve queue),
 * live-screen sound settings and guest reactions.
 */
class TriviaModerationService
{
    public const REACTION_KINDS = ['heart', 'party', 'laugh', 'clap', 'mazel'];

    /** Per player: at most N reactions per window. */
    public const PLAYER_REACTION_LIMIT = 5;

    public const PLAYER_REACTION_WINDOW = 10;

    /** Per event (all guests together). */
    public const EVENT_REACTION_LIMIT = 40;

    public const EVENT_REACTION_WINDOW = 5;

    /** The screen only fetches reactions younger than this. */
    private const SCREEN_REACTION_MAX_AGE = 20;

    private const SCREEN_REACTION_BATCH = 30;

    public function __construct(
        private ?EventService $event_service = null,
    ) {
        $this->event_service ??= new EventService();
    }

    /* ------------------------------------------------------------------ *
     * Payload helpers used by TriviaService
     * ------------------------------------------------------------------ */

    public static function fxPayload(?TriviaGame $game): array
    {
        return [
            'sound_enabled' => (bool) ($game?->sound_enabled ?? true),
            'sound_volume' => (int) ($game?->sound_volume ?? 70),
            'reactions_enabled' => (bool) ($game?->reactions_enabled ?? true),
        ];
    }

    /**
     * Reactions newer than $after for the live screen. With no cursor the screen
     * only receives the current cursor (no backlog burst when it opens).
     */
    public static function screenReactions(TriviaGame $game, ?int $after): array
    {
        $latest = (int) (TriviaReaction::where('event_id', $game->event_id)->max('id') ?? 0);

        if ($after === null || $after > $latest || !($game->reactions_enabled ?? true)) {
            return ['cursor' => $latest, 'items' => []];
        }

        $items = TriviaReaction::where('event_id', $game->event_id)
            ->where('id', '>', $after)
            ->where('created_at', '>=', now()->subSeconds(self::SCREEN_REACTION_MAX_AGE))
            ->orderByDesc('id')
            ->limit(self::SCREEN_REACTION_BATCH)
            ->get(['id', 'kind'])
            ->sortBy('id')
            ->values();

        return [
            'cursor' => $latest,
            'items' => $items->map(fn (TriviaReaction $reaction) => [
                'id' => (int) $reaction->id,
                'kind' => $reaction->kind,
            ])->all(),
        ];
    }

    /** Whether a free-text answer may appear on the big screen. */
    public static function answerVisible(TriviaAnswer $answer, TriviaQuestion $question): bool
    {
        // Filtered answers are masked and stay off the screen for good.
        if ($answer->hidden_at !== null || $answer->is_flagged) {
            return false;
        }

        if ($question->requires_approval) {
            return $answer->approved_at !== null;
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     * Host (authenticated)
     * ------------------------------------------------------------------ */

    public function live(int $event_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);
        $filter = new ProfanityFilter();

        $players = TriviaPlayer::where('event_id', $event_id)
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (TriviaPlayer $player) => [
                'id' => (int) $player->id,
                'nickname' => $player->nickname,
                'team' => $player->team,
                'score' => (int) $player->score,
                'kicked' => (bool) $player->kicked_at,
                'rejoin_blocked' => (bool) $player->rejoin_blocked,
                'nickname_flagged' => !$filter->isClean($player->nickname),
                'joined_at' => $player->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $question = $game->current_question_id
            ? TriviaQuestion::where('event_id', $event_id)->where('id', $game->current_question_id)->first()
            : null;

        return [
            'fx' => self::fxPayload($game),
            'current_phase' => $game->current_phase,
            'players' => $players,
            'question' => $question ? $this->questionModeration($question) : null,
            'free_text_questions' => TriviaQuestion::where('event_id', $event_id)
                ->whereIn('type', TriviaQuestionTypeEnum::freeText())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'prompt', 'type', 'requires_approval'])
                ->map(fn (TriviaQuestion $q) => [
                    'id' => (int) $q->id,
                    'prompt' => $q->prompt,
                    'type' => $q->type,
                    'requires_approval' => (bool) $q->requires_approval,
                ])
                ->values()
                ->all(),
            'reactions_last_minute' => TriviaReaction::where('event_id', $event_id)
                ->where('created_at', '>=', now()->subMinute())
                ->count(),
        ];
    }

    public function kickPlayer(int $event_id, int $user_id, int $player_id, bool $block): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $player = $this->playerForHost($event_id, $player_id);
        $player->kicked_at = $player->kicked_at ?? now();
        $player->rejoin_blocked = $block;
        $player->save();

        return $this->live($event_id, $user_id);
    }

    public function restorePlayer(int $event_id, int $user_id, int $player_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $player = $this->playerForHost($event_id, $player_id);
        $player->kicked_at = null;
        $player->rejoin_blocked = false;
        $player->save();

        return $this->live($event_id, $user_id);
    }

    public function renamePlayer(int $event_id, int $user_id, int $player_id, string $nickname): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $player = $this->playerForHost($event_id, $player_id);
        $nickname = mb_substr(trim(preg_replace('/\s+/u', ' ', $nickname) ?? ''), 0, 40);
        // The new name goes on the big screen, so it passes the same filter as guest nicknames.
        if (mb_strlen($nickname) < 2 || !(new ProfanityFilter())->isClean($nickname)) {
            throw new Exception(MessagesEnum::TRIVIA_NICKNAME_BLOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $player->nickname = $nickname;
        $player->save();

        return $this->live($event_id, $user_id);
    }

    /** Hide (or show again) answers on the big screen. */
    public function hideAnswers(int $event_id, int $user_id, array $answer_ids, bool $hidden): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $this->answersForHost($event_id, $answer_ids)->each(function (TriviaAnswer $answer) use ($hidden) {
            $answer->hidden_at = $hidden ? ($answer->hidden_at ?? now()) : null;
            if (!$hidden) {
                // Showing a queued answer again also approves it.
                $answer->approved_at = $answer->approved_at ?? now();
            }
            $answer->save();
        });

        return $this->live($event_id, $user_id);
    }

    /** Approve queued answers (approved=false rejects them: hidden, never shown). */
    public function approveAnswers(int $event_id, int $user_id, array $answer_ids, bool $approved): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $this->answersForHost($event_id, $answer_ids)->each(function (TriviaAnswer $answer) use ($approved) {
            if ($approved) {
                $answer->approved_at = now();
                if (!$answer->is_flagged) {
                    $answer->hidden_at = null;
                }
            } else {
                $answer->approved_at = null;
                $answer->hidden_at = $answer->hidden_at ?? now();
            }
            $answer->save();
        });

        return $this->live($event_id, $user_id);
    }

    public function setQuestionApproval(int $event_id, int $user_id, int $question_id, bool $required): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $question = TriviaQuestion::where('event_id', $event_id)->where('id', $question_id)->first();
        if (!$question) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }
        if (!in_array($question->type, TriviaQuestionTypeEnum::freeText(), true)) {
            throw new Exception(MessagesEnum::TRIVIA_INVALID_TYPE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($required && !$question->requires_approval) {
            // Turning the queue on mid-question: what is already on screen stays approved.
            TriviaAnswer::where('question_id', $question->id)
                ->whereNotNull('answered_at')
                ->whereNull('hidden_at')
                ->whereNull('approved_at')
                ->where('is_flagged', false)
                ->update(['approved_at' => now()]);
        }

        $question->requires_approval = $required;
        $question->save();

        return $this->live($event_id, $user_id);
    }

    public function updateFx(int $event_id, int $user_id, array $data): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $game = $this->gameOrFail($event_id);
        if (array_key_exists('sound_enabled', $data)) {
            $game->sound_enabled = (bool) $data['sound_enabled'];
        }
        if (array_key_exists('sound_volume', $data)) {
            $game->sound_volume = max(0, min(100, (int) $data['sound_volume']));
        }
        if (array_key_exists('reactions_enabled', $data)) {
            $game->reactions_enabled = (bool) $data['reactions_enabled'];
        }
        $game->save();

        return $this->live($event_id, $user_id);
    }

    /* ------------------------------------------------------------------ *
     * Guests (trivia session)
     * ------------------------------------------------------------------ */

    public function react(int $event_id, string $session_token, string $kind): array
    {
        if (!in_array($kind, self::REACTION_KINDS, true)) {
            throw new Exception(MessagesEnum::TRIVIA_INVALID_TYPE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $player = TriviaPlayer::where('event_id', $event_id)->where('session_token', $session_token)->first();
        if (!$player) {
            throw new Exception(MessagesEnum::TRIVIA_PLAYER_NOT_FOUND, Response::HTTP_UNAUTHORIZED);
        }
        if ($player->kicked_at) {
            throw new Exception(MessagesEnum::TRIVIA_PLAYER_REMOVED, Response::HTTP_FORBIDDEN);
        }

        $game = $this->gameOrFail($event_id);
        if (!$game->enabled || !in_array((int) $game->status, [TriviaStatusEnum::LIVE, TriviaStatusEnum::RESULTS], true)) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_LIVE, Response::HTTP_FORBIDDEN);
        }
        if (!($game->reactions_enabled ?? true)) {
            throw new Exception(MessagesEnum::TRIVIA_REACTIONS_OFF, Response::HTTP_FORBIDDEN);
        }

        $player_key = "trivia-react:p:{$player->id}";
        $event_key = "trivia-react:e:{$event_id}";
        if (
            RateLimiter::tooManyAttempts($player_key, self::PLAYER_REACTION_LIMIT)
            || RateLimiter::tooManyAttempts($event_key, self::EVENT_REACTION_LIMIT)
        ) {
            throw new Exception(MessagesEnum::TRIVIA_REACTION_RATE_LIMITED, Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($player_key, self::PLAYER_REACTION_WINDOW);
        RateLimiter::hit($event_key, self::EVENT_REACTION_WINDOW);

        $reaction = TriviaReaction::create([
            'event_id' => $event_id,
            'player_id' => $player->id,
            'kind' => $kind,
        ]);

        // Keep the table small: reactions are only useful for a few seconds.
        if ($reaction->id % 50 === 0) {
            TriviaReaction::where('event_id', $event_id)
                ->where('created_at', '<', now()->subMinutes(10))
                ->delete();
        }

        return ['id' => (int) $reaction->id, 'kind' => $kind];
    }

    /** Client-side hint: would this text be blocked / masked? */
    public function checkText(?string $text): array
    {
        $result = (new ProfanityFilter())->check(mb_substr((string) $text, 0, 200));

        return ['flagged' => $result['flagged'], 'masked' => $result['masked']];
    }

    /* ------------------------------------------------------------------ */

    private function questionModeration(TriviaQuestion $question): array
    {
        $payload = [
            'id' => (int) $question->id,
            'type' => $question->type,
            'prompt' => $question->prompt,
            'free_text' => in_array($question->type, TriviaQuestionTypeEnum::freeText(), true),
            'requires_approval' => (bool) $question->requires_approval,
            'answers' => [],
        ];

        if (!$payload['free_text']) {
            return $payload;
        }

        $payload['answers'] = TriviaAnswer::with('player:id,nickname,kicked_at')
            ->where('question_id', $question->id)
            ->whereNotNull('answered_at')
            ->orderBy('answered_at')
            ->limit(500)
            ->get()
            ->map(function (TriviaAnswer $answer) use ($question) {
                $state = 'visible';
                if ($answer->is_flagged) {
                    $state = 'filtered';
                } elseif ($answer->hidden_at) {
                    $state = 'hidden';
                } elseif ($question->requires_approval && !$answer->approved_at) {
                    $state = 'pending';
                }

                return [
                    'id' => (int) $answer->id,
                    'text' => $answer->value_text,
                    'player' => $answer->player?->nickname,
                    'player_kicked' => (bool) $answer->player?->kicked_at,
                    'state' => $state,
                ];
            })
            ->values()
            ->all();

        return $payload;
    }

    private function gameOrFail(int $event_id): TriviaGame
    {
        $game = TriviaGame::where('event_id', $event_id)->first();
        if (!$game) {
            throw new Exception(MessagesEnum::TRIVIA_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return $game;
    }

    private function playerForHost(int $event_id, int $player_id): TriviaPlayer
    {
        $player = TriviaPlayer::where('event_id', $event_id)->where('id', $player_id)->first();
        if (!$player) {
            throw new Exception(MessagesEnum::TRIVIA_PLAYER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return $player;
    }

    private function answersForHost(int $event_id, array $answer_ids)
    {
        $ids = array_values(array_unique(array_map('intval', $answer_ids)));

        return TriviaAnswer::whereIn('id', $ids)
            ->whereHas('question', fn ($query) => $query->where('event_id', $event_id))
            ->get();
    }
}
