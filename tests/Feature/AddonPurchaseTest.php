<?php

namespace Tests\Feature;

use App\Models\AddonPurchase;
use App\Models\EventEntitlement;
use App\Models\EventWhatsAppSetting;
use App\Models\Order;
use App\Services\Addons\AddonGatewayFactory;
use App\Services\Addons\AddonPurchaseService;
use App\Services\Helpers\EnvService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

class AddonPurchaseTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'addons.enabled' => true, 'addons.driver' => 'mock', 'addons.mock_dev_endpoints' => true,
            'whatsapp_messaging.enabled' => true, 'seating.enabled' => true, 'seating.paywall.enabled' => false,
            'app.client_url' => 'http://client.test', 'app.url' => 'http://api.test',
        ]);
        $this->makeHost('addon');
    }

    private function checkout(string $product, int $qty = 1)
    {
        return $this->api('POST', '/addons/checkout', ['product' => $product, 'quantity' => $qty]);
    }

    private function payplusConfig(): void
    {
        config([
            'addons.driver' => 'payplus',
            'payment.payplus.address' => 'https://payplus.test/api/v1.0/', 'payment.payplus.api_key' => 'k', 'payment.payplus.secret_key' => 'shh',
            'payment.payplus.page_uuid' => 'page-uuid', 'payment.payplus.currency_code' => 'ILS', 'payment.payplus.expiry_datetime' => 30,
        ]);
    }

    private function payplusCallback(array $body, ?string $hash = null, ?string $ua = null)
    {
        $raw = json_encode($body);
        $hash ??= base64_encode(hash_hmac('sha256', $raw, 'shh', true));
        $ua ??= EnvService::isLocal() ? 'PostmanRuntime/7.32.3' : 'PayPlus';

        return $this->call('POST', '/api/store/addon-confirmation', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_HASH' => $hash, 'HTTP_USER_AGENT' => $ua,
        ], $raw);
    }

    public function test_catalog_lists_products_with_approved_prices(): void
    {
        $r = $this->api('GET', '/addons')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.driver', 'mock');
        $p = collect($r->json('data.products'))->keyBy('key');
        $this->assertEquals(129, $p['wa_pro']['price']);
        $this->assertEquals(59, $p['wa_topup']['price']);
        $this->assertEquals(199, $p['seating_addon']['price']);
        $this->assertEquals(19, $p['seating_ai_topup']['price']);
        $this->assertSame(10, $p['seating_ai_topup']['runs']);
        // WhatsApp add-on prices stay in sync with the quota config.
        $this->assertEquals(config('whatsapp_messaging.pricing.pro_addon.price'), config('addons.products.wa_pro.price'));
        $this->assertEquals(config('whatsapp_messaging.pricing.topup.price'), config('addons.products.wa_topup.price'));
    }

    public function test_flag_off_blocks_checkout(): void
    {
        config(['addons.enabled' => false]);
        $this->checkout('wa_pro')->assertStatus(403);
        $this->assertSame(0, AddonPurchase::where('event_id', $this->event->id)->count());
    }

    public function test_mock_checkout_and_dev_complete_credits_wa_pro_once(): void
    {
        $orders = Order::count();
        $r = $this->checkout('wa_pro')->assertStatus(201)->assertJsonPath('data.purchase.status', 'in_progress')->assertJsonPath('data.purchase.amount', 129);
        $uid = $r->json('data.purchase.uid');
        $this->assertStringStartsWith('http://client.test/addons/mock-checkout?purchase=' . $uid, $r->json('data.payment_page_link'));

        $this->api('POST', "/addons/purchases/{$uid}/dev-complete", ['outcome' => 'success'])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.credited', true);
        $this->assertTrue((bool) EventWhatsAppSetting::where('event_id', $this->event->id)->value('pro_addon'));
        // idempotent: a second completion changes nothing
        $this->api('POST', "/addons/purchases/{$uid}/dev-complete", ['outcome' => 'success'])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, AddonPurchase::where('event_id', $this->event->id)->where('status', 'paid')->count());
        // quota reflects Pro (6 per guest), and Pro can't be bought twice
        $this->api('GET', '/messaging/overview')->assertOk()->assertJsonPath('data.quota.pro_addon', true)->assertJsonPath('data.quota.per_guest', 6)->assertJsonPath('data.quota.pricing.checkout_enabled', true);
        $this->checkout('wa_pro')->assertStatus(409);
        // plan orders untouched
        $this->assertSame($orders, Order::count());
    }

    public function test_topup_quantity_adds_topups_and_validates_range(): void
    {
        $this->checkout('wa_topup', 6)->assertStatus(422);
        $uid = $this->checkout('wa_topup', 2)->assertStatus(201)->assertJsonPath('data.purchase.amount', 118)->json('data.purchase.uid');
        $svc = new AddonPurchaseService();
        $id = AddonPurchase::where('uid', $uid)->value('id');
        $svc->markPaid($id, 'A1');
        $svc->markPaid($id, 'A1'); // duplicate callback
        $this->assertSame(2, (int) EventWhatsAppSetting::where('event_id', $this->event->id)->value('topups'));
        $this->api('GET', '/messaging/overview')->assertJsonPath('data.quota.per_guest', 1 + 2 * 2); // trial 1 + 2 top-ups × 2
    }

    public function test_failure_and_cancel_do_not_credit(): void
    {
        $uid = $this->checkout('wa_topup')->json('data.purchase.uid');
        $this->api('POST', "/addons/purchases/{$uid}/dev-complete", ['outcome' => 'failure'])->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.credited', false);
        $this->assertSame(0, (int) EventWhatsAppSetting::where('event_id', $this->event->id)->value('topups'));
        $this->actingAs($this->user, 'api')->getJson('/api/store/addons/' . $uid)->assertOk()->assertJsonPath('data.status', 'failed');
    }

    public function test_other_user_cannot_buy_or_read(): void
    {
        $uid = $this->checkout('wa_topup')->json('data.purchase.uid');
        $other = $this->makeUser('addon-foreign');
        $this->actingAs($other, 'api')->postJson("/api/events/{$this->event->id}/addons/checkout", ['product' => 'wa_topup'])->assertStatus(404);
        $this->actingAs($other, 'api')->getJson('/api/store/addons/' . $uid)->assertStatus(404);
        $this->actingAs($other, 'api')->postJson("/api/events/{$this->event->id}/addons/purchases/{$uid}/dev-complete")->assertStatus(404);
    }

    public function test_dev_complete_is_404_with_payplus_driver(): void
    {
        $uid = $this->checkout('wa_topup')->json('data.purchase.uid');
        config(['addons.driver' => 'payplus']);
        $this->api('POST', "/addons/purchases/{$uid}/dev-complete")->assertStatus(404);
    }

    public function test_auto_driver_is_mock_outside_production(): void
    {
        $this->payplusConfig();
        config(['addons.driver' => 'auto']);
        $this->assertSame('mock', AddonGatewayFactory::driver());
    }

    public function test_payplus_checkout_and_signed_callback_credits_seating_ai_runs(): void
    {
        $this->payplusConfig();
        Http::fake([
            'payplus.test/api/v1.0/PaymentPages/generateLink' => Http::response(['results' => ['status' => 'success'], 'data' => ['page_request_uid' => 'pp-req-1', 'payment_page_link' => 'https://payments.payplus.test/pp-req-1']]),
            'payplus.test/api/v1.0/PaymentPages/ipn' => Http::response(['results' => ['status' => 'success']]),
        ]);
        $r = $this->checkout('seating_ai_topup')->assertStatus(201)->assertJsonPath('data.payment_page_link', 'https://payments.payplus.test/pp-req-1');
        Http::assertSent(function ($req) {
            if (!str_ends_with($req->url(), 'generateLink')) {
                return false;
            }

            return $req['amount'] == 19 && $req['payment_page_uid'] === 'page-uuid' && $req['refURL_callback'] === 'http://api.test/api/store/addon-confirmation'
                && str_starts_with($req['refURL_success'], 'http://client.test/addons/return?purchase=') && $req->header('api-key')[0] === 'k';
        });

        $body = ['transaction' => ['payment_page_request_uid' => 'pp-req-1', 'approval_number' => '0012345', 'amount' => 19]];
        // bad signature → rejected, nothing credited
        $this->payplusCallback($body, 'bad-hash')->assertStatus(400);
        // wrong user agent → rejected
        $this->payplusCallback($body, null, 'curl/8')->assertStatus(400);
        $this->assertSame(0, (int) EventEntitlement::where('event_id', $this->event->id)->value('seating_ai_extra_runs'));

        $this->payplusCallback($body)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->payplusCallback($body)->assertOk(); // PayPlus retries → no double credit
        $this->assertSame(10, (int) EventEntitlement::where('event_id', $this->event->id)->value('seating_ai_extra_runs'));
        $this->api('GET', '/seating/ai/quota')->assertOk()->assertJsonPath('data.included', 15)->assertJsonPath('data.extra', 10);
        $this->assertSame('0012345', AddonPurchase::where('uid', $r->json('data.purchase.uid'))->value('approval_number'));
    }

    public function test_callback_rejected_when_ipn_fails_or_unknown_request(): void
    {
        $this->payplusConfig();
        Http::fake([
            'payplus.test/api/v1.0/PaymentPages/generateLink' => Http::response(['results' => ['status' => 'success'], 'data' => ['page_request_uid' => 'pp-req-2', 'payment_page_link' => 'https://p/2']]),
            'payplus.test/api/v1.0/PaymentPages/ipn' => Http::response(['results' => ['status' => 'error']]),
        ]);
        $this->checkout('wa_pro')->assertStatus(201);
        $this->payplusCallback(['transaction' => ['payment_page_request_uid' => 'pp-req-2', 'approval_number' => '1']])->assertStatus(400);
        $this->payplusCallback(['transaction' => ['payment_page_request_uid' => 'nope', 'approval_number' => '1']])->assertStatus(404);
        $this->assertFalse((bool) EventWhatsAppSetting::where('event_id', $this->event->id)->value('pro_addon'));
    }

    public function test_payplus_page_error_marks_failed(): void
    {
        $this->payplusConfig();
        Http::fake(['*' => Http::response(['results' => ['status' => 'error', 'description' => 'x']], 200)]);
        $this->checkout('wa_topup')->assertStatus(502);
        $this->assertSame('failed', AddonPurchase::where('event_id', $this->event->id)->value('status'));
    }

    public function test_plan_prices_unchanged(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/SubscriptionPlanSeeder.php'));
        $this->assertSame(1, preg_match_all("/'price'\s*=>\s*0,/", $seeder));
        $this->assertSame(1, preg_match_all("/'price'\s*=>\s*200,/", $seeder));
        $this->assertSame(1, preg_match_all("/'price'\s*=>\s*300,/", $seeder));
    }
}
