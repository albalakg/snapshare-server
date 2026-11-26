<?php

/*
| Seating planner (guest CRM + floor plan + AI smart arrange).
|
| TODO(pricing): seating builder + AI are a paid per-event add-on (paywall below, OFF by default).
| Checkout goes through config/addons.php (PayPlus, same account as plans). Price not confirmed by Gal.
*/

return [

    // ON by default only for local/testing environments. Set SEATING_ENABLED=true|false to override.
    'enabled' => filter_var(
        env('SEATING_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing', 'development'], true)),
        FILTER_VALIDATE_BOOLEAN
    ),

    // Paywall (t135u). OFF by default = today's behaviour (flag only). When ON, the builder + AI need the
    // paid seating add-on (config/addons.php seating_addon) unless the event's plan is in included_subscriptions.
    // TODO(pricing): ₪199 bundled (5 AI runs included), AI top-up ₪19 / 10 runs — not confirmed by Gal.
    'paywall' => [
        'enabled' => filter_var(env('SEATING_PAYWALL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // subscription ids whose events get seating without buying (e.g. [3] = Premium). Empty = everyone pays.
        'included_subscriptions' => array_values(array_filter(array_map('intval', explode(',', (string) env('SEATING_INCLUDED_SUBSCRIPTIONS', ''))))),
    ],

    // Public "your table" page (/t/{token}): show the floor map with the guest's table highlighted.
    'table_page' => [
        'show_map' => filter_var(env('SEATING_TABLE_PAGE_MAP', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Default table / limits
    'max_tables' => 200,
    'max_seats_per_table' => 30,
    'min_seats_per_table' => 2,

    'ai' => [
        // auto = OpenAI when OPENAI_API_KEY is set, otherwise the local mock provider.
        'provider' => env('SEATING_AI_PROVIDER', 'auto'),
        'model' => env('SEATING_AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('SEATING_AI_TIMEOUT', 45),

        // Successful AI runs per event. Failed runs are not counted. Packer (non-AI) runs are free.
        'runs_per_event' => (int) env('SEATING_AI_RUNS_PER_EVENT', 5),

        // Hard safety caps
        'max_guests' => 1500,
        'max_input_tokens' => 60000,
        'max_instructions_chars' => 500,

        // USD per 1M tokens (gpt-4o-mini list price) and ILS rate, for the cost estimate shown to the host.
        'price_in_per_m' => (float) env('SEATING_AI_PRICE_IN', 0.15),
        'price_out_per_m' => (float) env('SEATING_AI_PRICE_OUT', 0.60),
        'usd_to_ils' => (float) env('SEATING_USD_TO_ILS', 3.7),
    ],
];
