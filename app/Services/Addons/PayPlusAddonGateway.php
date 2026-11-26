<?php

namespace App\Services\Addons;

use App\Models\AddonPurchase;
use App\Models\User;
use App\Services\Helpers\EnvService;
use App\Services\Helpers\LogService;
use Exception;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Add-on checkout through the SAME PayPlus account/payment page as plan purchases (config/payment.php),
 * mirroring PayPlusProvider (generateLink → hosted page → signed callback → IPN check), but bound to
 * addon_purchases and its own callback URL so plan orders are untouched.
 */
class PayPlusAddonGateway implements AddonPaymentGateway
{
    public const PAGE_GENERATION_PATH = 'PaymentPages/generateLink';
    public const IPN_PATH = 'PaymentPages/ipn';

    private LogService $log;

    public function __construct()
    {
        $this->log = new LogService('payment');
    }

    public function name(): string
    {
        return 'payplus';
    }

    public static function configured(): bool
    {
        return (string) config('payment.payplus.address') !== ''
            && (string) config('payment.payplus.api_key') !== ''
            && (string) config('payment.payplus.secret_key') !== ''
            && (string) config('payment.payplus.page_uuid') !== '';
    }

    public function createPaymentPage(AddonPurchase $purchase, User $user): array
    {
        $client = rtrim((string) config('app.client_url'), '/');
        $return = $client . '/addons/return?purchase=' . urlencode($purchase->uid);
        $payload = [
            'payment_page_uid' => (string) config('payment.payplus.page_uuid'),
            'charge_method'    => 1,
            'amount'           => (float) $purchase->amount,
            'currency_code'    => (string) config('payment.payplus.currency_code', 'ILS'),
            'expiry_datetime'  => (int) config('payment.payplus.expiry_datetime', 30),
            'refURL_success'   => $return . '&status=success',
            'refURL_failure'   => $return . '&status=failure',
            'refURL_cancel'    => $return . '&status=cancel',
            'refURL_callback'  => rtrim((string) config('app.url'), '/') . '/api/store/addon-confirmation',
            'initial_invoice'  => true,
            'payments'         => 1,
            'sendEmailApproval' => true,
            'sendEmailFailure' => false,
            'send_failure_callback' => false,
            'create_hash'      => false,
            'more_info'        => $purchase->uid,
            'items'            => [[
                'name'     => (string) config("addons.products.{$purchase->product}.title", $purchase->product),
                'quantity' => (int) $purchase->quantity,
                'price'    => (float) $purchase->unit_price,
            ]],
            'customer' => [
                'customer_name' => method_exists($user, 'getFullName') ? $user->getFullName() : (string) $user->email,
                'email'         => (string) $user->email,
            ],
        ];

        $url = rtrim((string) config('payment.payplus.address'), '/') . '/' . self::PAGE_GENERATION_PATH;
        $this->log->info('Addon: PayPlus generateLink', ['purchase' => $purchase->uid, 'amount' => $purchase->amount]);
        $res = Http::withHeaders($this->auth())->timeout(20)->post($url, $payload);
        $json = $res->json();
        if (!$res->successful() || data_get($json, 'results.status') !== 'success' || !data_get($json, 'data.page_request_uid')) {
            $this->log->error('Addon: PayPlus generateLink failed', ['purchase' => $purchase->uid, 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)]);
            throw new RuntimeException('Payment page could not be created');
        }

        return [
            'page_request_uid'  => (string) data_get($json, 'data.page_request_uid'),
            'payment_page_link' => (string) data_get($json, 'data.payment_page_link'),
        ];
    }

    public function verifyCallback(array $data, string $raw_body): bool
    {
        $approval = $data['approval_number'] ?? null;
        if (!is_string($approval) || $approval === '') {
            $this->log->error('Addon callback: missing approval number');

            return false;
        }
        $expectedBrowser = EnvService::isLocal() ? 'PostmanRuntime/7.32.3' : 'PayPlus';
        if (($data['browser'] ?? null) !== $expectedBrowser) {
            $this->log->error('Addon callback: unexpected user agent', ['browser' => $data['browser'] ?? null]);

            return false;
        }
        $hash = $data['hash'] ?? null;
        if (!is_string($hash) || $hash === '' || $raw_body === '') {
            $this->log->error('Addon callback: missing hash/body');

            return false;
        }
        // PayPlus signs the exact raw body bytes (see PayPlusProvider::isHashValid).
        $calc = base64_encode(hash_hmac('sha256', $raw_body, (string) config('payment.payplus.secret_key'), true));
        if (!hash_equals($calc, $hash)) {
            $this->log->error('Addon callback: hash mismatch');

            return false;
        }

        return $this->ipnVerified((string) ($data['page_request_uid'] ?? ''));
    }

    private function ipnVerified(string $page_request_uid): bool
    {
        if ($page_request_uid === '') {
            return false;
        }
        try {
            $url = rtrim((string) config('payment.payplus.address'), '/') . '/' . self::IPN_PATH;
            $res = Http::withHeaders($this->auth())->timeout(20)->post($url, ['payment_request_uid' => $page_request_uid]);

            return data_get($res->json(), 'results.status') === 'success';
        } catch (Exception $ex) {
            $this->log->critical($ex);

            return false;
        }
    }

    private function auth(): array
    {
        return [
            'api-key'    => (string) config('payment.payplus.api_key'),
            'secret-key' => (string) config('payment.payplus.secret_key'),
        ];
    }
}
