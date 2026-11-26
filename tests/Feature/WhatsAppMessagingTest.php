<?php

namespace Tests\Feature;

use App\Models\EventGuest;
use App\Models\EventHub;
use App\Models\WhatsAppSend;
use App\Models\WhatsAppSendRecipient;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\Dispatcher;
use App\Services\Messaging\IntentParser;
use App\Services\Messaging\Providers\MockSmsDriver;
use App\Services\Messaging\Providers\MockWhatsAppDriver;
use App\Services\Messaging\QuietHoursGuard;
use App\Services\Messaging\RsvpToken;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

class WhatsAppMessagingTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    protected function setUp(): void
    {
        parent::setUp();
        // Tuesday 12:00 Israel time — outside quiet hours / Shabbat.
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'UTC'));
        config(['whatsapp_messaging.enabled' => true, 'whatsapp_messaging.mock.dev_endpoints' => true, 'whatsapp_messaging.mock.approve_after_seconds' => 30]);
        MockWhatsAppDriver::$outbox = [];
        MockSmsDriver::$outbox = [];
        $this->makeHost('wamsg');
        $this->event->starts_at = '2027-01-14 17:00:00'; // Thursday 19:00 Israel
        $this->event->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function m(string $method, string $uri, array $data = [])
    {
        return $this->api($method, '/messaging' . $uri, $data);
    }

    private function guests(int $n, array $extra = []): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = $this->guest('אורח ' . $i . ' בדיקה', 2, $extra + ['phone' => '05012300' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'status' => 0]);
        }

        return $out;
    }

    public function test_flag_off_returns_403_and_overview_reports_trial_quota(): void
    {
        config(['whatsapp_messaging.enabled' => false]);
        $this->m('GET', '/overview')->assertStatus(403);
        config(['whatsapp_messaging.enabled' => true]);
        $this->guests(3);
        $this->m('GET', '/overview')->assertOk()
            ->assertJsonPath('data.provider', 'mock')
            ->assertJsonPath('data.quota.package.key', 'trial')
            ->assertJsonPath('data.quota.per_guest', 1)
            ->assertJsonPath('data.quota.capacity', 3)
            ->assertJsonPath('data.guests.with_phone', 3);
    }

    public function test_other_users_event_is_hidden(): void
    {
        $other = $this->makeUser('wa-foreign');
        $this->actingAs($other, 'api')->json('GET', "/api/events/{$this->event->id}/messaging/overview")->assertStatus(404);
    }

    public function test_templates_list_has_ready_templates_approved_in_mock(): void
    {
        $res = $this->m('GET', '/templates')->assertOk();
        $rsvp = collect($res->json('data.templates'))->firstWhere('key', 'rsvp_invite');
        $this->assertSame('approved', $rsvp['meta_status']);
        $this->assertCount(3, $rsvp['buttons']);
    }

    public function test_send_now_delivers_with_mock_and_trial_quota_blocks_second_message(): void
    {
        [$a, $b] = $this->guests(2);
        $this->guest('ללא טלפון', 1, ['status' => 0]);
        $res = $this->m('POST', '/sends', ['template_key' => 'rsvp_invite', 'segment' => ['statuses' => [0]], 'send_now' => true])->assertStatus(201);
        $res->assertJsonPath('data.status', 'sent')->assertJsonPath('data.counts.sent', 2)->assertJsonPath('data.audience_report.no_phone', 1);
        $this->assertCount(2, MockWhatsAppDriver::$outbox);
        $this->assertSame('972501230001', MockWhatsAppDriver::$outbox[0]['to']);
        $this->assertSame('אורח', MockWhatsAppDriver::$outbox[0]['params']['first_name']);
        $this->assertStringStartsWith('rsvp_yes:', MockWhatsAppDriver::$outbox[0]['payloads'][0]);

        // Trial = 1 message per guest → second send is fully over quota
        $res2 = $this->m('POST', '/sends', ['template_key' => 'rsvp_nudge', 'segment' => ['statuses' => [0]], 'scheduled_at' => '2026-12-02T12:00'])->assertStatus(201);
        $res2->assertJsonPath('data.counts.pending', 0)->assertJsonPath('data.counts.skipped', 2);

        // Pro add-on (dev entitlement) → 6 per guest
        $this->m('POST', '/dev/entitlement', ['pro_addon' => true])->assertOk();
        $this->m('GET', '/overview')->assertJsonPath('data.quota.per_guest', 6)->assertJsonPath('data.quota.used', 2);
    }

    public function test_patch_requires_matching_version_and_reschedule_into_quiet_hours_is_moved(): void
    {
        $this->guests(2);
        $send = $this->m('POST', '/sends', ['template_key' => 'save_the_date', 'segment' => ['statuses' => [0]], 'scheduled_at' => '2026-12-03T18:00'])->assertStatus(201);
        $id = $send->json('data.id');
        $send->assertJsonPath('data.version', 1)->assertJsonPath('data.scheduled_at', '2026-12-03T16:00:00+00:00');

        $this->m('PATCH', "/sends/{$id}", ['expected_version' => 7, 'scheduled_at' => '2026-12-04T12:00'])
            ->assertStatus(409)->assertJsonPath('data.conflict', true)->assertJsonPath('data.send.version', 1);

        // 23:00 Israel → next allowed 08:30
        $r = $this->m('PATCH', "/sends/{$id}", ['expected_version' => 1, 'scheduled_at' => '2026-12-03T23:00'])->assertOk();
        $r->assertJsonPath('data.version', 2)->assertJsonPath('data.changes.conflicts.0', 'quiet')->assertJsonPath('data.scheduled_at', '2026-12-04T06:30:00+00:00');

        // Friday 16:00 → Shabbat → Saturday 20:30
        $r = $this->m('PATCH', "/sends/{$id}", ['expected_version' => 2, 'scheduled_at' => '2026-12-04T16:00'])->assertOk();
        $r->assertJsonPath('data.scheduled_at', '2026-12-05T18:30:00+00:00');

        // Host turns guards off → exact time kept
        $this->m('PUT', '/settings', ['quiet_hours_enabled' => false, 'shabbat_guard_enabled' => false])->assertOk();
        $this->m('PATCH', "/sends/{$id}", ['expected_version' => 3, 'scheduled_at' => '2026-12-04T16:00'])->assertOk()
            ->assertJsonPath('data.scheduled_at', '2026-12-04T14:00:00+00:00');

        $this->m('GET', "/sends/{$id}/audit")->assertOk()->assertJsonCount(4, 'data.audit');
    }

    public function test_body_edit_needs_meta_review_and_dispatch_waits_until_approved(): void
    {
        $this->guests(2);
        $id = $this->m('POST', '/sends', ['template_key' => 'save_the_date', 'segment' => ['statuses' => [0]], 'scheduled_at' => '2026-12-01T12:30'])->json('data.id');
        $r = $this->m('PATCH', "/sends/{$id}", ['expected_version' => 1, 'body' => 'היי {{first_name}}, נוסח חדש לגמרי {{hub_link}}'])->assertOk();
        $r->assertJsonPath('data.content_version', 2)->assertJsonPath('data.changes.content.needs_review', true)->assertJsonPath('data.pending_updated', 2);
        // the host sees it as "waiting on Meta" right away, before the dispatcher reaches the send time
        $this->m('GET', '/overview')->assertJsonPath('data.queue.review', 1);

        Carbon::setTestNow(now()->addMinutes(31)->subSeconds(10)); // due, but still within review delay? approve_after=30s
        config(['whatsapp_messaging.mock.approve_after_seconds' => 999999]);
        (new Dispatcher())->tick();
        $this->assertSame('review', WhatsAppSend::find($id)->status);
        $this->assertCount(0, MockWhatsAppDriver::$outbox);

        $tplId = WhatsAppSend::find($id)->template_id;
        $this->m('POST', "/dev/templates/{$tplId}", ['status' => 'approved'])->assertOk();
        (new Dispatcher())->tick();
        $this->assertSame('sent', WhatsAppSend::find($id)->status);
        $this->assertCount(2, MockWhatsAppDriver::$outbox);
        $this->assertSame(2, WhatsAppSendRecipient::where('send_id', $id)->where('content_version_sent', 2)->count());
    }

    public function test_rejected_custom_template_keeps_send_in_review(): void
    {
        $this->guests(1);
        $res = $this->m('POST', '/templates', ['title' => 'שלי', 'body' => 'היי {{first_name}} [reject]', 'category' => 'utility'])->assertStatus(201);
        $res->assertJsonPath('data.meta_status', 'pending');
        Carbon::setTestNow(now()->addMinutes(2));
        $list = $this->m('GET', '/templates')->assertOk();
        $this->assertSame('rejected', collect($list->json('data.templates'))->firstWhere('id', $res->json('data.id'))['meta_status']);
        $this->m('POST', '/templates', ['title' => 'x', 'body' => 'היי {{nope}}'])->assertStatus(422)->assertJsonPath('data.unknown_variables.0', 'nope');
    }

    public function test_partial_send_edits_apply_only_to_pending_and_cancel_releases(): void
    {
        $this->m('POST', '/dev/entitlement', ['pro_addon' => true]);
        $gs = $this->guests(4);
        config(['whatsapp_messaging.dispatch_batch' => 2]);
        $res = $this->m('POST', '/sends', ['template_key' => 'rsvp_invite', 'segment' => ['statuses' => [0]], 'send_now' => true])->assertStatus(201);
        $id = $res->json('data.id');
        $res->assertJsonPath('data.status', 'sending')->assertJsonPath('data.counts.sent', 2)->assertJsonPath('data.counts.pending', 2);

        $v = $res->json('data.version');
        $r = $this->m('PATCH', "/sends/{$id}", ['expected_version' => $v, 'title' => 'כותרת חדשה'])->assertOk();
        $r->assertJsonPath('data.pending_updated', 2)->assertJsonPath('data.locked_unchanged', 2);

        // audience change: drop guest 4 (pending), sent ones stay locked
        $r = $this->m('PATCH', "/sends/{$id}", ['expected_version' => $v + 1, 'segment' => ['guest_ids' => [$gs[0]->id, $gs[1]->id, $gs[2]->id]]])->assertOk();
        $r->assertJsonPath('data.changes.segment.removed', 1)->assertJsonPath('data.counts.pending', 1)->assertJsonPath('data.counts.sent', 2);

        $r = $this->m('PATCH', "/sends/{$id}", ['expected_version' => $v + 2, 'action' => 'cancel'])->assertOk();
        $r->assertJsonPath('data.status', 'partial')->assertJsonPath('data.changes.released_credits', 1);
        $this->m('PATCH', "/sends/{$id}", ['expected_version' => $v + 3, 'action' => 'pause'])->assertStatus(422);
        $this->m('GET', '/overview')->assertJsonPath('data.quota.used', 2)->assertJsonPath('data.quota.reserved', 0);
    }

    public function test_pause_holds_dispatch_and_resume_sends(): void
    {
        $this->guests(2);
        $id = $this->m('POST', '/sends', ['template_key' => 'save_the_date', 'segment' => ['statuses' => [0]], 'scheduled_at' => '2026-12-01T12:05'])->json('data.id');
        $this->m('PATCH', "/sends/{$id}", ['expected_version' => 1, 'action' => 'pause'])->assertOk()->assertJsonPath('data.status', 'paused');
        Carbon::setTestNow(now()->addMinutes(10));
        (new Dispatcher())->tick();
        $this->assertCount(0, MockWhatsAppDriver::$outbox);
        $this->m('PATCH', "/sends/{$id}", ['expected_version' => 2, 'action' => 'resume'])->assertOk();
        (new Dispatcher())->tick();
        $this->assertCount(2, MockWhatsAppDriver::$outbox);
    }

    public function test_send_now_during_quiet_hours_needs_override(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 21:00:00', 'UTC')); // 23:00 Israel
        $this->guests(1);
        $this->m('POST', '/sends', ['template_key' => 'schedule_change', 'segment' => ['statuses' => [0]], 'send_now' => true])
            ->assertStatus(422)->assertJsonPath('data.conflicts.0', 'quiet');
        $this->m('POST', '/sends', ['template_key' => 'schedule_change', 'segment' => ['statuses' => [0]], 'send_now' => true, 'override_quiet' => true])
            ->assertStatus(201)->assertJsonPath('data.status', 'sent');
    }

    public function test_quiet_hours_guard_unit(): void
    {
        $g = new QuietHoursGuard(true, true);
        $this->assertSame(['quiet'], $g->conflicts(Carbon::parse('2026-12-01 20:00', 'UTC'))); // 22:00 IL
        $this->assertSame([], $g->conflicts(Carbon::parse('2026-12-01 07:00', 'UTC'))); // 09:00 IL
        $this->assertSame(['shabbat'], $g->conflicts(Carbon::parse('2026-12-04 14:00', 'UTC'))); // Fri 16:00
        $this->assertSame('2026-12-05 18:30', $g->nextAllowed(Carbon::parse('2026-12-04 14:00', 'UTC'))->format('Y-m-d H:i'));
        $this->assertSame([], (new QuietHoursGuard(false, false))->conflicts(Carbon::parse('2026-12-04 20:00', 'UTC')));
    }

    public function test_rsvp_bot_flow_writes_event_guest(): void
    {
        [$a] = $this->guests(1);
        $this->m('POST', '/sends', ['template_key' => 'rsvp_invite', 'segment' => ['statuses' => [0]], 'send_now' => true])->assertStatus(201);
        $this->m('POST', '/dev/inbound', ['guest_id' => $a->id, 'payload' => 'rsvp_yes'])->assertOk()->assertJsonPath('data.intent', 'rsvp_yes');
        $this->assertSame(1, (int) $a->fresh()->status);
        $this->m('POST', '/dev/inbound', ['guest_id' => $a->id, 'text' => '3'])->assertOk()->assertJsonPath('data.intent', 'bot');
        $this->m('POST', '/dev/inbound', ['guest_id' => $a->id, 'text' => '2'])->assertOk();
        $this->m('POST', '/dev/inbound', ['guest_id' => $a->id, 'text' => 'אלרגיה לבוטנים'])->assertOk();
        $g = $a->fresh();
        $this->assertSame(3, (int) $g->party_size);
        $this->assertSame('צמחוני', $g->metadata['rsvp']['meal']);
        $this->assertSame('אלרגיה לבוטנים', $g->metadata['rsvp']['allergies']);
        $this->assertSame('whatsapp', $g->rsvp_source);
        $thread = $this->m('GET', "/inbox/{$a->id}")->assertOk()->assertJsonPath('data.window_open', true);
        $this->assertGreaterThanOrEqual(8, count($thread->json('data.messages')));
        $this->m('POST', "/inbox/{$a->id}/reply", ['body' => 'נתראה!'])->assertOk();
    }

    public function test_free_text_suggestion_apply_and_opt_out(): void
    {
        $this->m('POST', '/dev/entitlement', ['pro_addon' => true]);
        [$a] = $this->guests(1);
        $this->m('POST', '/sends', ['template_key' => 'save_the_date', 'segment' => ['statuses' => [0]], 'send_now' => true]);
        $this->m('POST', '/dev/inbound', ['guest_id' => $a->id, 'text' => 'מגיעים רק 2 במקום 3, אני צמחונית'])->assertJsonPath('data.intent', 'rsvp_update');
        $inbox = $this->m('GET', '/inbox')->assertOk();
        $sugg = $inbox->json('data.threads.0.suggestion');
        $this->assertSame(['status' => 1, 'party_size' => 2, 'meal' => 'צמחוני'], $sugg['changes']);
        $this->m('POST', "/inbox/messages/{$sugg['message_id']}/suggestion", ['apply' => true])->assertOk()->assertJsonPath('data.guest.party_size', 2);

        $pending = $this->m('POST', '/sends', ['template_key' => 'reminder_pre_event', 'segment' => ['guest_ids' => [$a->id]], 'scheduled_at' => '2026-12-05T21:00'])->json('data.id');
        $this->m('POST', '/dev/inbound', ['guest_id' => $a->id, 'text' => 'הסר'])->assertJsonPath('data.intent', 'opt_out');
        $this->assertNotNull($a->fresh()->wa_opted_out_at);
        $this->assertSame('skipped', WhatsAppSendRecipient::where('send_id', $pending)->first()->status);
        $this->m('GET', "/guests/{$a->id}/messages")->assertOk()->assertJsonCount(2, 'data.items');
        $aud = $this->m('GET', '/audience')->assertOk();
        $this->assertTrue($aud->json('data.guests.0.opted_out'));
        $this->assertSame(1, $aud->json('data.guests.0.used'));
    }

    public function test_intent_parser_examples(): void
    {
        $this->assertSame(2, IntentParser::parse('לצערנו לא נוכל להגיע')['suggestion']['status']);
        $this->assertSame('question', IntentParser::parse('איפה חונים?')['intent']);
        $this->assertTrue(IntentParser::isOptOut('STOP'));
        $this->assertSame(4, IntentParser::parse('נהיה 4 אנשים')['suggestion']['party_size']);
    }

    public function test_sms_fallback_for_guest_without_whatsapp(): void
    {
        $this->guest('בלי וואטסאפ', 1, ['phone' => '0501230000', 'status' => 0]);
        // D6: on by default, the host can turn it off
        $this->m('GET', '/settings')->assertJsonPath('data.sms_fallback_enabled', true)->assertJsonPath('data.sms_available', true);
        $this->m('PUT', '/settings', ['sms_fallback_enabled' => false])->assertOk();
        $this->m('POST', '/sends', ['template_key' => 'rsvp_invite', 'segment' => ['statuses' => [0]], 'send_now' => true])
            ->assertJsonPath('data.counts.failed', 1);
        $this->assertCount(0, MockSmsDriver::$outbox);
        $this->m('PUT', '/settings', ['sms_fallback_enabled' => true])->assertOk();
        $this->m('POST', '/dev/entitlement', ['topups' => 1]);
        $this->m('POST', '/sends', ['template_key' => 'rsvp_nudge', 'segment' => ['statuses' => [0]], 'send_now' => true])
            ->assertJsonPath('data.counts.sent', 1)->assertJsonPath('data.counts.sms', 1);
        $this->assertCount(1, MockSmsDriver::$outbox);
        $this->assertStringContainsString('/rsvp/', MockSmsDriver::$outbox[0]['body']);
    }

    public function test_meta_webhook_signature_and_status_updates(): void
    {
        config(['whatsapp_messaging.meta.app_secret' => 'shh', 'whatsapp_messaging.meta.verify_token' => 'vt']);
        [$a] = $this->guests(1);
        $id = $this->m('POST', '/sends', ['template_key' => 'save_the_date', 'segment' => ['statuses' => [0]], 'send_now' => true])->json('data.id');
        $wamid = WhatsAppSendRecipient::where('send_id', $id)->value('provider_message_id');

        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=vt&hub.challenge=123')->assertOk()->assertSee('123');
        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=bad&hub.challenge=123')->assertStatus(403);

        $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'statuses' => [['id' => $wamid, 'status' => 'read']],
            'messages' => [['from' => '972501230001', 'id' => 'wamid.in.1', 'type' => 'text', 'text' => ['body' => 'איפה חונים?'], 'context' => ['id' => $wamid]]],
        ]]]]]];
        $body = json_encode($payload);
        $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertStatus(401);
        $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'shh')], $body)
            ->assertOk()->assertJsonPath('stats.messages', 1)->assertJsonPath('stats.statuses', 1);
        $r = WhatsAppSendRecipient::where('send_id', $id)->first();
        $this->assertSame('read', $r->status);
        $this->assertNotNull($r->replied_at);
        $this->m('GET', '/analytics')->assertOk()->assertJsonPath('data.funnel.read', 1)->assertJsonPath('data.funnel.replied', 1);
    }

    public function test_public_rsvp_token_and_hub_form(): void
    {
        [$a] = $this->guests(1);
        $token = RsvpToken::make($a);
        $this->getJson('/api/public/rsvp/' . $token)->assertOk()->assertJsonPath('data.guest.first_name', 'אורח');
        $this->getJson('/api/public/rsvp/' . $token . 'x')->assertStatus(404);
        $this->postJson('/api/public/rsvp/' . $token, ['status' => 'yes', 'party_size' => 4, 'meal' => 'טבעוני'])->assertOk()
            ->assertJsonPath('data.guest.status', 1)->assertJsonPath('data.guest.answers.meal', 'טבעוני');
        $this->assertSame('web', $a->fresh()->rsvp_source);

        EventHub::create(['event_id' => $this->event->id, 'slug' => 'wa-hub-' . $this->event->id, 'is_published' => true, 'hub_config' => []]);
        $this->postJson('/api/public/hubs/wa-hub-' . $this->event->id . '/rsvp', ['full_name' => 'אורח חדש', 'phone' => '052-7777777', 'status' => 'no'])->assertOk();
        $new = EventGuest::where('event_id', $this->event->id)->get()->firstWhere('full_name', 'אורח חדש');
        $this->assertSame(2, (int) $new->status);
        $this->postJson('/api/public/hubs/wa-hub-' . $this->event->id . '/rsvp', ['full_name' => 'כפול', 'phone' => '+972527777777', 'status' => 'yes'])->assertOk();
        $this->assertSame(1, (int) $new->fresh()->status);
    }

    public function test_automation_materializes_and_skips_guests_who_confirmed(): void
    {
        $this->m('POST', '/dev/entitlement', ['pro_addon' => true]);
        [$a, $b] = $this->guests(2);
        $list = $this->m('GET', '/automations')->assertOk();
        $nudge = collect($list->json('data.automations'))->firstWhere('key', 'rsvp_nudge');
        $this->assertSame('off', $nudge['state']);
        // event Thu 14.01.2027 19:00 IL → -14d at 18:30 = Thu 31.12.2026 18:30 IL (16:30 UTC)
        $r = $this->m('PUT', "/automations/{$nudge['id']}", ['enabled' => true])->assertOk();
        $r->assertJsonPath('data.state', 'scheduled')->assertJsonPath('data.next_run_at', '2026-12-31T16:30:00+00:00');
        $sendId = $r->json('data.send_id');
        $this->assertSame(2, WhatsAppSendRecipient::where('send_id', $sendId)->where('status', 'pending')->count());

        $a->status = 1; // confirmed through the hub meanwhile
        $a->save();
        $c = $this->guest('חדש', 1, ['phone' => '0501239999', 'status' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-12-31 16:31:00', 'UTC'));
        (new Dispatcher())->tick();
        $rows = WhatsAppSendRecipient::where('send_id', $sendId)->get()->keyBy('guest_id');
        $this->assertSame('skipped', $rows[$a->id]->status);
        $this->assertSame('segment_changed', $rows[$a->id]->skip_reason);
        $this->assertSame('sent', $rows[$b->id]->status);
        $this->assertSame('sent', $rows[$c->id]->status); // dynamic audience picked up the new guest

        // disabling another automation cancels its occurrence
        $thanks = collect($list->json('data.automations'))->firstWhere('key', 'thank_you_gallery');
        $sid = $this->m('PUT', "/automations/{$thanks['id']}", ['enabled' => true])->json('data.send_id');
        $this->m('PUT', "/automations/{$thanks['id']}", ['enabled' => false])->assertOk()->assertJsonPath('data.state', 'off');
        $this->assertSame('cancelled', WhatsAppSend::find($sid)->status);
    }

    public function test_table_label_resolved_at_dispatch(): void
    {
        config(['seating.enabled' => true]);
        [$a] = $this->guests(1, ['status' => 1]);
        $plan = $this->api('PUT', '/seating', ['revision' => 0, 'tables' => [['key' => 't1', 'label' => '12', 'shape' => 'round', 'seats' => 10, 'side' => null, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'rot' => 0]], 'fixtures' => [], 'assignments' => [['guest_id' => $a->id, 'table_key' => 't1', 'seat' => 0]]])->assertOk();
        $res = $this->m('POST', '/sends', ['template_key' => 'table_assignment', 'segment' => ['statuses' => [1], 'has_table' => true], 'send_now' => true]);
        $res->assertStatus(201)->assertJsonPath('data.counts.sent', 1);
        $this->assertSame('12', MockWhatsAppDriver::$outbox[0]['params']['table_label']);
    }

    public function test_preview_reports_audience_cost_and_conflicts(): void
    {
        $this->guests(3);
        $this->m('POST', '/sends/preview', ['template_key' => 'rsvp_invite', 'segment' => ['statuses' => [0]], 'scheduled_at' => '2026-12-01T23:00'])
            ->assertOk()->assertJsonPath('data.audience.will_send', 3)->assertJsonPath('data.schedule.conflicts.0', 'quiet')
            ->assertJsonPath('data.needs_review', false)->assertJsonPath('data.cost.messages', 3);
        $this->m('POST', '/test-send', ['phone' => '0501234567', 'template_key' => 'rsvp_invite'])->assertOk()->assertJsonPath('data.provider', 'mock');
    }
}
