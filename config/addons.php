<?php

/*
| Per-event paid add-ons (WhatsApp Pro / top-up, seating add-on / AI top-up).
|
| Paid through the same PayPlus account as plan purchases (config/payment.php), but with their own
| table (addon_purchases) and callback (/api/store/addon-confirmation) so plan orders are untouched.
|
| Driver: auto = PayPlus only in production when PayPlus keys exist, otherwise the mock gateway
| (no real charge; dev "complete payment" endpoint). Force with ADDON_PAYMENT_DRIVER=payplus|mock.
*/

$localish = in_array(env('APP_ENV', 'production'), ['local', 'testing', 'development'], true);

return [
    'enabled' => filter_var(env('ADDON_PAYMENTS_ENABLED', $localish), FILTER_VALIDATE_BOOLEAN),

    'driver' => env('ADDON_PAYMENT_DRIVER', 'auto'),

    // Dev-only endpoint to complete a mock payment (never available with the PayPlus driver).
    'mock_dev_endpoints' => filter_var(env('ADDON_PAYMENT_MOCK_DEV', $localish), FILTER_VALIDATE_BOOLEAN),

    'currency' => 'ILS',

    'products' => [
        // Approved by Gal (t134u).
        'wa_pro' => [
            'title' => 'WhatsApp Pro — מחזור מלא',
            'price' => 129,
            'max_quantity' => 1,
            'feature' => 'whatsapp',
            'description' => '6 הודעות לכל אורח, ללא הגבלת מספר אורחים',
        ],
        'wa_topup' => [
            'title' => 'תוספת הודעות WhatsApp',
            'price' => 59,
            'max_quantity' => 5,
            'feature' => 'whatsapp',
            'description' => 'עוד 2 הודעות לכל אורח',
        ],
        // TODO(pricing): seating price not confirmed by Gal. Defaults per t135u brief.
        'seating_addon' => [
            'title' => 'הושבה חכמה',
            'price' => (float) env('SEATING_ADDON_PRICE', 199),
            'max_quantity' => 1,
            'feature' => 'seating',
            'description' => 'בונה ההושבה + 5 סידורים חכמים (AI) כלולים',
        ],
        'seating_ai_topup' => [
            'title' => 'עוד סידורים חכמים',
            'price' => (float) env('SEATING_AI_TOPUP_PRICE', 19),
            'max_quantity' => 5,
            'feature' => 'seating',
            'runs' => (int) env('SEATING_AI_TOPUP_RUNS', 10),
            'description' => '10 סידורים חכמים נוספים',
        ],
    ],
];
