<?php

namespace Tests\Unit;

use App\Services\Messaging\Providers\MicropaySmsDriver;
use App\Services\Messaging\Providers\ProviderFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicropaySmsDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp_messaging.sms.micropay' => ['endpoint' => 'https://sms.micropay.test/extApi/scheduleSms.php', 'token' => 'tok', 'from' => 'SnapShare', 'timeout' => 5]]);
    }

    public function test_success_parses_campaign_id_and_posts_expected_form(): void
    {
        Http::fake(['sms.micropay.test/*' => Http::response("OK 34556\n", 200)]);
        $r = (new MicropaySmsDriver())->send('+972501234567', 'שלום, אישור הגעה');
        $this->assertTrue($r->ok);
        $this->assertSame('micropay.34556', $r->id);
        Http::assertSent(fn ($req) => $req['post'] == 2 && $req['token'] === 'tok' && $req['list'] === '0501234567' && $req['from'] === 'SnapShare' && $req['msg'] === 'שלום, אישור הגעה'
            && str_contains($req->header('Content-Type')[0], 'application/x-www-form-urlencoded'));
    }

    public function test_error_is_reported(): void
    {
        Http::fake(['sms.micropay.test/*' => Http::response('ERROR --> Description: from parameter, sender id not approved', 200)]);
        $r = (new MicropaySmsDriver())->send('0501234567', 'x');
        $this->assertFalse($r->ok);
        $this->assertSame('from parameter, sender id not approved', $r->error);
    }

    public function test_factory_defaults_to_mock_and_tests_never_reach_provider(): void
    {
        config(['whatsapp_messaging.sms.driver' => 'micropay']);
        $this->assertSame('mock', ProviderFactory::sms()->name()); // testing env
        $this->assertSame('0521112222', MicropaySmsDriver::localNumber('+972-52-111-2222'));
    }
}
