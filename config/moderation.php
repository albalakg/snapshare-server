<?php

return [

    'enabled' => env('MODERATION_ENABLED', true),

    'min_confidence' => (float) env('MODERATION_MIN_CONFIDENCE', 80),

    'blocked_categories' => [
        'Explicit Nudity',
        'Violence',
        'Visually Disturbing',
    ],

    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    'profanity_en' => [
        'fuck', 'shit', 'bitch', 'asshole', 'damn',
    ],

    'profanity_he' => [
        'זין', 'כוס', 'שרמוטה', 'מניאק',
    ],

];
