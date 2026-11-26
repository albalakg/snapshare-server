<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Micropay (מיקרופיי) SMS — https://site.micropay.co.il/api/send-sms.php
 * POST https://www.micropay.co.il/extApi/scheduleSms.php  post=2&token=&from=&msg=&list=
 * Plain-text reply: "OK <campaignId>" or "ERROR --> Description: ...".
 * Pricing (t135u): 5,000 msgs ₪100 (2 ag.), down to 1 ag. at 100k, no subscription, no expiry; 134 Hebrew chars = 1 unit.
 */
class MicropaySmsDriver implements SmsProviderInterface
{
    public function name(): string
    {
        return 'micropay';
    }

    public static function configured(): bool
    {
        return (string) config('whatsapp_messaging.sms.micropay.token') !== '';
    }

    public function send(string $to, string $body): ProviderResult
    {
        $cfg = (array) config('whatsapp_messaging.sms.micropay');
        $from = (string) ($cfg['from'] ?? '') ?: (string) config('whatsapp_messaging.sms.sender', '');
        $params = ['post' => 2, 'token' => (string) ($cfg['token'] ?? ''), 'msg' => $body, 'list' => self::localNumber($to)];
        if ($from !== '') {
            $params['from'] = $from;
        }
        try {
            $res = Http::asForm()->timeout((int) ($cfg['timeout'] ?? 15))->post((string) $cfg['endpoint'], $params);
        } catch (Throwable $e) {
            Log::warning('[sms-micropay] transport error', ['to_suffix' => substr($to, -3), 'error' => $e->getMessage()]);

            return ProviderResult::fail('transport', 'SMS provider unreachable');
        }
        $text = trim((string) $res->body());
        if ($res->successful() && preg_match('/^OK\s*(\S*)/i', $text, $m)) {
            $id = 'micropay.' . ($m[1] !== '' ? $m[1] : substr(sha1($to . microtime()), 0, 10));
            Log::info('[sms-micropay] accepted', ['id' => $id, 'to_suffix' => substr($to, -3), 'chars' => mb_strlen($body)]);

            return ProviderResult::ok($id);
        }
        $error = preg_replace('/^ERROR\s*(-->)?\s*(Description:)?\s*/i', '', $text) ?: ('HTTP ' . $res->status());
        Log::warning('[sms-micropay] rejected', ['to_suffix' => substr($to, -3), 'status' => $res->status(), 'error' => mb_substr($error, 0, 200)]);

        return ProviderResult::fail('micropay', $error);
    }

    /** E.164 +9725XXXXXXXX → 05XXXXXXXX (Micropay accepts local or international; local is safest). */
    public static function localNumber(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($d, '972')) {
            $d = '0' . substr($d, 3);
        }

        return $d;
    }
}
