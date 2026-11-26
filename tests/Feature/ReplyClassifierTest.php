<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\Services\Messaging\Ai\MockReplyClassifier;
use App\Services\Messaging\Ai\OpenAiReplyClassifier;
use App\Services\Messaging\Ai\ReplyClassifier;
use App\Services\Messaging\Ai\ReplyClassifierFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

class ReplyClassifierTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'UTC'));
        config(['whatsapp_messaging.enabled' => true, 'whatsapp_messaging.mock.dev_endpoints' => true]);
        $this->makeHost('wacls');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inbound($guest, string $text)
    {
        // an open conversation lets the inbound matcher find the guest without a prior template send
        \App\Models\WhatsAppConversation::firstOrCreate(['event_id' => $guest->event_id, 'guest_id' => $guest->id], ['last_inbound_at' => now()]);
        return $this->api('POST', '/messaging/dev/inbound', ['guest_id' => $guest->id, 'text' => $text])->assertOk();
    }

    public function test_default_is_rules_only_and_never_applies(): void
    {
        $this->assertNull(ReplyClassifierFactory::make());
        $g = $this->guest('נועה כהן', 3, ['phone' => '058' . random_int(1000000, 9999999), 'status' => 0]);
        $this->inbound($g, 'מגיעים רק 2, אני צמחונית');
        $m = WhatsAppMessage::where('guest_id', $g->id)->where('direction', 'in')->latest('id')->first();
        $this->assertSame('rules', $m->classifier);
        $this->assertSame(['status' => 1, 'party_size' => 2, 'meal' => 'צמחוני'], $m->suggestion);
        $this->assertSame(0, (int) $g->fresh()->status); // suggestion only
        $this->assertSame(3, (int) $g->fresh()->party_size);
    }

    public function test_mock_classifier_understands_more_and_stays_a_suggestion(): void
    {
        $this->app->instance(ReplyClassifier::class, new MockReplyClassifier());
        $g = $this->guest('נועה כהן', 1, ['phone' => '058' . random_int(1000000, 9999999), 'status' => 0]);
        $this->inbound($g, 'בשמחה! נבוא סה״כ ארבעה');
        $m = WhatsAppMessage::where('guest_id', $g->id)->where('direction', 'in')->latest('id')->first();
        $this->assertSame('mock', $m->classifier);
        $this->assertSame(['status' => 1, 'party_size' => 4], $m->suggestion);
        $this->assertNotEmpty($m->suggestion_note);
        $this->assertSame(0, (int) $g->fresh()->status);
        $thread = $this->api('GET', "/messaging/inbox/{$g->id}")->assertOk();
        $last = collect($thread->json('data.messages'))->where('direction', 'in')->last();
        $this->assertSame('mock', $last['classifier']);
        $this->assertSame('mock', $thread->json('data.classifier'));
    }

    public function test_openai_result_is_sanitized(): void
    {
        Http::fake(['api.openai.test/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'intent' => 'rsvp_update', 'suggestion' => ['status' => 1, 'party_size' => 99, 'meal' => 'טבעוני', 'shuttle' => true, 'guest_id' => 5],
                'confidence' => 1.7, 'note' => 'מגיעים, טבעוני',
            ], JSON_UNESCAPED_UNICODE)]]],
        ])]);
        $this->app->instance(ReplyClassifier::class, new OpenAiReplyClassifier('sk-test', 'gpt-4o-mini', 'https://api.openai.test/v1', 5));
        $g = $this->guest('דן', 2, ['phone' => '058' . random_int(1000000, 9999999), 'status' => 0]);
        $this->inbound($g, 'נגיע כולנו, טבעונים');
        $m = WhatsAppMessage::where('guest_id', $g->id)->where('direction', 'in')->latest('id')->first();
        $this->assertSame('openai', $m->classifier);
        $this->assertSame(['status' => 1, 'party_size' => 10, 'meal' => 'טבעוני'], $m->suggestion); // clamped to max_party_size, unknown keys dropped
        $this->assertEquals(1.0, (float) $m->confidence);
        Http::assertSent(fn ($r) => $r['model'] === 'gpt-4o-mini' && $r['response_format']['type'] === 'json_object');
    }

    public function test_ai_failure_falls_back_to_rules(): void
    {
        $this->app->instance(ReplyClassifier::class, new class implements ReplyClassifier {
            public function name(): string { return 'openai'; }
            public function model(): ?string { return 'x'; }
            public function classify(string $text, array $context): array { throw new RuntimeException('timeout'); }
        });
        $g = $this->guest('דן', 2, ['phone' => '058' . random_int(1000000, 9999999), 'status' => 0]);
        $this->inbound($g, 'לצערנו לא נוכל להגיע');
        $m = WhatsAppMessage::where('guest_id', $g->id)->where('direction', 'in')->latest('id')->first();
        $this->assertSame('rules', $m->classifier);
        $this->assertSame(['status' => 2], $m->suggestion);
    }

    public function test_opt_out_never_goes_to_ai(): void
    {
        $called = false;
        $this->app->instance(ReplyClassifier::class, new class($called) implements ReplyClassifier {
            public function __construct(private bool &$called) {}
            public function name(): string { return 'openai'; }
            public function model(): ?string { return 'x'; }
            public function classify(string $text, array $context): array { $this->called = true; return ['intent' => 'other', 'suggestion' => null, 'confidence' => null, 'note' => null]; }
        });
        $g = $this->guest('דן', 2, ['phone' => '058' . random_int(1000000, 9999999), 'status' => 0]);
        $this->inbound($g, 'הסר');
        $this->assertFalse($called);
        $this->assertNotNull($g->fresh()->wa_opted_out_at);
    }

    public function test_factory_selection(): void
    {
        config(['whatsapp_messaging.ai.provider' => 'mock']);
        $this->assertSame('mock', ReplyClassifierFactory::name());
        config(['whatsapp_messaging.ai.provider' => 'rules']);
        $this->assertSame('rules', ReplyClassifierFactory::name());
    }
}
