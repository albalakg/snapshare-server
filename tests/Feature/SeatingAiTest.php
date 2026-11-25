<?php

namespace Tests\Feature;

use App\Models\AiSeatingRun;
use App\Models\EventGuestGroup;
use App\Services\Seating\Ai\SeatingAiProvider;
use App\Services\Seating\SeatingPacker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

class SeatingAiTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    private array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeHost('seatai');
        config(['seating.enabled' => true, 'seating.ai.provider' => 'mock', 'seating.ai.api_key' => null, 'seating.ai.runs_per_event' => 5]);
    }

    private function seedPlan(): array
    {
        $fam = EventGuestGroup::create(['event_id' => $this->event->id, 'name' => 'משפחה', 'side' => 'bride']);
        $work = EventGuestGroup::create(['event_id' => $this->event->id, 'name' => 'עבודה', 'side' => 'groom']);
        $g = [];
        $g[] = $this->guest('F1', 4, ['group_id' => $fam->id]);
        $g[] = $this->guest('F2', 3, ['group_id' => $fam->id]);
        $g[] = $this->guest('W1', 2, ['group_id' => $work->id]);
        $g[] = $this->guest('W2', 2, ['group_id' => $work->id]);
        $g[] = $this->guest('Solo', 1);
        $g[] = $this->guest('Declined', 2, ['status' => 2]);

        $res = $this->api('PUT', '/seating', [
            'revision'    => 0,
            'tables'      => [
                ['key' => 'a', 'label' => '1', 'shape' => 'round', 'seats' => 8, 'side' => 'bride', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 200],
                ['key' => 'b', 'label' => '2', 'shape' => 'round', 'seats' => 8, 'side' => 'groom', 'x' => 300, 'y' => 0, 'w' => 200, 'h' => 200],
            ],
            'fixtures'    => [],
            'assignments' => [],
        ])->assertOk();
        $this->tables = $res->json('data.key_map');

        return $g;
    }

    public function test_packer_unit_keeps_groups_and_respects_capacity(): void
    {
        $tables = [['id' => 1, 'label' => '1', 'seats' => 7, 'side' => null], ['id' => 2, 'label' => '2', 'seats' => 7, 'side' => null]];
        $guests = [
            ['id' => 10, 'party' => 3, 'group_id' => 1, 'group_name' => 'A', 'side' => null],
            ['id' => 11, 'party' => 3, 'group_id' => 1, 'group_name' => 'A', 'side' => null],
            ['id' => 12, 'party' => 4, 'group_id' => 2, 'group_name' => 'B', 'side' => null],
            ['id' => 13, 'party' => 4, 'group_id' => 3, 'group_name' => 'C', 'side' => null],
        ];
        $r = (new SeatingPacker())->pack($tables, $guests, [['type' => 'apart', 'guest_ids' => [10, 11]]]);
        $by = collect($r['assignments'])->keyBy('guest_id');
        // apart beats group → 10 and 11 on different tables
        $this->assertNotSame($by[10]['table_id'], $by[11]['table_id']);
        $this->assertCount(0, $r['unplaced']);
        $load = [];
        foreach ($r['assignments'] as $a) {
            $load[$a['table_id']] = ($load[$a['table_id']] ?? 0) + collect($guests)->firstWhere('id', $a['guest_id'])['party'];
        }
        $this->assertLessThanOrEqual(7, max($load));
        // Without the apart rule group A stays whole on one table; groups that don't fit are left unplaced, never over capacity
        $guests[] = ['id' => 14, 'party' => 4, 'group_id' => 4, 'group_name' => 'D', 'side' => null];
        $r2 = (new SeatingPacker())->pack($tables, $guests, []);
        $this->assertSame([13, 14], array_column($r2['unplaced'], 'guest_id'));
        $this->assertSame($r2['assignments'][0]['table_id'], $r2['assignments'][1]['table_id']);
        // deterministic
        $this->assertEquals($r2, (new SeatingPacker())->pack($tables, $guests, []));
    }

    public function test_packer_run_is_free_and_preview_only_until_applied(): void
    {
        $g = $this->seedPlan();
        $res = $this->api('POST', '/seating/ai/runs', ['engine' => 'packer'])->assertCreated();
        $res->assertJsonPath('data.run.status', 'succeeded');
        $res->assertJsonPath('data.run.counted', false);
        $res->assertJsonPath('data.quota.remaining', 5);

        $proposal = collect($res->json('data.run.proposal'))->keyBy('guest_id');
        $this->assertSame($proposal[$g[0]->id]['table_id'], $proposal[$g[1]->id]['table_id'], 'family together');
        $this->assertSame($this->tables['a'], $proposal[$g[0]->id]['table_id'], 'bride side table');
        $this->assertSame($this->tables['b'], $proposal[$g[2]->id]['table_id'], 'groom side table');
        $this->assertFalse($proposal->has($g[5]->id), 'declined never seated');

        // Not applied yet
        $this->api('GET', '/seating')->assertJsonCount(0, 'data.assignments');

        $runId = $res->json('data.run.id');
        $applied = $this->api('POST', "/seating/ai/runs/{$runId}/apply")->assertOk();
        $applied->assertJsonPath('data.revision', 2);
        $this->assertCount(5, $applied->json('data.assignments'));
        $this->api('POST', "/seating/ai/runs/{$runId}/apply")->assertStatus(422);
    }

    public function test_ai_run_spends_quota_and_exhausts_at_limit(): void
    {
        $this->seedPlan();
        config(['seating.ai.runs_per_event' => 2]);

        $r1 = $this->api('POST', '/seating/ai/runs', ['engine' => 'ai', 'instructions' => 'המשפחה קרוב לרחבה'])->assertCreated();
        $r1->assertJsonPath('data.run.provider', 'mock');
        $r1->assertJsonPath('data.run.counted', true);
        $r1->assertJsonPath('data.quota.remaining', 1);
        $this->assertGreaterThan(0, $r1->json('data.run.tokens_in'));

        $this->api('POST', '/seating/ai/runs/' . $r1->json('data.run.id') . '/discard')->assertOk()
            ->assertJsonPath('data.run.status', 'discarded')->assertJsonPath('data.quota.remaining', 1);

        $this->api('POST', '/seating/ai/runs', ['engine' => 'ai'])->assertCreated()->assertJsonPath('data.quota.remaining', 0);
        $this->api('POST', '/seating/ai/runs', ['engine' => 'ai'])->assertStatus(429);
        // Free packer still available
        $this->api('POST', '/seating/ai/runs', ['engine' => 'packer'])->assertCreated();
    }

    public function test_failed_provider_run_is_not_counted(): void
    {
        $this->seedPlan();
        $this->app->bind(SeatingAiProvider::class, fn () => new class implements SeatingAiProvider {
            public function propose(array $input): array { throw new RuntimeException('boom'); }
            public function name(): string { return 'openai'; }
            public function model(): string { return 'gpt-4o-mini'; }
        });
        config(['seating.ai.provider' => 'openai', 'seating.ai.api_key' => 'sk-test']);
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $this->api('POST', '/seating/ai/runs', ['engine' => 'ai'])->assertStatus(502)
            ->assertJsonPath('data.run.status', 'failed')
            ->assertJsonPath('data.quota.remaining', 5);
        $this->assertSame(1, AiSeatingRun::where('event_id', $this->event->id)->where('status', 'failed')->count());
    }

    public function test_openai_proposal_is_validated_and_repaired(): void
    {
        $g = $this->seedPlan();
        config(['seating.ai.provider' => 'openai', 'seating.ai.api_key' => 'sk-test']);
        $content = json_encode(['assignments' => [
            ['guest_id' => $g[0]->id, 'table_id' => $this->tables['a'], 'reason' => 'משפחה'],
            ['guest_id' => $g[1]->id, 'table_id' => $this->tables['a'], 'reason' => 'משפחה'],
            ['guest_id' => $g[2]->id, 'table_id' => $this->tables['a'], 'reason' => 'over capacity'], // 4+3+2 > 8 → repaired
            ['guest_id' => $g[5]->id, 'table_id' => $this->tables['b'], 'reason' => 'declined'],     // not eligible → ignored
            ['guest_id' => 99999999, 'table_id' => 1, 'reason' => 'hallucinated'],
        ], 'unplaced' => []]);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-4o-mini-2024-07-18',
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120],
        ])]);

        $res = $this->api('POST', '/seating/ai/runs', ['engine' => 'ai'])->assertCreated();
        $res->assertJsonPath('data.run.provider', 'openai');
        $res->assertJsonPath('data.run.tokens_in', 900);
        $this->assertGreaterThan(0, $res->json('data.run.cost_usd'));
        $p = collect($res->json('data.run.proposal'))->keyBy('guest_id');
        $this->assertSame('ai', $p[$g[0]->id]['source']);
        $this->assertSame('repaired', $p[$g[2]->id]['source']);
        $this->assertSame($this->tables['b'], $p[$g[2]->id]['table_id']);
        $this->assertFalse($p->has($g[5]->id));
        $this->assertFalse($p->has(99999999));

        Http::assertSent(fn ($req) => str_contains($req->url(), '/chat/completions') && $req['temperature'] === 0 && $req->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_apply_on_stale_revision_needs_force(): void
    {
        $g = $this->seedPlan();
        $run = $this->api('POST', '/seating/ai/runs', ['engine' => 'packer'])->json('data.run');
        $state = $this->api('GET', '/seating')->json('data');
        $this->api('PUT', '/seating', [
            'revision' => $state['revision'], 'tables' => $state['tables'], 'fixtures' => [], 'assignments' => [],
        ])->assertOk();

        $this->api('POST', "/seating/ai/runs/{$run['id']}/apply")->assertStatus(409)->assertJsonPath('data.stale', true);
        $this->api('POST', "/seating/ai/runs/{$run['id']}/apply", ['force' => true])->assertOk();
    }

    public function test_requires_tables(): void
    {
        $this->guest('A');
        $this->api('POST', '/seating/ai/runs', ['engine' => 'packer'])->assertStatus(422);
    }
}
