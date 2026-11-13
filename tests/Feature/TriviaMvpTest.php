<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TriviaAnswer;
use App\Models\TriviaGame;
use App\Models\TriviaPlayer;
use App\Models\TriviaQuestion;
use App\Models\User;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Enums\TriviaPaceEnum;
use App\Services\Enums\TriviaPhaseEnum;
use App\Services\Enums\TriviaStatusEnum;
use App\Services\Trivia\TriviaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class TriviaMvpTest extends TestCase
{
    use DatabaseTransactions;

    private TriviaService $service;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TriviaService();

        $this->user = new User();
        $this->user->role_id = RoleEnum::USER_ID;
        $this->user->first_name = 'Trivia';
        $this->user->last_name = 'Host';
        $this->user->email = 'trivia-mvp-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'trivia-mvp-' . Str::lower(Str::random(10));
        $this->event->name = 'Trivia MVP Event';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    public function test_host_paced_players_share_question_started_at(): void
    {
        $game = $this->seedChoiceGame(TriviaPaceEnum::HOST);
        $q = $game->questions()->first();

        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::QUESTION, (int) $q->id);

        $p1 = $this->makePlayer('Player One');
        $p2 = $this->makePlayer('Player Two');

        $first = $this->service->nextQuestion((int) $this->event->id, $p1->session_token);
        $second = $this->service->nextQuestion((int) $this->event->id, $p2->session_token);

        $this->assertFalse($first['waiting'] ?? false);
        $this->assertFalse($second['waiting'] ?? false);
        $this->assertSame($first['question']['started_at'], $second['question']['started_at']);
        $this->assertSame($first['question']['served_at'], $second['question']['served_at']);
        $this->assertSame($first['question']['id'], $second['question']['id']);

        $game->refresh();
        $this->assertNotNull($game->question_started_at);
        $this->assertSame(
            $game->question_started_at->toIso8601String(),
            $first['question']['started_at']
        );
    }

    public function test_screen_and_answer_do_not_leak_is_correct_before_reveal(): void
    {
        $game = $this->seedChoiceGame(TriviaPaceEnum::HOST);
        $q = $game->questions()->with('options')->first();
        $correct_id = (int) $q->options->firstWhere('is_correct', true)->id;

        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::QUESTION, (int) $q->id);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame(TriviaPhaseEnum::QUESTION, $screen['current_phase']);
        $this->assertNotNull($screen['question']);
        $this->assertArrayNotHasKey('is_correct', $screen['question']['options'][0]);
        $this->assertNull($screen['correct']);
        $this->assertNull($screen['fun_fact']);

        $player = $this->makePlayer('Guesser');
        $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        $answer = $this->service->submitAnswer(
            (int) $this->event->id,
            $player->session_token,
            (int) $q->id,
            [$correct_id]
        );

        $this->assertNull($answer['is_correct']);
        $this->assertNull($answer['correct_option_ids']);
        $this->assertTrue($answer['waiting_for_reveal']);

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);
        $reveal = $this->service->screenByPath($this->event->path);

        $this->assertSame(TriviaPhaseEnum::REVEAL, $reveal['current_phase']);
        $option_with_flag = collect($reveal['question']['options'])->firstWhere('id', $correct_id);
        $this->assertTrue((bool) $option_with_flag['is_correct']);
        $this->assertNotNull($reveal['correct']);
        $this->assertSame('רקע קצר לבדיקה', $reveal['fun_fact']);
    }

    public function test_patch_question_does_not_reset_scores(): void
    {
        $game = $this->seedChoiceGame(TriviaPaceEnum::FREE);
        $q = $game->questions()->first();

        $player = $this->makePlayer('Scorer');
        $player->score = 750;
        $player->save();

        TriviaAnswer::create([
            'player_id' => $player->id,
            'question_id' => $q->id,
            'selected_option_ids' => [],
            'is_correct' => true,
            'points_awarded' => 750,
            'elapsed_ms' => 1000,
            'served_at' => now()->subSeconds(2),
            'answered_at' => now()->subSecond(),
        ]);

        $this->service->patchQuestion((int) $this->event->id, (int) $this->user->id, (int) $q->id, [
            'prompt' => 'שאלה מעודכנת בלי איפוס',
            'fun_fact' => 'עודכן',
        ]);

        $player->refresh();
        $this->assertSame(750, (int) $player->score);
        $this->assertSame(1, TriviaAnswer::where('player_id', $player->id)->count());
        $this->assertSame('שאלה מעודכנת בלי איפוס', $q->fresh()->prompt);
    }

    public function test_free_pace_still_serves_next_unanswered_per_player(): void
    {
        $game = $this->seedChoiceGame(TriviaPaceEnum::FREE, 2);
        $questions = $game->questions()->with('options')->orderBy('sort_order')->get();

        $this->service->showNow((int) $this->event->id, (int) $this->user->id);

        $player = $this->makePlayer('Free Pace');
        $first = $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        $this->assertSame((int) $questions[0]->id, (int) $first['question']['id']);

        $correct = (int) $questions[0]->options->firstWhere('is_correct', true)->id;
        $result = $this->service->submitAnswer(
            (int) $this->event->id,
            $player->session_token,
            (int) $questions[0]->id,
            [$correct]
        );
        $this->assertTrue($result['is_correct']);
        $this->assertGreaterThan(0, $result['points_awarded']);

        $second = $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        $this->assertSame((int) $questions[1]->id, (int) $second['question']['id']);
    }

    public function test_poll_and_thisorthat_award_zero_points(): void
    {
        $game = $this->ensureGame(TriviaPaceEnum::FREE);
        $poll = $this->createQuestion($game, [
            'type' => 'poll',
            'prompt' => 'איזה שיר?',
            'max_points' => 0,
            'options' => [
                ['label' => 'א', 'is_correct' => false],
                ['label' => 'ב', 'is_correct' => false],
            ],
        ], 0);
        $tot = $this->createQuestion($game, [
            'type' => 'thisorthat',
            'prompt' => 'חוף או הרים?',
            'max_points' => 0,
            'options' => [
                ['label' => 'חוף', 'is_correct' => false],
                ['label' => 'הרים', 'is_correct' => false],
            ],
        ], 1);

        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $player = $this->makePlayer('Voter');

        $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        $poll_result = $this->service->submitAnswer(
            (int) $this->event->id,
            $player->session_token,
            (int) $poll->id,
            [(int) $poll->options()->first()->id]
        );
        $this->assertSame(0, (int) $poll_result['points_awarded']);
        $this->assertFalse((bool) $poll_result['is_correct']);

        $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        $tot_result = $this->service->submitAnswer(
            (int) $this->event->id,
            $player->session_token,
            (int) $tot->id,
            [(int) $tot->options()->first()->id]
        );
        $this->assertSame(0, (int) $tot_result['points_awarded']);
        $this->assertSame(0, (int) $player->fresh()->score);
    }

    public function test_number_closest_wins_full_points_on_reveal(): void
    {
        $game = $this->ensureGame(TriviaPaceEnum::HOST);
        $game->speed_bonus = false;
        $game->save();

        $question = $this->createQuestion($game, [
            'type' => 'number',
            'prompt' => 'כמה אורחים?',
            'max_points' => 1000,
            'config' => ['min' => 0, 'max' => 200, 'correct' => 100, 'unit' => ''],
            'options' => [],
        ], 0);

        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::QUESTION, (int) $question->id);

        $close = $this->makePlayer('Close');
        $far = $this->makePlayer('Far');
        $this->service->nextQuestion((int) $this->event->id, $close->session_token);
        $this->service->nextQuestion((int) $this->event->id, $far->session_token);

        $this->service->submitAnswer((int) $this->event->id, $close->session_token, (int) $question->id, [], 101);
        $this->service->submitAnswer((int) $this->event->id, $far->session_token, (int) $question->id, [], 150);

        $this->assertSame(0, (int) $close->fresh()->score);
        $this->assertSame(0, (int) $far->fresh()->score);

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $question->id);

        $this->assertSame(1000, (int) $close->fresh()->score);
        $this->assertGreaterThan(0, (int) $far->fresh()->score);
        $this->assertLessThan(1000, (int) $far->fresh()->score);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame(100, $screen['correct']['number']);
        $this->assertSame('Close', $screen['correct']['closest']['nickname']);
    }

    public function test_question_cap_blocks_41st_append(): void
    {
        $game = $this->ensureGame(TriviaPaceEnum::HOST);

        for ($i = 0; $i < TriviaService::MAX_QUESTIONS; $i++) {
            $this->createQuestion($game, [
                'type' => 'truefalse',
                'prompt' => "שאלה {$i}",
                'max_points' => 1000,
                'options' => [
                    ['label' => 'נכון', 'is_correct' => true],
                    ['label' => 'לא נכון', 'is_correct' => false],
                ],
            ], $i);
        }

        $this->expectException(\Exception::class);
        $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
            'type' => 'choice',
            'prompt' => 'עוד אחת',
            'time_limit_seconds' => 20,
            'max_points' => 1000,
            'options' => [
                ['label' => 'א', 'is_correct' => true],
                ['label' => 'ב', 'is_correct' => false],
            ],
        ]);
    }

    public function test_public_screen_endpoint_includes_mvp_fields(): void
    {
        $game = $this->seedChoiceGame(TriviaPaceEnum::HOST);
        $q = $game->questions()->first();
        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::QUESTION, (int) $q->id);

        $response = $this->getJson('/api/events/' . $this->event->path . '/trivia/screen');
        $response->assertOk();
        $response->assertJsonPath('data.theme', 'elegant');
        $response->assertJsonPath('data.pace', 'host');
        $response->assertJsonPath('data.current_phase', 'question');
        $response->assertJsonPath('data.question.type', 'choice');
        $this->assertArrayNotHasKey('is_correct', $response->json('data.question.options.0'));
    }

    public function test_from_template_and_reorder(): void
    {
        $admin = $this->service->applyTemplate((int) $this->event->id, (int) $this->user->id, 'birthday');
        $questions = collect($admin['questions']);
        $this->assertGreaterThanOrEqual(3, $questions->count());
        $this->assertSame('neon', $admin['theme']);

        $ids = $questions->pluck('id')->reverse()->values()->all();
        $reordered = $this->service->reorderQuestions((int) $this->event->id, (int) $this->user->id, $ids);
        $this->assertSame($ids[0], collect($reordered['questions'])->first()['id']);
    }

    private function ensureGame(string $pace): TriviaGame
    {
        $game = TriviaGame::firstOrNew(['event_id' => $this->event->id]);
        $game->enabled = true;
        $game->status = TriviaStatusEnum::DRAFT;
        $game->title = 'טריוויה בדיקה';
        $game->theme = 'elegant';
        $game->pace = $pace;
        $game->teams_mode = 'none';
        $game->speed_bonus = true;
        $game->reveal_seconds = 6;
        $game->default_time = 20;
        $game->default_points = 1000;
        $game->save();

        return $game;
    }

    private function seedChoiceGame(string $pace, int $count = 1): TriviaGame
    {
        $game = $this->ensureGame($pace);

        for ($i = 0; $i < $count; $i++) {
            $this->createQuestion($game, [
                'type' => 'choice',
                'prompt' => "שאלה {$i}",
                'fun_fact' => 'רקע קצר לבדיקה',
                'max_points' => 1000,
                'options' => [
                    ['label' => 'נכון', 'is_correct' => true],
                    ['label' => 'לא', 'is_correct' => false],
                    ['label' => 'אולי', 'is_correct' => false],
                    ['label' => 'ממש לא', 'is_correct' => false],
                ],
            ], $i);
        }

        return $game->fresh('questions.options');
    }

    private function createQuestion(TriviaGame $game, array $payload, int $index): TriviaQuestion
    {
        $question = TriviaQuestion::create([
            'event_id' => $game->event_id,
            'prompt' => $payload['prompt'],
            'type' => $payload['type'],
            'fun_fact' => $payload['fun_fact'] ?? null,
            'media_asset_id' => $payload['media_asset_id'] ?? null,
            'config' => $payload['config'] ?? null,
            'sort_order' => $index,
            'time_limit_seconds' => $payload['time_limit_seconds'] ?? 20,
            'max_points' => $payload['max_points'] ?? 1000,
        ]);

        foreach (array_values($payload['options'] ?? []) as $option_index => $option) {
            $question->options()->create([
                'label' => $option['label'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => $option_index,
            ]);
        }

        return $question->fresh('options');
    }

    private function makePlayer(string $nickname): TriviaPlayer
    {
        return TriviaPlayer::create([
            'event_id' => $this->event->id,
            'session_token' => Str::random(48),
            'nickname' => $nickname,
            'score' => 0,
        ]);
    }
}
