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
use App\Services\Enums\TriviaQuestionTypeEnum;
use App\Services\Enums\TriviaStatusEnum;
use App\Services\Trivia\TriviaIncompleteQuestionsException;
use App\Services\Trivia\TriviaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TriviaV4TypesTest extends TestCase
{
    use DatabaseTransactions;

    private const NEW_TYPES = [
        'buzzer', 'tugofwar', 'blur', 'spotdiff', 'video', 'cloud', 'rate',
        'finish', 'date', 'order', 'map', 'match', 'fillblank', 'emoji', 'audio',
    ];

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
        $this->user->email = 'trivia-v4-' . uniqid() . '@example.com';
        $this->user->password = 'Password1';
        $this->user->status = StatusEnum::ACTIVE;
        $this->user->save();

        $this->event = new Event();
        $this->event->user_id = $this->user->id;
        $this->event->path = 'trivia-v4-' . Str::lower(Str::random(10));
        $this->event->name = 'Trivia V4 Event';
        $this->event->status = StatusEnum::ACTIVE;
        $this->event->save();
    }

    public function test_playable_types_cover_every_new_type_and_keep_mvp(): void
    {
        $playable = TriviaQuestionTypeEnum::playable();
        foreach (self::NEW_TYPES as $type) {
            $this->assertContains($type, $playable);
        }
        foreach (TriviaQuestionTypeEnum::mvp() as $type) {
            $this->assertContains($type, $playable);
        }
        $this->assertCount(25, array_unique($playable));

        foreach (['poll', 'thisorthat', 'tugofwar', 'cloud', 'rate', 'finish'] as $type) {
            $this->assertContains($type, TriviaQuestionTypeEnum::unscored());
        }
    }

    public function test_draft_create_and_patch_each_new_type(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);
        $expected_options = [
            'buzzer' => 4, 'tugofwar' => 2, 'blur' => 4, 'spotdiff' => 4, 'video' => 4,
            'cloud' => 0, 'rate' => 0, 'finish' => 0, 'date' => 0, 'order' => 3,
            'map' => 0, 'match' => 3, 'fillblank' => 0, 'emoji' => 4, 'audio' => 4,
        ];

        foreach (self::NEW_TYPES as $type) {
            $created = $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
                'type' => $type,
                'prompt' => '',
            ]);
            $question = collect($created['questions'])->last();

            $this->assertSame($type, $question['type'], "type {$type}");
            $this->assertSame('', $question['prompt']);
            $this->assertCount($expected_options[$type], $question['options'], "options for {$type}");

            $patched = $this->service->patchQuestion(
                (int) $this->event->id,
                (int) $this->user->id,
                (int) $question['id'],
                ['prompt' => "שאלה {$type}"]
            );
            $updated = collect($patched['questions'])->firstWhere('id', $question['id']);
            $this->assertSame("שאלה {$type}", $updated['prompt']);
            $this->assertSame($type, $updated['type']);
        }

        $this->assertSame(count(self::NEW_TYPES), TriviaQuestion::where('event_id', $this->event->id)->count());
    }

    public function test_draft_create_and_patch_new_types_through_http(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);
        $base = '/api/events/' . $this->event->id . '/trivia/admin/questions';

        $create = $this->actingAs($this->user, 'api')->postJson($base, [
            'type' => 'fillblank',
            'prompt' => 'בירת צרפת היא ___',
            'config' => ['answer' => 'פריז'],
            'time_limit_seconds' => 20,
            'max_points' => 800,
        ]);
        $create->assertSuccessful();
        $question = collect($create->json('data.questions'))->last();
        $this->assertSame('fillblank', $question['type']);
        $this->assertSame('פריז', $question['config']['answer']);
        $this->assertSame([], $question['options']);

        $patch = $this->actingAs($this->user, 'api')->patchJson($base . '/' . $question['id'], [
            'type' => 'rate',
        ]);
        $patch->assertSuccessful();
        $patched = collect($patch->json('data.questions'))->firstWhere('id', $question['id']);
        $this->assertSame('rate', $patched['type']);
        $this->assertSame(0, (int) $patched['max_points']);
        $this->assertSame(1, (int) $patched['config']['min']);
        $this->assertSame(10, (int) $patched['config']['max']);

        $bad = $this->actingAs($this->user, 'api')->postJson($base, ['type' => 'nonsense']);
        $bad->assertStatus(422);
    }

    public function test_match_pairs_are_expanded_into_options_and_map(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);
        $created = $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
            'type' => 'match',
            'prompt' => 'חברו בין הזוגות',
            'config' => ['pairs' => [
                ['left' => 'עמית', 'right' => 'חתן'],
                ['left' => 'גל', 'right' => 'שושבין'],
            ]],
        ]);
        $question = collect($created['questions'])->last();

        $this->assertSame(['עמית', 'גל'], collect($question['options'])->pluck('label')->all());
        $this->assertSame(['חתן', 'שושבין'], $question['config']['right_labels']);
        $this->assertSame([0 => 0, 1 => 1], $question['config']['correct_map']);

        $this->expectException(\Exception::class);
        $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
            'type' => 'match',
            'prompt' => 'יותר מדי',
            'options' => array_fill(0, 5, ['label' => 'x', 'is_correct' => false]),
        ]);
    }

    public function test_go_live_accepts_complete_new_type_questions(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);

        $choice = [
            ['label' => 'א', 'is_correct' => true],
            ['label' => 'ב', 'is_correct' => false],
        ];
        $payloads = [
            ['type' => 'buzzer', 'options' => $choice],
            ['type' => 'tugofwar', 'options' => $choice],
            ['type' => 'blur', 'options' => $choice],
            ['type' => 'spotdiff', 'options' => $choice],
            ['type' => 'video', 'options' => $choice],
            ['type' => 'emoji', 'options' => $choice],
            ['type' => 'audio', 'options' => $choice],
            ['type' => 'cloud'],
            ['type' => 'rate'],
            ['type' => 'finish', 'config' => ['prefix' => 'הדבר הכי מצחיק ש...']],
            ['type' => 'date', 'config' => ['correct' => 20260610]],
            ['type' => 'order', 'options' => [
                ['label' => '1'], ['label' => '2'], ['label' => '3'],
            ]],
            ['type' => 'map', 'config' => ['correct_x' => 30, 'correct_y' => 70]],
            ['type' => 'match', 'config' => ['pairs' => [
                ['left' => 'a', 'right' => 'A'], ['left' => 'b', 'right' => 'B'],
            ]]],
            ['type' => 'fillblank', 'config' => ['answer' => 'שלום']],
        ];

        foreach ($payloads as $payload) {
            $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, $payload + ['prompt' => 'שאלה ' . $payload['type']]);
        }

        $live = $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $this->assertSame('live', $live['status']);
        $this->assertCount(count($payloads), $live['questions']);
    }

    public function test_go_live_rejects_incomplete_new_type_questions(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);

        $drafts = [
            'fillblank' => [['type' => 'fillblank', 'prompt' => 'q'], 'config.answer'],
            'map' => [['type' => 'map', 'prompt' => 'q', 'config' => ['correct_x' => 150, 'correct_y' => 20]], 'config.correct_x'],
            'date' => [['type' => 'date', 'prompt' => 'q', 'config' => ['correct' => 20261340]], 'config.correct'],
            'order' => [['type' => 'order', 'prompt' => 'q', 'options' => [['label' => 'a'], ['label' => 'b']]], 'options'],
            'match' => [['type' => 'match', 'prompt' => 'q'], 'options.0.label'],
            'buzzer' => [['type' => 'buzzer', 'prompt' => 'q', 'options' => [
                ['label' => 'a', 'is_correct' => false], ['label' => 'b', 'is_correct' => false],
            ]], 'options'],
            'cloud' => [['type' => 'cloud', 'prompt' => ''], 'prompt'],
        ];

        foreach ($drafts as [$payload]) {
            $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, $payload);
        }

        try {
            $this->service->showNow((int) $this->event->id, (int) $this->user->id);
            $this->fail('Expected TriviaIncompleteQuestionsException');
        } catch (TriviaIncompleteQuestionsException $ex) {
            $by_index = collect($ex->errors)->groupBy('index');
            $i = 0;
            foreach ($drafts as $type => [, $field]) {
                $this->assertTrue(
                    collect($by_index->get($i, []))->contains(fn ($error) => $error['field'] === $field),
                    "expected {$field} error for {$type}"
                );
                $i++;
            }
        }
    }

    public function test_fillblank_is_case_trim_and_niqqud_insensitive_and_hides_answer(): void
    {
        $game = $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion([
            'type' => 'fillblank',
            'prompt' => 'בירת צרפת',
            'config' => ['answer' => 'Paris', 'alternates' => ['שלום']],
        ]);
        $this->startQuestion($q);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertArrayNotHasKey('answer', $screen['question']['config'] ?? []);
        $this->assertArrayNotHasKey('alternates', $screen['question']['config'] ?? []);

        $right = $this->makePlayer('Right');
        $niqqud = $this->makePlayer('Niqqud');
        $wrong = $this->makePlayer('Wrong');

        $guest = $this->service->nextQuestion((int) $this->event->id, $right->session_token);
        $this->assertSame('text', $guest['question']['selection_mode']);
        $this->assertArrayNotHasKey('answer', $guest['question']['config'] ?? []);
        $this->service->nextQuestion((int) $this->event->id, $niqqud->session_token);
        $this->service->nextQuestion((int) $this->event->id, $wrong->session_token);

        $this->service->submitAnswer((int) $this->event->id, $right->session_token, (int) $q->id, [], null, '  pARIS ');
        $this->service->submitAnswer((int) $this->event->id, $niqqud->session_token, (int) $q->id, [], null, 'שָׁלוֹם');
        $this->service->submitAnswer((int) $this->event->id, $wrong->session_token, (int) $q->id, [], null, 'London');

        $this->assertSame(1000, (int) $right->fresh()->score);
        $this->assertSame(1000, (int) $niqqud->fresh()->score);
        $this->assertSame(0, (int) $wrong->fresh()->score);
        $this->assertSame('London', TriviaAnswer::where('player_id', $wrong->id)->first()->value_text);

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);
        $reveal = $this->service->screenByPath($this->event->path);
        $this->assertSame('Paris', $reveal['question']['config']['answer']);
        $this->assertSame('Paris', $reveal['correct']['answer']);

        $this->expectException(\Exception::class);
        $this->service->submitAnswer((int) $this->event->id, $this->makePlayer('Late')->session_token, (int) $q->id, [], null, 'x');
    }

    public function test_date_closest_wins(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion([
            'type' => 'date',
            'prompt' => 'מתי התחתנו?',
            'config' => ['min' => 20260101, 'max' => 20261231, 'correct' => 20260610],
        ]);
        $this->startQuestion($q);

        $close = $this->makePlayer('Close');
        $far = $this->makePlayer('Far');
        $this->service->nextQuestion((int) $this->event->id, $close->session_token);
        $this->service->nextQuestion((int) $this->event->id, $far->session_token);

        $this->service->submitAnswer((int) $this->event->id, $close->session_token, (int) $q->id, [], 20260612);
        $this->service->submitAnswer((int) $this->event->id, $far->session_token, (int) $q->id, [], 20260710);
        $this->assertSame(0, (int) $close->fresh()->score);

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);

        $this->assertSame(1000, (int) $close->fresh()->score);
        // 30 days off a 364-day span: 1000 * (1 - 30/364) ~= 918
        $this->assertEqualsWithDelta(918, (int) $far->fresh()->score, 2);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame(20260610, (int) $screen['correct']['date']);
        $this->assertSame('Close', $screen['correct']['closest']['nickname']);
        $this->assertSame('date', $screen['tallies']['mode']);
    }

    public function test_date_rejects_invalid_guess(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion(['type' => 'date', 'prompt' => 'q', 'config' => ['correct' => 20260610]]);
        $this->startQuestion($q);

        $player = $this->makePlayer('Bad');
        $this->service->nextQuestion((int) $this->event->id, $player->session_token);

        $this->expectException(\Exception::class);
        $this->service->submitAnswer((int) $this->event->id, $player->session_token, (int) $q->id, [], 20261345);
    }

    public function test_order_requires_exact_sequence_and_hides_correct_order(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion([
            'type' => 'order',
            'prompt' => 'סדרו לפי הזמן',
            'options' => [
                ['label' => 'ראשון'], ['label' => 'שני'], ['label' => 'שלישי'], ['label' => 'רביעי'],
            ],
        ]);
        $this->startQuestion($q);
        $correct = $q->options()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $screen = $this->service->screenByPath($this->event->path);
        $shown = collect($screen['question']['options'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame('order', $screen['question']['selection_mode']);
        $this->assertNotSame($correct, $shown, 'order options must be shuffled before reveal');
        $this->assertEqualsCanonicalizing($correct, $shown);

        $right = $this->makePlayer('Right');
        $wrong = $this->makePlayer('Wrong');
        $partial = $this->makePlayer('Partial');

        $guest = $this->service->nextQuestion((int) $this->event->id, $right->session_token);
        $this->assertNotSame($correct, collect($guest['question']['options'])->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->service->nextQuestion((int) $this->event->id, $wrong->session_token);
        $this->service->nextQuestion((int) $this->event->id, $partial->session_token);

        $this->service->submitAnswer((int) $this->event->id, $right->session_token, (int) $q->id, $correct);
        $this->service->submitAnswer((int) $this->event->id, $wrong->session_token, (int) $q->id, array_reverse($correct));

        $this->assertSame(1000, (int) $right->fresh()->score);
        $this->assertSame(0, (int) $wrong->fresh()->score);
        $this->assertSame($correct, TriviaAnswer::where('player_id', $right->id)->first()->selected_option_ids);
        $this->assertSame(array_reverse($correct), TriviaAnswer::where('player_id', $wrong->id)->first()->selected_option_ids);

        try {
            $this->service->submitAnswer((int) $this->event->id, $partial->session_token, (int) $q->id, array_slice($correct, 0, 2));
            $this->fail('partial order should be rejected');
        } catch (\Exception $ex) {
            $this->assertSame(0, (int) $partial->fresh()->score);
        }

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);
        $reveal = $this->service->screenByPath($this->event->path);
        $this->assertSame($correct, collect($reveal['question']['options'])->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($correct, $reveal['correct']['option_ids']);
    }

    public function test_rate_is_unscored_and_reports_average(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);
        $q = $this->addQuestion(['type' => 'rate', 'prompt' => 'כמה אהבתם?']);
        $this->assertSame(0, (int) $q->max_points);
        $this->startQuestion($q);

        $a = $this->makePlayer('A');
        $b = $this->makePlayer('B');
        $c = $this->makePlayer('C');
        foreach ([$a, $b, $c] as $player) {
            $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        }

        $result = $this->service->submitAnswer((int) $this->event->id, $a->session_token, (int) $q->id, [], 7);
        $this->assertSame(0, (int) $result['score']);
        $this->assertTrue((bool) $result['waiting_for_reveal']);
        $this->service->submitAnswer((int) $this->event->id, $b->session_token, (int) $q->id, [], 9);

        try {
            $this->service->submitAnswer((int) $this->event->id, $c->session_token, (int) $q->id, [], 11);
            $this->fail('out-of-range rating should be rejected');
        } catch (\Exception $ex) {
            $this->assertNull(TriviaAnswer::where('player_id', $c->id)->first()->answered_at);
        }

        $this->assertSame(0, (int) $a->fresh()->score);
        $this->assertSame(7.0, (float) TriviaAnswer::where('player_id', $a->id)->first()->value_number);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame('rate', $screen['tallies']['mode']);
        $this->assertSame(2, $screen['tallies']['total_answers']);
        $this->assertEquals(8.0, $screen['tallies']['average']);
        $this->assertCount(10, $screen['tallies']['distribution']);
    }

    public function test_cloud_and_finish_store_value_text_and_tally_words(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);
        $cloud = $this->addQuestion(['type' => 'cloud', 'prompt' => 'מילה אחת על האירוע']);
        $finish = $this->addQuestion([
            'type' => 'finish',
            'prompt' => 'השלימו',
            'config' => ['prefix' => 'הדבר הכי מצחיק ש...'],
        ]);
        $this->startQuestion($cloud);

        $a = $this->makePlayer('A');
        $b = $this->makePlayer('B');
        $c = $this->makePlayer('C');
        foreach ([$a, $b, $c] as $player) {
            $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        }

        $this->service->submitAnswer((int) $this->event->id, $a->session_token, (int) $cloud->id, [], null, 'שמחה');
        $this->service->submitAnswer((int) $this->event->id, $b->session_token, (int) $cloud->id, [], null, ' שמחה ');
        $this->service->submitAnswer((int) $this->event->id, $c->session_token, (int) $cloud->id, [], null, 'אהבה');

        $this->assertSame('שמחה', TriviaAnswer::where('player_id', $a->id)->first()->value_text);
        $this->assertSame(0, (int) $a->fresh()->score);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame('words', $screen['tallies']['mode']);
        $this->assertSame('שמחה', $screen['tallies']['words'][0]['text']);
        $this->assertSame(2, $screen['tallies']['words'][0]['count']);
        $this->assertSame('אהבה', $screen['tallies']['words'][1]['text']);

        $empty = $this->makePlayer('Empty');
        $this->service->nextQuestion((int) $this->event->id, $empty->session_token);
        try {
            $this->service->submitAnswer((int) $this->event->id, $empty->session_token, (int) $cloud->id, [], null, '   ');
            $this->fail('blank text should be rejected');
        } catch (\Exception $ex) {
            $this->assertTrue(true);
        }

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::QUESTION, (int) $finish->id);
        $this->service->nextQuestion((int) $this->event->id, $a->session_token);
        $this->service->submitAnswer((int) $this->event->id, $a->session_token, (int) $finish->id, [], null, 'קרה בחתונה');

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame('הדבר הכי מצחיק ש...', $screen['question']['config']['prefix']);
        $this->assertSame('words', $screen['tallies']['mode']);
        $this->assertSame('קרה בחתונה', $screen['tallies']['words'][0]['text']);
    }

    public function test_buzzer_is_winner_take_all_on_reveal(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion([
            'type' => 'buzzer',
            'prompt' => 'מי ראשון?',
            'options' => [
                ['label' => 'נכון', 'is_correct' => true],
                ['label' => 'לא', 'is_correct' => false],
            ],
        ]);
        $this->startQuestion($q);
        $correct = (int) $q->options()->where('is_correct', true)->value('id');
        $wrong = (int) $q->options()->where('is_correct', false)->value('id');

        $slow = $this->makePlayer('Slow');
        $fast = $this->makePlayer('Fast');
        $miss = $this->makePlayer('Miss');
        foreach ([$slow, $fast, $miss] as $player) {
            $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        }

        $this->service->submitAnswer((int) $this->event->id, $slow->session_token, (int) $q->id, [$correct]);
        $this->service->submitAnswer((int) $this->event->id, $fast->session_token, (int) $q->id, [$correct]);
        $this->service->submitAnswer((int) $this->event->id, $miss->session_token, (int) $q->id, [$wrong]);

        TriviaAnswer::where('player_id', $slow->id)->update(['elapsed_ms' => 4000]);
        TriviaAnswer::where('player_id', $fast->id)->update(['elapsed_ms' => 1500]);
        TriviaAnswer::where('player_id', $miss->id)->update(['elapsed_ms' => 500]);

        foreach ([$slow, $fast, $miss] as $player) {
            $this->assertSame(0, (int) $player->fresh()->score, 'no points before reveal');
        }

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);

        $this->assertSame(1000, (int) $fast->fresh()->score);
        $this->assertSame(0, (int) $slow->fresh()->score);
        $this->assertSame(0, (int) $miss->fresh()->score);

        $slow_result = $this->service->submitAnswer((int) $this->event->id, $slow->session_token, (int) $q->id, [$correct]);
        $this->assertSame(0, (int) $slow_result['points_awarded']);
        $this->assertFalse($slow_result['is_correct']);
        $fast_result = $this->service->submitAnswer((int) $this->event->id, $fast->session_token, (int) $q->id, [$correct]);
        $this->assertSame(1000, (int) $fast_result['points_awarded']);
        $this->assertTrue($fast_result['is_correct']);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame('Fast', $screen['correct']['winner']['nickname']);

        // Re-running the reveal must not change the outcome.
        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);
        $this->assertSame(1000, (int) $fast->fresh()->score);
        $this->assertSame(0, (int) $slow->fresh()->score);
    }

    public function test_map_scores_by_distance_and_hides_coordinates_until_reveal(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion([
            'type' => 'map',
            'prompt' => 'איפה הכרנו?',
            'config' => ['correct_x' => 50, 'correct_y' => 50],
        ]);
        $this->startQuestion($q);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertArrayNotHasKey('correct_x', $screen['question']['config'] ?? []);
        $this->assertArrayNotHasKey('correct_y', $screen['question']['config'] ?? []);

        $near = $this->makePlayer('Near');
        $far = $this->makePlayer('Far');
        $this->service->nextQuestion((int) $this->event->id, $near->session_token);
        $this->service->nextQuestion((int) $this->event->id, $far->session_token);

        $this->service->submitAnswer((int) $this->event->id, $near->session_token, (int) $q->id, [], null, null, ['x' => 52, 'y' => 50]);
        $this->service->submitAnswer((int) $this->event->id, $far->session_token, (int) $q->id, [], null, null, ['x' => 90, 'y' => 10]);
        $this->assertSame(0, (int) $near->fresh()->score);

        $bad = $this->makePlayer('Bad');
        $this->service->nextQuestion((int) $this->event->id, $bad->session_token);
        try {
            $this->service->submitAnswer((int) $this->event->id, $bad->session_token, (int) $q->id, [], null, null, ['x' => 101, 'y' => 5]);
            $this->fail('out-of-range map guess should be rejected');
        } catch (\Exception $ex) {
            $this->assertNull(TriviaAnswer::where('player_id', $bad->id)->first()->answered_at);
        }

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);

        $this->assertSame(1000, (int) $near->fresh()->score);
        // distance = sqrt(40^2 + 40^2) = 56.57 of span 141.42 -> 1000 * 0.6
        $this->assertEqualsWithDelta(600, (int) $far->fresh()->score, 1);

        $reveal = $this->service->screenByPath($this->event->path);
        $this->assertEquals(50, $reveal['correct']['x']);
        $this->assertEquals(50, $reveal['question']['config']['correct_x']);
        $this->assertSame('map', $reveal['tallies']['mode']);
        $this->assertCount(2, $reveal['tallies']['guesses']);
        $this->assertSame('Near', $reveal['correct']['closest']['nickname']);
    }

    public function test_match_requires_every_pair_correct(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion([
            'type' => 'match',
            'prompt' => 'חברו',
            'config' => ['pairs' => [
                ['left' => 'ישראל', 'right' => 'ירושלים'],
                ['left' => 'צרפת', 'right' => 'פריז'],
                ['left' => 'איטליה', 'right' => 'רומא'],
            ]],
        ]);
        $this->startQuestion($q);
        $left = $q->options()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $screen = $this->service->screenByPath($this->event->path);
        $config = $screen['question']['config'];
        $this->assertSame('match', $screen['question']['selection_mode']);
        $this->assertArrayNotHasKey('correct_map', $config);
        $this->assertArrayNotHasKey('pairs', $config);
        $this->assertArrayNotHasKey('right_labels', $config);
        $this->assertCount(3, $config['right_items']);
        $this->assertNotSame([0, 1, 2], array_column($config['right_items'], 'id'), 'right side shuffled');
        $this->assertEqualsCanonicalizing(['ירושלים', 'פריז', 'רומא'], array_column($config['right_items'], 'label'));

        $right = $this->makePlayer('Right');
        $wrong = $this->makePlayer('Wrong');
        $this->service->nextQuestion((int) $this->event->id, $right->session_token);
        $this->service->nextQuestion((int) $this->event->id, $wrong->session_token);

        $this->service->submitAnswer((int) $this->event->id, $right->session_token, (int) $q->id, [], null, null, [
            'matches' => [
                ['left_id' => $left[0], 'right_id' => 0],
                ['left_id' => $left[1], 'right_id' => 1],
                ['left_id' => $left[2], 'right_id' => 2],
            ],
        ]);
        $this->service->submitAnswer((int) $this->event->id, $wrong->session_token, (int) $q->id, [], null, null, [
            'matches' => [
                ['left_id' => $left[0], 'right_id' => 1],
                ['left_id' => $left[1], 'right_id' => 0],
                ['left_id' => $left[2], 'right_id' => 2],
            ],
        ]);

        $this->assertSame(1000, (int) $right->fresh()->score);
        $this->assertSame(0, (int) $wrong->fresh()->score);
        $this->assertCount(3, TriviaAnswer::where('player_id', $right->id)->first()->value_json['matches']);

        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::REVEAL, (int) $q->id);
        $reveal = $this->service->screenByPath($this->event->path);
        $this->assertCount(3, $reveal['question']['config']['correct_pairs']);
        $this->assertSame($left[0], $reveal['question']['config']['correct_pairs'][0]['left_id']);
        $this->assertSame(0, $reveal['question']['config']['correct_pairs'][0]['right_id']);
    }

    public function test_match_accepts_plain_map_and_rejects_bad_ids(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $q = $this->addQuestion([
            'type' => 'match',
            'prompt' => 'חברו',
            'config' => ['pairs' => [['left' => 'a', 'right' => 'A'], ['left' => 'b', 'right' => 'B']]],
        ]);
        $this->startQuestion($q);
        $left = $q->options()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $good = $this->makePlayer('Good');
        $bad = $this->makePlayer('Bad');
        $this->service->nextQuestion((int) $this->event->id, $good->session_token);
        $this->service->nextQuestion((int) $this->event->id, $bad->session_token);

        $this->service->submitAnswer((int) $this->event->id, $good->session_token, (int) $q->id, [], null, null, [
            (string) $left[0] => 0,
            (string) $left[1] => 1,
        ]);
        $this->assertSame(1000, (int) $good->fresh()->score);

        try {
            $this->service->submitAnswer((int) $this->event->id, $bad->session_token, (int) $q->id, [], null, null, [
                'matches' => [['left_id' => 999999, 'right_id' => 0]],
            ]);
            $this->fail('unknown left id should be rejected');
        } catch (\Exception $ex) {
            $this->assertSame(0, (int) $bad->fresh()->score);
        }
    }

    public function test_tugofwar_is_unscored_with_tallies(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);
        $q = $this->addQuestion([
            'type' => 'tugofwar',
            'prompt' => 'חוף או הרים?',
            'max_points' => 1000,
        ]);
        $this->assertSame(0, (int) $q->max_points);
        $this->startQuestion($q);
        [$left, $right] = $q->options()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $a = $this->makePlayer('A');
        $b = $this->makePlayer('B');
        $c = $this->makePlayer('C');
        foreach ([$a, $b, $c] as $player) {
            $this->service->nextQuestion((int) $this->event->id, $player->session_token);
        }

        $result = $this->service->submitAnswer((int) $this->event->id, $a->session_token, (int) $q->id, [$left]);
        $this->assertTrue((bool) $result['waiting_for_reveal']);
        $this->service->submitAnswer((int) $this->event->id, $b->session_token, (int) $q->id, [$left]);
        $this->service->submitAnswer((int) $this->event->id, $c->session_token, (int) $q->id, [$right]);

        foreach ([$a, $b, $c] as $player) {
            $this->assertSame(0, (int) $player->fresh()->score);
        }
        $this->assertFalse((bool) TriviaAnswer::where('player_id', $a->id)->first()->is_correct);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame('options', $screen['tallies']['mode']);
        $this->assertSame(3, $screen['tallies']['total_answers']);
        $counts = collect($screen['tallies']['options'])->pluck('count', 'option_id');
        $this->assertSame(2, $counts[$left]);
        $this->assertSame(1, $counts[$right]);
    }

    public function test_media_payload_reports_kind_for_audio_video_and_image(): void
    {
        $game = $this->ensureGame(TriviaPaceEnum::HOST);
        $cases = [
            ['audio', 'clips/song.mp3', 2, 'audio'],
            ['video', 'clips/party.mp4', 1, 'video'],
            ['blur', 'pics/face.jpg', 2, 'image'],
        ];

        foreach ($cases as [$type, $path, $asset_type, $kind]) {
            $asset_id = DB::table('event_assets')->insertGetId([
                'event_id' => $this->event->id,
                'asset_type' => $asset_type,
                'path' => $path,
                'status' => StatusEnum::ACTIVE,
                'user_agent' => 'test',
                'ip' => '127.0.0.1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $admin = $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
                'type' => $type,
                'prompt' => 'מדיה',
                'media_asset_id' => $asset_id,
                'options' => [
                    ['label' => 'א', 'is_correct' => true],
                    ['label' => 'ב', 'is_correct' => false],
                ],
            ]);
            $question = collect($admin['questions'])->last();
            $this->assertSame($asset_id, $question['media']['id']);
            $this->assertSame($kind, $question['media']['kind'], "kind for {$type}");
            $this->assertStringEndsWith($path, $question['media']['url']);
        }
    }

    public function test_cinema_theme_and_other_new_themes_save(): void
    {
        foreach (['cinema', 'elegant', 'neon', 'arcade', 'playful', 'festival', 'corporate'] as $theme) {
            $response = $this->actingAs($this->user, 'api')->putJson(
                '/api/events/' . $this->event->id . '/trivia/admin',
                ['enabled' => false, 'title' => 'טריוויה', 'theme' => $theme]
            );
            $response->assertSuccessful();
            $response->assertJsonPath('data.theme', $theme);
            $this->assertSame($theme, TriviaGame::where('event_id', $this->event->id)->value('theme'));
        }

        $invalid = $this->actingAs($this->user, 'api')->putJson(
            '/api/events/' . $this->event->id . '/trivia/admin',
            ['enabled' => false, 'title' => 'טריוויה', 'theme' => 'vaporwave']
        );
        $invalid->assertStatus(422);

        $this->actingAs($this->user, 'api')->putJson(
            '/api/events/' . $this->event->id . '/trivia/admin',
            ['enabled' => false, 'title' => 'טריוויה', 'theme' => 'cinema']
        )->assertSuccessful();

        $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
            'type' => 'choice',
            'prompt' => 'בדיקה',
            'options' => [['label' => 'א', 'is_correct' => true], ['label' => 'ב', 'is_correct' => false]],
        ]);
        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $screen = $this->getJson('/api/events/' . $this->event->path . '/trivia/screen');
        $screen->assertOk();
        $screen->assertJsonPath('data.theme', 'cinema');
    }

    public function test_teams_mode_tables_accepts_free_form_table_names(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST);

        $saved = $this->actingAs($this->user, 'api')->putJson(
            '/api/events/' . $this->event->id . '/trivia/admin',
            ['enabled' => true, 'title' => 'טריוויה', 'teams_mode' => 'tables']
        );
        $saved->assertSuccessful();
        $saved->assertJsonPath('data.teams_mode', 'tables');

        $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
            'type' => 'choice',
            'prompt' => 'בדיקה',
            'options' => [['label' => 'א', 'is_correct' => true], ['label' => 'ב', 'is_correct' => false]],
        ]);
        $this->service->showNow((int) $this->event->id, (int) $this->user->id);

        $join = $this->postJson('/api/events/' . $this->event->path . '/trivia/join', [
            'nickname' => 'שולחן שלוש',
            'team' => '3',
        ]);
        $join->assertSuccessful();
        $join->assertJsonPath('data.player.team', '3');
        $join->assertJsonPath('data.teams_mode', 'tables');

        $missing = $this->postJson('/api/events/' . $this->event->path . '/trivia/join', [
            'nickname' => 'בלי שולחן',
        ]);
        $missing->assertStatus(422);

        $too_long = $this->postJson('/api/events/' . $this->event->path . '/trivia/join', [
            'nickname' => 'שולחן ארוך',
            'team' => str_repeat('ש', 41),
        ]);
        $too_long->assertStatus(422);

        $named = $this->service->join($this->event->path, 'Guest Four', null, 'שולחן הכלה');
        $this->assertSame('שולחן הכלה', $named['player']['team']);
        $this->service->join($this->event->path, 'Guest Five', null, '3');

        TriviaPlayer::where('event_id', $this->event->id)->where('nickname', 'שולחן שלוש')->update(['score' => 400]);
        TriviaPlayer::where('event_id', $this->event->id)->where('nickname', 'Guest Five')->update(['score' => 250]);
        TriviaPlayer::where('event_id', $this->event->id)->where('nickname', 'Guest Four')->update(['score' => 100]);

        $screen = $this->service->screenByPath($this->event->path);
        $this->assertSame('tables', $screen['teams']['mode']);
        $this->assertSame(
            [
                ['team' => '3', 'score' => 650, 'players' => 2],
                ['team' => 'שולחן הכלה', 'score' => 100, 'players' => 1],
            ],
            $screen['teams']['tables']
        );
    }

    public function test_sides_mode_still_requires_a_or_b(): void
    {
        $game = $this->ensureGame(TriviaPaceEnum::HOST);
        $game->teams_mode = 'sides';
        $game->save();
        $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, [
            'type' => 'choice',
            'prompt' => 'בדיקה',
            'options' => [['label' => 'א', 'is_correct' => true], ['label' => 'ב', 'is_correct' => false]],
        ]);
        $this->service->showNow((int) $this->event->id, (int) $this->user->id);

        $this->expectException(\Exception::class);
        $this->service->join($this->event->path, 'Wrong Side', null, '3');
    }

    public function test_answer_endpoint_accepts_value_text_and_value_json(): void
    {
        $this->ensureGame(TriviaPaceEnum::HOST, false);
        $map = $this->addQuestion([
            'type' => 'map',
            'prompt' => 'איפה?',
            'config' => ['correct_x' => 10, 'correct_y' => 10],
        ]);
        $this->startQuestion($map);
        $player = $this->makePlayer('Http');
        $this->service->nextQuestion((int) $this->event->id, $player->session_token);

        $response = $this->postJson(
            '/api/events/' . $this->event->path . '/trivia/answer',
            ['question_id' => $map->id, 'value_json' => ['x' => 12.5, 'y' => 9]],
            ['X-Trivia-Session' => $player->session_token]
        );
        $response->assertSuccessful();

        $stored = TriviaAnswer::where('player_id', $player->id)->first();
        $this->assertEquals(12.5, $stored->value_json['x']);
        $this->assertEquals(9, $stored->value_json['y']);

        $text = $this->postJson(
            '/api/events/' . $this->event->path . '/trivia/answer',
            ['question_id' => $map->id, 'value_text' => str_repeat('x', 201)],
            ['X-Trivia-Session' => $player->session_token]
        );
        $text->assertStatus(422);
    }

    private function ensureGame(string $pace, bool $speed_bonus = true): TriviaGame
    {
        $game = TriviaGame::firstOrNew(['event_id' => $this->event->id]);
        $game->enabled = true;
        $game->status = TriviaStatusEnum::DRAFT;
        $game->title = 'טריוויה בדיקה';
        $game->theme = 'elegant';
        $game->pace = $pace;
        $game->teams_mode = 'none';
        $game->speed_bonus = $speed_bonus;
        $game->reveal_seconds = 6;
        $game->default_time = 20;
        $game->default_points = 1000;
        $game->save();

        return $game;
    }

    /**
     * Append through the service (exercises normalisation) and return the model with options.
     */
    private function addQuestion(array $payload): TriviaQuestion
    {
        $admin = $this->service->appendQuestion((int) $this->event->id, (int) $this->user->id, $payload + [
            'max_points' => 1000,
            'time_limit_seconds' => 20,
        ]);
        $id = (int) collect($admin['questions'])->last()['id'];

        return TriviaQuestion::with('options')->findOrFail($id);
    }

    private function startQuestion(TriviaQuestion $question): void
    {
        $this->service->showNow((int) $this->event->id, (int) $this->user->id);
        $this->service->setPhase((int) $this->event->id, (int) $this->user->id, TriviaPhaseEnum::QUESTION, (int) $question->id);
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
