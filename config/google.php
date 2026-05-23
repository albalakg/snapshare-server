<?php

$clientUrls = env('APP_CLIENT_URLS');

return [
    'allowed_redirect_origins' => $clientUrls
        ? array_values(array_filter(array_map('trim', explode(',', $clientUrls))))
        : [rtrim(env('APP_CLIENT_URL', 'https://snapshare-live.com'), '/')],

    'auth_code_ttl' => (int) env('GOOGLE_AUTH_CODE_TTL', 60),

    'state_ttl' => (int) env('GOOGLE_STATE_TTL', 600),
];
