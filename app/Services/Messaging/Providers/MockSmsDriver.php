<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Mock SMS: logs only, never sends. */
class MockSmsDriver implements SmsProviderInterface
{
    public static array $outbox = [];

    public function name(): string
    {
        return 'mock';
    }

    public function send(string $to, string $body): ProviderResult
    {
        $id = 'sms.mock.' . Str::lower(Str::random(12));
        self::$outbox[] = ['id' => $id, 'to' => $to, 'body' => $body];
        Log::info('[sms-mock] accepted', ['id' => $id, 'to_suffix' => substr($to, -3), 'chars' => mb_strlen($body)]);

        return ProviderResult::ok($id);
    }
}
