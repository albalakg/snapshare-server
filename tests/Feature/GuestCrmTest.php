<?php

namespace Tests\Feature;

use App\Models\EventGuest;
use App\Models\EventGuestGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

class GuestCrmTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeHost('crm');
    }

    public function test_create_guest_without_phone_and_list_with_stats(): void
    {
        $res = $this->api('POST', '/guests', ['full_name' => '  סבתא   רחל ', 'party_size' => 2, 'side' => 'bride', 'notes' => 'קרוב ליציאה']);
        $res->assertCreated();
        $res->assertJsonPath('data.full_name', 'סבתא רחל');
        $res->assertJsonPath('data.phone', null);
        $res->assertJsonPath('data.party_size', 2);
        $res->assertJsonPath('data.notes', 'קרוב ליציאה');

        $this->api('POST', '/guests', ['full_name' => 'דנה', 'phone' => '+972-52-123-4567', 'status' => 1])->assertCreated()
            ->assertJsonPath('data.phone', '0521234567');

        $list = $this->api('GET', '/guests');
        $list->assertOk();
        $list->assertJsonCount(2, 'data.guests');
        $list->assertJsonPath('data.stats.invitations', 2);
        $list->assertJsonPath('data.stats.people_invited', 3);
        $list->assertJsonPath('data.stats.confirmed', 1);
        $list->assertJsonPath('data.stats.pending', 2);
        $list->assertJsonPath('data.stats.without_phone', 1);

        // notes are encrypted at rest
        $raw = \DB::table('event_guests')->where('event_id', $this->event->id)->whereNotNull('notes')->value('notes');
        $this->assertNotSame('קרוב ליציאה', $raw);
    }

    public function test_phone_dedupe_across_local_and_international_forms(): void
    {
        $this->guest('Legacy', 1, ['phone' => '972521234567']);
        $this->api('POST', '/guests', ['full_name' => 'Dup', 'phone' => '052-1234567'])->assertStatus(422);
    }

    public function test_update_group_side_and_declined_guest_loses_seat(): void
    {
        $g = $this->guest('Yossi', 2);
        $group = $this->api('POST', '/guest-groups', ['name' => 'חברים מהצבא', 'side' => 'groom'])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $group['color']);

        $this->api('PUT', '/seating', [
            'revision' => 0,
            'tables' => [['key' => 'n1', 'label' => '1', 'shape' => 'round', 'seats' => 10, 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 200]],
            'fixtures' => [],
            'assignments' => [['guest_id' => $g->id, 'table_key' => 'n1', 'seat' => 0]],
        ])->assertOk();

        $this->api('PUT', "/guests/{$g->id}", ['group_id' => $group['id'], 'side' => 'groom'])->assertOk()
            ->assertJsonPath('data.group_id', $group['id'])
            ->assertJsonPath('data.table_label', '1');

        $this->api('PUT', "/guests/{$g->id}", ['status' => 2])->assertOk()->assertJsonPath('data.table_id', null);
        $this->assertDatabaseMissing('seating_assignments', ['guest_id' => $g->id]);
    }

    public function test_bulk_update_and_bulk_delete(): void
    {
        $a = $this->guest('A');
        $b = $this->guest('B');
        $this->api('POST', '/guests/bulk-update', ['guest_ids' => [$a->id, $b->id], 'changes' => ['status' => 3, 'side' => 'shared']])
            ->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame(3, (int) $a->fresh()->status);
        $this->assertSame('shared', $b->fresh()->side);

        $this->api('DELETE', '/guests', ['guest_ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSoftDeleted('event_guests', ['id' => $a->id]);
    }

    public function test_import_preview_flags_errors_duplicates_and_hebrew_values(): void
    {
        $this->guest('Existing', 1, ['phone' => '0501111111']);
        $rows = [
            ['_line' => 2, 'full_name' => 'משפחת כהן', 'phone' => '050-222-2222', 'party_size' => '4', 'group' => 'משפחה', 'side' => 'צד כלה', 'status' => 'מגיעים'],
            ['_line' => 3, 'full_name' => '', 'phone' => '0503333333'],
            ['_line' => 4, 'full_name' => 'Existing again', 'phone' => '+972501111111'],
            ['_line' => 5, 'full_name' => 'Same file', 'phone' => '0502222222'],
            ['_line' => 6, 'full_name' => 'Existing', 'phone' => '', 'party_size' => 'abc'],
            ['_line' => 7, 'full_name' => 'Bad phone', 'phone' => '12'],
        ];

        $res = $this->api('POST', '/guests/import/preview', ['rows' => $rows]);
        $res->assertOk();
        $out = $res->json('data.rows');
        $this->assertSame('new', $out[0]['action']);
        $this->assertSame('bride', $out[0]['values']['side']);
        $this->assertSame(1, $out[0]['values']['status']);
        $this->assertSame(4, $out[0]['values']['party_size']);
        $this->assertSame('error', $out[1]['action']);
        $this->assertSame('skip', $out[2]['action']);
        $this->assertSame('duplicate_in_file', $out[3]['action']);
        $this->assertSame('new', $out[4]['action']);
        $codes = array_column($out[4]['warnings'], 'code');
        $this->assertContains('name_match', $codes);
        $this->assertContains('party_invalid', $codes);
        $this->assertSame('error', $out[5]['action']);
        $res->assertJsonPath('data.summary.new', 2);
        $res->assertJsonPath('data.summary.error', 2);

        // Preview writes nothing.
        $this->assertSame(1, EventGuest::where('event_id', $this->event->id)->count());
    }

    public function test_import_commit_creates_groups_and_updates_on_request(): void
    {
        $existing = $this->guest('Old name', 1, ['phone' => '0501111111', 'status' => 1]);
        $rows = [
            ['full_name' => 'משפחת לוי', 'phone' => '0504444444', 'party_size' => '3', 'group' => 'עבודה', 'side' => 'חתן'],
            ['full_name' => 'New name', 'phone' => '0501111111', 'party_size' => '2', 'group' => 'עבודה'],
        ];
        $res = $this->api('POST', '/guests/import/commit', ['rows' => $rows, 'on_duplicate' => 'update']);
        $res->assertOk();
        $res->assertJsonPath('data.created', 1);
        $res->assertJsonPath('data.updated', 1);
        $res->assertJsonPath('data.groups_created', 1);

        $fresh = $existing->fresh();
        $this->assertSame('New name', $fresh->full_name);
        $this->assertSame(2, (int) $fresh->party_size);
        $this->assertSame(1, (int) $fresh->status, 'RSVP kept when the file has no status');
        $this->assertSame(1, EventGuestGroup::where('event_id', $this->event->id)->where('name', 'עבודה')->count());
    }

    public function test_export_csv_has_bom_hebrew_headers_and_formula_guard(): void
    {
        $this->guest('=HYPERLINK("x")');
        $res = $this->actingAs($this->user, 'api')->get("/api/events/{$this->event->id}/guests/export");
        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('שם מלא', $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
    }

    public function test_other_users_cannot_read_or_write_guests(): void
    {
        $other = $this->makeUser('crm-other');
        $this->actingAs($other, 'api')->getJson("/api/events/{$this->event->id}/guests")->assertStatus(400);
        $this->actingAs($other, 'api')->postJson("/api/events/{$this->event->id}/guests", ['full_name' => 'x'])->assertStatus(400);
        $this->assertSame(0, EventGuest::where('event_id', $this->event->id)->count());
    }

    public function test_whatsapp_list_hides_phoneless_guests(): void
    {
        $this->guest('With phone', 1, ['phone' => '0505555555']);
        $this->guest('No phone');
        $this->api('GET', '/whatsapp/guests')->assertOk()->assertJsonPath('data.total', 1);
    }
}
