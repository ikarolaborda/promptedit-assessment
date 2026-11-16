<?php

return [
    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'price_id' => env('STRIPE_CREDIT_PRICE_ID', ''),
        'livemode' => env('STRIPE_LIVE_MODE', false),
        'test_email' => env('STRIPE_TEST_EMAIL', ''),
    ],
    'foundry' => [
        'enabled' => env('FOUNDRY_IMAGES_ENABLED', false),
        'endpoint' => env('FOUNDRY_ENDPOINT', ''),
        'key' => env('FOUNDRY_API_KEY', ''),
        'deployment' => env('FOUNDRY_IMAGE_DEPLOYMENT', 'frame-studio-image-mini'),
        'daily_budget' => env('FOUNDRY_DAILY_BUDGET_USD', '5.00'),
        'text_rate' => env('FOUNDRY_TEXT_RATE_USD', '2'),
        'image_rate' => env('FOUNDRY_IMAGE_RATE_USD', '2.5'),
        'output_rate' => env('FOUNDRY_OUTPUT_RATE_USD', '8'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect' => rtrim(env('APP_URL', 'http://localhost'), '/').'/auth/google/callback',
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', ''),
        'client_secret' => '',
        'team_id' => env('APPLE_TEAM_ID', ''),
        'key_id' => env('APPLE_KEY_ID', ''),
        'private_key' => base64_decode(env('APPLE_PRIVATE_KEY_BASE64', ''), true) ?: '',
        'redirect' => rtrim(env('APP_URL', 'http://localhost'), '/').'/auth/apple/callback',
        'nonce_ttl' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pixelforge' => [
        'url' => env('PIXELFORGE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'key' => env('PIXELFORGE_API_KEY', ''),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET', ''),
        'demo_enabled' => env('DEMO_ENABLED', false),
    ],

];
