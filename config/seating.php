<?php

/*
| Seating planner (guest CRM + floor plan + AI smart arrange).
|
| TODO(pricing): seating builder + AI are planned as a paid per-event add-on.
| Packaging and price are NOT decided yet (Gal). Until then the feature is
| behind this flag. Do not wire checkout / PayPal / PayPlus here.
*/

return [

    // ON by default only for local/testing environments. Set SEATING_ENABLED=true|false to override.
    'enabled' => filter_var(
        env('SEATING_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing', 'development'], true)),
        FILTER_VALIDATE_BOOLEAN
    ),

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
