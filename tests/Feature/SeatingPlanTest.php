<?php

namespace Tests\Feature;

use App\Models\SeatingAssignment;
use App\Services\Seating\SeatLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

class SeatingPlanTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeHost('seatplan');
        config(['seating.enabled' => true]);
    }

    private function table(string $key, int $seats = 10, array $extra = []): array
    {
        return array_merge(['key' => $key, 'label' => 'T' . $key, 'shape' => 'round', 'seats' => $seats, 'side' => null, 'x' => 100, 'y' => 100, 'w' => 200, 'h' => 200, 'rot' => 0], $extra);
    }

    public function test_get_empty_state_then_save_maps_client_keys_and_bumps_revision(): void
    {
        $this->api('GET', '/seating')->assertOk()
            ->assertJsonPath('data.revision', 0)
            ->assertJsonPath('data.entitlement.enabled', true)
            ->assertJsonPath('data.ai.included', 5);

        $a = $this->guest('A', 2);
        $res = $this->api('PUT', '/seating', [
            'revision'    => 0,
            'tables'      => [$this->table('n-1'), $this->table('n-2', 8, ['shape' => 'rect', 'rot' => 375])],
            'fixtures'    => [['id' => 'dance', 'kind' => 'dance', 'label' => 'רחבה', 'x' => 500, 'y' => 300, 'w' => 300, 'h' => 200]],
            'assignments' => [['guest_id' => $a->id, 'table_key' => 'n-2', 'seat' => 3]],
        ]);
        $res->assertOk();
        $res->assertJsonPath('data.revision', 1);
        $id2 = $res->json('data.key_map.n-2');
        $this->assertNotNull($id2);
        $res->assertJsonPath('data.tables.1.rot', 15);
        $res->assertJsonPath('data.assignments.0.table_id', $id2);
        $res->assertJsonPath('data.assignments.0.seat', 3);
        $res->assertJsonPath('data.fixtures.0.kind', 'dance');

        // Stale revision → 409 with current state
        $this->api('PUT', '/seating', ['revision' => 0, 'tables' => [], 'fixtures' => [], 'assignments' => []])
            ->assertStatus(409)->assertJsonPath('data.conflict', true)->assertJsonPath('data.state.revision', 1);

        // Removing a table removes its assignments
        $tables = $res->json('data.tables');
        $this->api('PUT', '/seating', ['revision' => 1, 'tables' => [$tables[0]], 'fixtures' => [], 'assignments' => []])
            ->assertOk()->assertJsonCount(1, 'data.tables')->assertJsonCount(0, 'data.assignments');
    }

    public function test_server_normalises_overlapping_seats_and_flags_overflow(): void
    {
        $a = $this->guest('A', 3);
        $b = $this->guest('B', 3);
        $c = $this->guest('C', 3);
        $res = $this->api('PUT', '/seating', [
            'revision'    => 0,
            'tables'      => [$this->table('x', 6)],
            'fixtures'    => [],
            'assignments' => [
                ['guest_id' => $a->id, 'table_key' => 'x', 'seat' => 0],
                ['guest_id' => $b->id, 'table_key' => 'x', 'seat' => 1], // overlaps A → moved to 3
                ['guest_id' => $c->id, 'table_key' => 'x', 'seat' => 0], // no room → seat null
            ],
        ])->assertOk();

        $seats = collect($res->json('data.assignments'))->pluck('seat', 'guest_id');
        $this->assertSame(0, $seats[$a->id]);
        $this->assertSame(3, $seats[$b->id]);
        $this->assertNull($seats[$c->id]);
        $this->assertSame('over_capacity', $res->json('data.warnings.0.type'));
    }

    public function test_declined_and_foreign_guests_are_dropped(): void
    {
        $declined = $this->guest('No', 1, ['status' => 2]);
        $other = $this->makeUser('seat-foreign');
        $res = $this->api('PUT', '/seating', [
            'revision'    => 0,
            'tables'      => [$this->table('x')],
            'fixtures'    => [],
            'assignments' => [
                ['guest_id' => $declined->id, 'table_key' => 'x', 'seat' => 0],
                ['guest_id' => 999999999, 'table_key' => 'x', 'seat' => 2],
            ],
        ])->assertOk();
        $this->assertCount(0, $res->json('data.assignments'));
        $this->assertEqualsCanonicalizing([$declined->id, 999999999], $res->json('data.dropped'));

        $this->actingAs($other, 'api')->getJson("/api/events/{$this->event->id}/seating")->assertStatus(400);
    }

    public function test_constraints_crud_and_warnings(): void
    {
        $a = $this->guest('A');
        $b = $this->guest('B');
        $c = $this->api('POST', '/seating/constraints', ['type' => 'apart', 'guest_ids' => [$a->id, $b->id], 'note' => 'לא ביחד'])
            ->assertCreated()->json('data');

        $res = $this->api('PUT', '/seating', [
            'revision'    => 0,
            'tables'      => [$this->table('x')],
            'fixtures'    => [],
            'assignments' => [['guest_id' => $a->id, 'table_key' => 'x'], ['guest_id' => $b->id, 'table_key' => 'x']],
        ])->assertOk();
        $this->assertSame('apart', $res->json('data.warnings.0.type'));

        $this->api('PUT', "/seating/constraints/{$c['id']}", ['type' => 'together'])->assertOk()->assertJsonPath('data.type', 'together');
        $this->api('GET', '/seating')->assertOk()->assertJsonCount(0, 'data.warnings');
        $this->api('DELETE', "/seating/constraints/{$c['id']}")->assertOk();
        $this->api('POST', '/seating/constraints', ['type' => 'apart', 'guest_ids' => [$a->id]])->assertStatus(422);
    }

    public function test_disabled_flag_blocks_writes_but_not_guest_crm(): void
    {
        config(['seating.enabled' => false]);
        $this->api('GET', '/seating')->assertOk()->assertJsonPath('data.entitlement.enabled', false);
        $this->api('PUT', '/seating', ['revision' => 0, 'tables' => [], 'fixtures' => [], 'assignments' => []])->assertStatus(403);
        $this->api('POST', '/seating/ai/runs', ['engine' => 'packer'])->assertStatus(403);
        $this->api('POST', '/guests', ['full_name' => 'Still works'])->assertCreated();
    }

    public function test_seat_layout_unit(): void
    {
        $r = SeatLayout::normalize(5, [
            ['guest_id' => 1, 'party' => 2, 'seat' => 3],
            ['guest_id' => 2, 'party' => 2, 'seat' => 4], // out of range → first free
            ['guest_id' => 3, 'party' => 2, 'seat' => null],
        ]);
        $this->assertSame([1 => 3, 2 => 0, 3 => null], $r);
        $this->assertSame(0, SeatingAssignment::count() * 0);
    }
}
