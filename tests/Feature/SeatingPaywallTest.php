<?php

namespace Tests\Feature;

use App\Models\AddonPurchase;
use App\Models\Order;
use App\Services\Addons\AddonPurchaseService;
use App\Services\Seating\TableToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\CreatesSeatingHost;
use Tests\TestCase;

class SeatingPaywallTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesSeatingHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeHost('paywall');
        config([
            'seating.enabled' => true, 'seating.paywall.enabled' => true, 'seating.paywall.included_subscriptions' => [],
            'seating.ai.provider' => 'mock', 'seating.ai.api_key' => null, 'seating.ai.runs_per_event' => 5,
            'addons.enabled' => true, 'addons.driver' => 'mock', 'addons.mock_dev_endpoints' => true, 'app.client_url' => 'http://client.test',
        ]);
    }

    private function savePlan(array $assignments = [])
    {
        return $this->api('PUT', '/seating', [
            'revision' => 0,
            'tables' => [
                ['key' => 'a', 'label' => '7', 'shape' => 'round', 'seats' => 10, 'side' => null, 'x' => 40, 'y' => 40, 'w' => 160, 'h' => 160, 'rot' => 0],
                ['key' => 'b', 'label' => 'משפחה', 'shape' => 'rect', 'seats' => 12, 'side' => null, 'x' => 300, 'y' => 40, 'w' => 240, 'h' => 100, 'rot' => 0],
            ],
            'fixtures' => [['id' => 'stage', 'kind' => 'stage', 'label' => 'במה', 'x' => 40, 'y' => 400, 'w' => 300, 'h' => 80, 'rot' => 0]],
            'assignments' => $assignments,
        ]);
    }

    private function unlock(): void
    {
        $uid = $this->api('POST', '/addons/checkout', ['product' => 'seating_addon'])->assertStatus(201)->json('data.purchase.uid');
        $this->api('POST', "/addons/purchases/{$uid}/dev-complete", ['outcome' => 'success'])->assertOk()->assertJsonPath('data.credited', true);
    }

    public function test_paywall_off_keeps_flag_behaviour(): void
    {
        config(['seating.paywall.enabled' => false]);
        $this->savePlan()->assertOk();
        $this->api('GET', '/seating')->assertOk()->assertJsonPath('data.entitlement.enabled', true)->assertJsonPath('data.entitlement.source', 'feature_flag');
    }

    public function test_locked_event_gets_402_with_paywall_payload_and_crm_stays_free(): void
    {
        $this->api('GET', '/seating')->assertOk()->assertJsonPath('data.entitlement.enabled', false)->assertJsonPath('data.entitlement.source', 'locked');
        $r = $this->savePlan()->assertStatus(402);
        $this->assertEquals(199, $r->json('data.entitlement.products.seating_addon.price'));
        $this->api('POST', '/seating/ai/runs', [])->assertStatus(402);
        $this->api('POST', '/guests', ['full_name' => 'אורח חופשי'])->assertSuccessful();
        // AI top-up can't be bought before the add-on
        $this->api('POST', '/addons/checkout', ['product' => 'seating_ai_topup'])->assertStatus(402);
    }

    public function test_purchase_unlocks_seating_and_topup_adds_runs(): void
    {
        $this->unlock();
        $this->api('GET', '/seating')->assertJsonPath('data.entitlement.enabled', true)->assertJsonPath('data.entitlement.source', 'purchase');
        $this->savePlan()->assertOk();
        $this->api('POST', '/addons/checkout', ['product' => 'seating_addon'])->assertStatus(409);
        $this->api('GET', '/seating/ai/quota')->assertJsonPath('data.included', 5);

        $uid = $this->api('POST', '/addons/checkout', ['product' => 'seating_ai_topup', 'quantity' => 2])->assertStatus(201)->assertJsonPath('data.purchase.amount', 38)->json('data.purchase.uid');
        $id = AddonPurchase::where('uid', $uid)->value('id');
        (new AddonPurchaseService())->markPaid($id, 'X');
        (new AddonPurchaseService())->markPaid($id, 'X');
        $this->api('GET', '/seating/ai/quota')->assertJsonPath('data.included', 25)->assertJsonPath('data.extra', 20)->assertJsonPath('data.remaining', 25);
    }

    public function test_included_subscription_unlocks_without_purchase(): void
    {
        $order = new Order();
        $order->forceFill(['user_id' => $this->user->id, 'subscription_id' => 3, 'status' => 1, 'price' => 300, 'order_number' => 'T' . random_int(1000000, 9999999)]);
        try {
            $order->save();
        } catch (\Throwable $e) {
            $this->markTestSkipped('orders schema differs: ' . $e->getMessage());
        }
        $this->event->order_id = $order->id;
        $this->event->save();
        config(['seating.paywall.included_subscriptions' => [3]]);
        $this->api('GET', '/seating')->assertJsonPath('data.entitlement.enabled', true)->assertJsonPath('data.entitlement.source', 'plan');
    }

    public function test_public_table_page(): void
    {
        $this->unlock();
        $seated = $this->guest('דנה לוי', 2, ['status' => 1]);
        $waiting = $this->guest('יוסי כהן', 1, ['status' => 1]);
        $declined = $this->guest('רון', 1, ['status' => 2]);
        $this->savePlan([['guest_id' => $seated->id, 'table_key' => 'b', 'seat' => 0]])->assertOk();

        $r = $this->getJson('/api/public/table/' . TableToken::make($seated))->assertOk()
            ->assertJsonPath('data.state', 'assigned')->assertJsonPath('data.guest.first_name', 'דנה')
            ->assertJsonPath('data.table.label', 'משפחה')->assertJsonPath('data.table.number', 2);
        $tables = $r->json('data.map.tables');
        $this->assertCount(2, $tables);
        $this->assertTrue(collect($tables)->firstWhere('label', 'משפחה')['mine']);
        $this->assertFalse(collect($tables)->firstWhere('label', '7')['mine']);
        $this->assertSame('stage', $r->json('data.map.fixtures.0.kind'));
        $this->assertStringNotContainsString('יוסי', $r->getContent()); // no other guests' names

        $this->getJson('/api/public/table/' . TableToken::make($waiting))->assertOk()->assertJsonPath('data.state', 'unassigned')->assertJsonPath('data.table', null);
        $this->getJson('/api/public/table/' . TableToken::make($declined))->assertOk()->assertJsonPath('data.state', 'declined');

        config(['seating.table_page.show_map' => false]);
        $this->getJson('/api/public/table/' . TableToken::make($seated))->assertOk()->assertJsonPath('data.map', null);

        // tampered / wrong-context tokens
        $tok = TableToken::make($seated);
        $this->getJson('/api/public/table/' . substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a'))->assertStatus(404);
        $this->getJson('/api/public/table/' . \App\Services\Messaging\RsvpToken::make($seated))->assertStatus(404);

        // link variable
        $vars = (new \App\Services\Messaging\VariableResolver($this->event))->forGuest($seated);
        $this->assertSame('http://client.test/t/' . $tok, $vars['table_link']);
    }

    public function test_table_page_unavailable_when_locked(): void
    {
        $g = $this->guest('דנה', 1, ['status' => 1]);
        $this->getJson('/api/public/table/' . TableToken::make($g))->assertOk()->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.table', null);
        config(['seating.enabled' => false]);
        $this->getJson('/api/public/table/' . TableToken::make($g))->assertStatus(404);
    }
}
