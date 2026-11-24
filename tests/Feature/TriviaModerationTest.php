<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TriviaAnswer;
use App\Models\TriviaGame;
use App\Models\TriviaPlayer;
use App\Models\TriviaQuestion;
use App\Models\TriviaReaction;
use App\Models\User;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\RoleEnum;
use App\Services\Enums\StatusEnum;
use App\Services\Enums\TriviaPaceEnum;
use App\Services\Enums\TriviaPhaseEnum;
use App\Services\Enums\TriviaStatusEnum;
use App\Services\Trivia\TriviaModerationService;
use App\Services\Trivia\TriviaService;
use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class TriviaModerationTest extends TestCase
{
    use DatabaseTransactions;

    private TriviaService $service;

    private TriviaModerationService $moderation;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TriviaService();
        $this->moderation = new TriviaModerationService();

        $this->user = new User();
        $this->user->role_id = RoleEnum::USER_ID;
        $this->user->first_name = 'Trivia';
        $this->user->last_name = 'Moderator';
        $this->user->email = 'trivia-mod-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'trivia-mod-' . Str::lower(Str::random(10));
        $this->event->name = 'Trivia Moderation Event';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    /* ---------------- profanity on the server ---------------- */

    public function test_join_rejects_offensive_nickname_and_masks_table_name(): void
    {
        $this->liveGame();

        try {
            $this->service->join($this->event->path, 'זונה');
            $this->fail('offensive nickname must be rejected');
        } catch (Exception $ex) {
            $this->assertSame(MessagesEnum::TRIVIA_NICKNAME_BLOCKED, $ex->getMessage());
            $this->assertSame(422, $ex->getCode());
        }

        $ok = $this->service->join($this->event->path, 'נועה');
        $this->assertSame('נועה', $ok['player']['nickname']);

        $game = TriviaGame::find($this->event->id);
        $game->teams_mode = 'tables';
        $game->save();
        $table = $this->service->join($this->event->path, 'דני', null, 'שולחן fuck');
        $this->assertSame('שולחן ****', $table['player']['team']);
    }

    public function test_join_http_returns_reason_for_blocked_nickname(): void
    {
        $this->liveGame();

        $response = $this->postJson("/api/events/{$this->event->path}/trivia/join", ['nickname' => 'f u c k']);
        $response->assertStatus(422);
        $response->assertJsonPath('data.reason', 'nickname_blocked');

        $hint = $this->postJson("/api/events/{$this->event->path}/trivia/text-check", ['text' => 'שרמוטה']);
        $hint->assertOk();
        $hint->assertJsonPath('data.flagged', true);
    }

    public function test_free_text_answer_is_masked_and_never_reaches_the_screen(): void
    {
        $cloud = $this->cloudQuestion();
        [$a, $b] = [$this->player('A'), $this->player('B')];

        $this->answer($a, $cloud, 'שמחה');
        $this->answer($b, $cloud, 'fuuuck you');

        $stored = TriviaAnswer::where('player_id', $b->id)->first();
        $this->assertTrue($stored->is_flagged);
        $this->assertNotNull($stored->hidden_at);
        $this->assertSame('****** you', $stored->value_text);

        $words = collect($this->screen()['tallies']['words'])->pluck('text')->all();
        $this->assertSame(['שמחה'], $words);
    }

    /* ---------------- hide an answer / word ---------------- */

    public function test_host_can_hide_and_show_a_word_on_the_screen(): void
    {
        $cloud = $this->cloudQuestion();
        $a = $this->player('A');
        $b = $this->player('B');
        $c = $this->player('C');
        $this->answer($a, $cloud, 'משעמם');
        $this->answer($b, $cloud, 'משעמם');
        $this->answer($c, $cloud, 'אהבה');

        $ids = TriviaAnswer::where('question_id', $cloud->id)->where('value_text', 'משעמם')->pluck('id')->all();
        $live = $this->moderation->hideAnswers((int) $this->event->id, (int) $this->user->id, $ids, true);

        $this->assertSame(['אהבה'], collect($this->screen()['tallies']['words'])->pluck('text')->all());
        $states = collect($live['question']['answers'])->pluck('state', 'id');
        $this->assertSame('hidden', $states[$ids[0]]);

        $this->moderation->hideAnswers((int) $this->event->id, (int) $this->user->id, $ids, false);
        $this->assertContains('משעמם', collect($this->screen()['tallies']['words'])->pluck('text')->all());
    }

    public function test_host_cannot_moderate_answers_of_another_event(): void
    {
        $cloud = $this->cloudQuestion();
        $a = $this->player('A');
        $this->answer($a, $cloud, 'שמחה');
        $answer_id = (int) TriviaAnswer::where('player_id', $a->id)->value('id');

        $other = new User();
        $other->role_id = RoleEnum::USER_ID;
        $other->first_name = 'Other';
        $other->last_name = 'Host';
        $other->email = 'trivia-mod-other-' . uniqid() . '@example.com';
        $other->password = 'Password1';
        $other->status = StatusEnum::ACTIVE;
        $other->save();

        $this->expectException(Exception::class);
        $this->moderation->hideAnswers((int) $this->event->id, (int) $other->id, [$answer_id], true);
    }

    /* ---------------- approve-before-showing queue ---------------- */

    public function test_approval_queue_holds_answers_until_approved(): void
    {
        $cloud = $this->cloudQuestion();
        $this->moderation->setQuestionApproval((int) $this->event->id, (int) $this->user->id, (int) $cloud->id, true);

        $a = $this->player('A');
        $b = $this->player('B');
        $this->answer($a, $cloud, 'מרגש');
        $this->answer($b, $cloud, 'מצחיק');

        $this->assertSame([], $this->screen()['tallies']['words']);
        $this->assertSame(2, $this->screen()['tallies']['total_answers']);

        $live = $this->moderation->live((int) $this->event->id, (int) $this->user->id);
        $this->assertTrue($live['question']['requires_approval']);
        $this->assertSame(['pending', 'pending'], collect($live['question']['answers'])->pluck('state')->all());

        $first = (int) TriviaAnswer::where('player_id', $a->id)->value('id');
        $second = (int) TriviaAnswer::where('player_id', $b->id)->value('id');
        $this->moderation->approveAnswers((int) $this->event->id, (int) $this->user->id, [$first], true);
        $this->moderation->approveAnswers((int) $this->event->id, (int) $this->user->id, [$second], false);

        $this->assertSame(['מרגש'], collect($this->screen()['tallies']['words'])->pluck('text')->all());
        $states = collect($this->moderation->live((int) $this->event->id, (int) $this->user->id)['question']['answers'])->pluck('state')->all();
        $this->assertSame(['visible', 'hidden'], $states);
    }

    public function test_turning_the_queue_on_mid_question_keeps_shown_answers(): void
    {
        $cloud = $this->cloudQuestion();
        $a = $this->player('A');
        $this->answer($a, $cloud, 'שמחה');
        $this->moderation->setQuestionApproval((int) $this->event->id, (int) $this->user->id, (int) $cloud->id, true);

        $b = $this->player('B');
        $this->answer($b, $cloud, 'חדש');

        $this->assertSame(['שמחה'], collect($this->screen()['tallies']['words'])->pluck('text')->all());
        $this->assertTrue((bool) TriviaQuestion::find($cloud->id)->requires_approval);
    }

    /* ---------------- kick / rejoin block / rename ---------------- */

    public function test_kicked_player_is_removed_everywhere_and_can_rejoin_without_block(): void
    {
        $this->liveGame();
        $joined = $this->service->join($this->event->path, 'בדיקה', null, null, 'device-aaaaaaaa');
        $player = TriviaPlayer::where('session_token', $joined['session_token'])->first();
        $this->player('Stays');

        $this->moderation->kickPlayer((int) $this->event->id, (int) $this->user->id, (int) $player->id, false);

        $screen = $this->screen();
        $this->assertSame(1, $screen['player_count']);
        $this->assertNotContains('בדיקה', collect($screen['joining'])->pluck('nickname')->all());

        try {
            $this->service->me((int) $this->event->id, $player->session_token);
            $this->fail('kicked player must lose the session');
        } catch (Exception $ex) {
            $this->assertSame(MessagesEnum::TRIVIA_PLAYER_REMOVED, $ex->getMessage());
            $this->assertSame(403, $ex->getCode());
        }

        $again = $this->service->join($this->event->path, 'בדיקה 2', null, null, 'device-aaaaaaaa', $player->session_token);
        $this->assertNotEmpty($again['session_token']);
    }

    public function test_kick_with_block_stops_rejoin_from_same_device_or_session(): void
    {
        $this->liveGame();
        $joined = $this->service->join($this->event->path, 'מטריד', null, null, 'device-bbbbbbbb');
        $player = TriviaPlayer::where('session_token', $joined['session_token'])->first();

        $live = $this->moderation->kickPlayer((int) $this->event->id, (int) $this->user->id, (int) $player->id, true);
        $row = collect($live['players'])->firstWhere('id', $player->id);
        $this->assertTrue($row['kicked']);
        $this->assertTrue($row['rejoin_blocked']);

        foreach ([['device-bbbbbbbb', null], [null, $player->session_token]] as [$device, $session]) {
            try {
                $this->service->join($this->event->path, 'שם חדש', null, null, $device, $session);
                $this->fail('blocked device/session must not rejoin');
            } catch (Exception $ex) {
                $this->assertSame(MessagesEnum::TRIVIA_REJOIN_BLOCKED, $ex->getMessage());
            }
        }

        // Another phone can still join.
        $this->assertNotEmpty($this->service->join($this->event->path, 'אורח אחר', null, null, 'device-cccccccc')['session_token']);

        // Host restores: the same device may join again.
        $this->moderation->restorePlayer((int) $this->event->id, (int) $this->user->id, (int) $player->id);
        $this->assertNotEmpty($this->service->join($this->event->path, 'חזר', null, null, 'device-bbbbbbbb')['session_token']);
    }

    public function test_host_can_rename_a_player_and_the_screen_shows_it(): void
    {
        $this->liveGame();
        $player = $this->player('שם ישן');

        $live = $this->moderation->renamePlayer((int) $this->event->id, (int) $this->user->id, (int) $player->id, '  אורח   נחמד ');
        $this->assertSame('אורח נחמד', collect($live['players'])->firstWhere('id', $player->id)['nickname']);
        $this->assertContains('אורח נחמד', collect($this->screen()['joining'])->pluck('nickname')->all());
        $this->assertSame('אורח נחמד', $this->service->me((int) $this->event->id, $player->session_token)['player']['nickname']);
    }

    public function test_host_rename_is_filtered_and_bad_ids_are_404(): void
    {
        $this->liveGame();
        $player = $this->player('שם ישן');

        try {
            $this->moderation->renamePlayer((int) $this->event->id, (int) $this->user->id, (int) $player->id, 'שרמוטה');
            $this->fail('offensive rename must be rejected');
        } catch (Exception $ex) {
            $this->assertSame(MessagesEnum::TRIVIA_NICKNAME_BLOCKED, $ex->getMessage());
        }
        $this->assertSame('שם ישן', $player->fresh()->nickname);

        $this->actingAs($this->user, 'api')
            ->postJson("/api/events/{$this->event->id}/trivia/admin/players/abc/kick", ['block' => false])
            ->assertNotFound();
    }

    /* ---------------- reactions ---------------- */

    public function test_reactions_reach_the_screen_through_a_cursor(): void
    {
        $this->liveGame();
        $player = $this->player('A');
        RateLimiter::clear("trivia-react:p:{$player->id}");
        RateLimiter::clear("trivia-react:e:{$this->event->id}");

        $first = $this->screen();
        $cursor = $first['reactions']['cursor'];
        $this->assertSame([], $first['reactions']['items']);

        $this->moderation->react((int) $this->event->id, $player->session_token, 'heart');
        $this->moderation->react((int) $this->event->id, $player->session_token, 'mazel');

        $next = $this->service->screenByPath($this->event->path, $cursor);
        $this->assertSame(['heart', 'mazel'], collect($next['reactions']['items'])->pluck('kind')->all());
        $this->assertSame([], $this->service->screenByPath($this->event->path, $next['reactions']['cursor'])['reactions']['items']);
    }

    public function test_reactions_are_rate_limited_per_player_and_per_event(): void
    {
        $this->liveGame();
        $player = $this->player('Spammer');
        RateLimiter::clear("trivia-react:p:{$player->id}");
        RateLimiter::clear("trivia-react:e:{$this->event->id}");

        for ($i = 0; $i < TriviaModerationService::PLAYER_REACTION_LIMIT; $i++) {
            $this->moderation->react((int) $this->event->id, $player->session_token, 'clap');
        }
        try {
            $this->moderation->react((int) $this->event->id, $player->session_token, 'clap');
            $this->fail('player limit must apply');
        } catch (Exception $ex) {
            $this->assertSame(429, $ex->getCode());
        }

        // Global cap: many different players.
        $sent = TriviaModerationService::PLAYER_REACTION_LIMIT;
        $blocked = 0;
        for ($i = 0; $i < 12; $i++) {
            $p = $this->player("P{$i}");
            RateLimiter::clear("trivia-react:p:{$p->id}");
            for ($k = 0; $k < 5; $k++) {
                try {
                    $this->moderation->react((int) $this->event->id, $p->session_token, 'party');
                    $sent++;
                } catch (Exception $ex) {
                    $this->assertSame(429, $ex->getCode());
                    $blocked++;
                }
            }
        }
        $this->assertSame(TriviaModerationService::EVENT_REACTION_LIMIT, $sent);
        $this->assertGreaterThan(0, $blocked);
        $this->assertSame($sent, TriviaReaction::where('event_id', $this->event->id)->count());
    }

    public function test_host_can_turn_reactions_off_and_http_rate_limit_returns_429(): void
    {
        $this->liveGame();
        $player = $this->player('A');
        RateLimiter::clear("trivia-react:p:{$player->id}");
        RateLimiter::clear("trivia-react:e:{$this->event->id}");
        $headers = ['X-Trivia-Session' => $player->session_token];

        $this->postJson("/api/events/{$this->event->path}/trivia/react", ['kind' => 'heart'], $headers)->assertOk();
        $this->postJson("/api/events/{$this->event->path}/trivia/react", ['kind' => 'poop'], $headers)->assertStatus(422);

        $live = $this->moderation->updateFx((int) $this->event->id, (int) $this->user->id, [
            'reactions_enabled' => false,
            'sound_enabled' => false,
            'sound_volume' => 35,
        ]);
        $this->assertFalse($live['fx']['reactions_enabled']);
        $this->assertSame(['sound_enabled' => false, 'sound_volume' => 35, 'reactions_enabled' => false], $this->screen()['fx']);

        $off = $this->postJson("/api/events/{$this->event->path}/trivia/react", ['kind' => 'heart'], $headers);
        $off->assertStatus(403);
        $off->assertJsonPath('data.reason', 'reactions_off');

        $this->moderation->updateFx((int) $this->event->id, (int) $this->user->id, ['reactions_enabled' => true]);
        $codes = [];
        for ($i = 0; $i < TriviaModerationService::PLAYER_REACTION_LIMIT + 1; $i++) {
            $codes[] = $this->postJson("/api/events/{$this->event->path}/trivia/react", ['kind' => 'clap'], $headers)->status();
        }
        $this->assertContains(429, $codes);
    }

    public function test_kicked_player_cannot_react(): void
    {
        $this->liveGame();
        $player = $this->player('A');
        $this->moderation->kickPlayer((int) $this->event->id, (int) $this->user->id, (int) $player->id, false);

        $response = $this->postJson(
            "/api/events/{$this->event->path}/trivia/react",
            ['kind' => 'heart'],
            ['X-Trivia-Session' => $player->session_token]
        );
        $response->assertStatus(403);
        $response->assertJsonPath('data.reason', 'removed');
    }

    /* ---------------- helpers ---------------- */

    private function liveGame(): TriviaGame
    {
        $game = TriviaGame::firstOrNew(['event_id' => $this->event->id]);
        $game->enabled = true;
        $game->status = TriviaStatusEnum::LIVE;
        $game->current_phase = TriviaPhaseEnum::LOBBY;
        $game->title = 'טריוויה בדיקה';
        $game->theme = 'elegant';
        $game->pace = TriviaPaceEnum::HOST;
        $game->teams_mode = 'none';
        $game->speed_bonus = true;
        $game->reveal_seconds = 6;
        $game->default_time = 20;
        $game->default_points = 1000;
        $game->save();

        return $game;
    }

    private function cloudQuestion(): TriviaQuestion
    {
        $this->liveGame();
        $game = TriviaGame::find($this->event->id);
        $game->status = TriviaStatusEnum::DRAFT;
        $game->save();

        $admin = $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
            'type' => 'cloud',
            'prompt' => 'מילה אחת על האירוע',
            'max_points' => 0,
            'time_limit_seconds' => 20,
        ]);
        $id = (int) collect($admin['questions'])->last()['id'];
        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::QUESTION, $id);

        return TriviaQuestion::findOrFail($id);
    }

    private function player(string $nickname): TriviaPlayer
    {
        return TriviaPlayer::create([
            'event_id' => $this->event->id,
            'session_token' => Str::random(48),
            'nickname' => $nickname,
            'score' => 0,
        ]);
    }

    private function answer(TriviaPlayer $player, TriviaQuestion $question, string $text): void
    {
        $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        $this->service->submitAnswer((int) $this->event->id, $player->session_token, (int) $question->id, [], null, $text);
    }

    private function screen(): array
    {
        return $this->service->screenByPath($this->event->path);
    }
}
