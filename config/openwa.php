<?php

return [
    'api_url'         => rtrim(env('OPENWA_API_URL', 'http://localhost:2785/api'), '/'),
    'api_key'         => env('OPENWA_API_KEY'),
    'session_id'      => env('OPENWA_SESSION_ID'),
    'webhook_url'     => env('OPENWA_WEBHOOK_URL'),
    'webhook_secret'  => env('OPENWA_WEBHOOK_SECRET'),
    'webhook_id'      => env('OPENWA_WEBHOOK_ID'),
    'status'          => env('OPENWA_STATUS', 'active'),
    'idempotency_ttl' => (int) env('OPENWA_IDEMPOTENCY_TTL', 86400),
    'dashboard_url'   => env('OPENWA_DASHBOARD_URL', 'http://localhost:2886'),
];
