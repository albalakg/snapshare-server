<?php

return [
    'cache_ttl' => (int) env('EVENT_HUB_CACHE_TTL', 3600),

    'cache_prefix' => 'hub:slug:',

    'slug_min_length' => 3,
    'slug_max_length' => 100,

    'reserved_slugs' => [
        'admin',
        'api',
        'dashboard',
        'auth',
        'pricing',
        'snapshare',
        'public',
        'events',
        'user',
        'store',
        'subscriptions',
        'h',
        'hub',
        'hubs',
    ],

    'default_theme' => [
        'primary_color'   => '#D4AF37',
        'background_type' => 'LIGHT',
        'font_family'     => 'Rubik',
    ],

    'default_snapshare_cta_text' => 'Find Your Photos via Face Recognition',
];
