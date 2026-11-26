<?php

/*
| WhatsApp guest messaging (templates, editable send queue, automations, RSVP bot, inbox, SMS fallback).
| PLAN: snapshare/whatsapp/PLAN.md (t134u decisions D1–D8).
|
| The legacy OpenWA campaigns (config/openwa.php, /whatsapp/* routes, whatsapp_campaigns) are untouched;
| this feature lives under /api/events/{id}/messaging/* with its own tables.
*/

$isDevEnv = in_array(env('APP_ENV', 'production'), ['local', 'testing', 'development'], true);

return [

    // Master flag. ON by default only for local/testing. WHATSAPP_MESSAGING_ENABLED=true|false overrides.
    'enabled' => filter_var(env('WHATSAPP_MESSAGING_ENABLED', $isDevEnv), FILTER_VALIDATE_BOOLEAN),

    // Provider: auto = Meta Cloud API when WHATSAPP_ACCESS_TOKEN + WHATSAPP_PHONE_NUMBER_ID exist, else mock.
    // The testing environment is always forced to mock (see WhatsAppProviderFactory) so tests never reach Meta.
    'driver' => env('WHATSAPP_DRIVER', 'auto'), // auto | meta | mock

    // D2: one shared SnapShare sender number for every event. D4: Meta Cloud API directly (no BSP).
    'meta' => [
        'graph_url'       => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version'   => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'access_token'    => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'waba_id'         => env('WHATSAPP_WABA_ID'),
        'app_secret'      => env('WHATSAPP_APP_SECRET'),
        'verify_token'    => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'timeout'         => (int) env('WHATSAPP_TIMEOUT', 20),
        // Prefix for template names submitted to Meta (system templates + host custom templates).
        'template_prefix' => env('WHATSAPP_TEMPLATE_PREFIX', 'snapshare'),
    ],

    // Mock driver behaviour (local / testing). Real messages are NEVER sent by the mock.
    'mock' => [
        // Seconds after submission until a pending custom template is auto-approved on the next tick.
        'approve_after_seconds' => (int) env('WHATSAPP_MOCK_APPROVE_AFTER', 30),
        // Phones whose digits end with this suffix behave as "not on WhatsApp" (error 131026) for QA of SMS fallback.
        'no_whatsapp_suffix' => env('WHATSAPP_MOCK_NO_WA_SUFFIX', '0000'),
        // Dev-only helper endpoints (simulate inbound replies, approve/reject templates). Never in production.
        'dev_endpoints' => filter_var(env('WHATSAPP_MOCK_DEV_ENDPOINTS', $isDevEnv), FILTER_VALIDATE_BOOLEAN),
    ],

    // D6: SMS fallback for guests without WhatsApp. Mock is the default; Micropay (cheapest Israeli provider with
    // an HTTP API, t135u research) is wired and used only with SMS_DRIVER=micropay + MICROPAY_SMS_TOKEN.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'mock'), // mock | micropay
        'sender' => env('SMS_SENDER', 'SnapShare'), // 2-11 latin chars/digits, or a verified phone number
        'max_chars' => 268, // 4 Hebrew (UCS-2) segments
        'micropay' => [
            'endpoint' => env('MICROPAY_SMS_ENDPOINT', 'https://www.micropay.co.il/extApi/scheduleSms.php'),
            'token'    => env('MICROPAY_SMS_TOKEN'), // "טוקנים לממשקים" token with SMS permission
            'from'     => env('MICROPAY_SMS_FROM'), // optional; falls back to sms.sender / the token's default sender id
            'timeout'  => (int) env('MICROPAY_SMS_TIMEOUT', 15),
        ],
    ],

    // Free-text reply classifier (suggestions only, never auto-applied). auto = OpenAI when a key exists, else rules.
    'ai' => [
        'provider' => env('WHATSAPP_AI_PROVIDER', 'auto'), // auto | openai | mock | rules
        'model'    => env('WHATSAPP_AI_MODEL', 'gpt-4o-mini'),
        'api_key'  => env('WHATSAPP_OPENAI_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout'  => (int) env('WHATSAPP_AI_TIMEOUT', 8),
    ],

    /*
    | D1/D3 quotas — prices and packaging confirmed by Gal on 26.11.2026 (t134u). Checkout (t135u) is the per-event
    | add-on flow in config/addons.php (keep wa_pro / wa_topup prices there in sync with the values below).
    | The existing subscription prices (Trial free, Classic ₪200, Premium ₪300) are unchanged.
    | Messages are counted per guest (household row), only for template sends accepted by the provider.
    | Free-form replies inside the 24h service window (RSVP bot, inbox replies) are not counted.
    */
    'pricing' => [
        // Kill switch for the Pro / top-up checkout buttons (the checkout itself is config/addons.php → ADDON_PAYMENTS_ENABLED).
        'enabled' => filter_var(env('WHATSAPP_PRICING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'currency' => 'ILS',
        'packages' => [
            // subscription_id => messages per guest / max guests that can receive messages
            1 => ['key' => 'trial',   'label' => 'Trial',   'per_guest' => 1, 'max_guests' => 15],
            2 => ['key' => 'classic', 'label' => 'Classic', 'per_guest' => 2, 'max_guests' => null],
            3 => ['key' => 'premium', 'label' => 'Premium', 'per_guest' => 4, 'max_guests' => null],
        ],
        // Event without an order (local dev, legacy) uses this package id.
        'default_package_id' => (int) env('WHATSAPP_DEFAULT_PACKAGE_ID', 1),
        'pro_addon' => ['key' => 'pro', 'label' => 'Pro', 'price' => 129, 'per_guest' => 6],
        'topup' => ['key' => 'topup', 'label' => '+2 לכל אורח', 'price' => 59, 'per_guest' => 2],
    ],

    // D7: Israel quiet hours + Shabbat guard. ON by default; host can turn each off per event.
    'timezone' => 'Asia/Jerusalem',
    'quiet_hours' => ['start' => '21:30', 'end' => '08:30'],
    'shabbat' => ['start_day' => 5, 'start' => '15:00', 'end_day' => 6, 'end' => '20:30'], // Fri 15:00 → Sat 20:30

    // Safety limits
    'test_sends_per_event' => (int) env('WHATSAPP_TEST_SENDS_PER_EVENT', 20),
    'dispatch_batch' => (int) env('WHATSAPP_DISPATCH_BATCH', 200),
    'service_window_hours' => 24,
    'max_body_chars' => 1024,
    'max_buttons' => 3,

    // Meta Israel (+972) rates per delivered message (Oct 2026) for host-facing cost estimates only.
    'rates_usd' => ['marketing' => 0.0353, 'utility' => 0.0053, 'service' => 0.0],
    'usd_to_ils' => (float) env('WHATSAPP_USD_TO_ILS', 3.7),
];
