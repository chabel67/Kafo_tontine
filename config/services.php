<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'kkiapay' => [
        'public_key'      => env('KKIAPAY_PUBLIC_KEY'),
        'private_key'     => env('KKIAPAY_PRIVATE_KEY'),
        'secret'          => env('KKIAPAY_SECRET'),
        'webhook_secret'  => env('KKIAPAY_WEBHOOK_SECRET'),
        'sandbox'         => (bool) env('KKIAPAY_SANDBOX', true),
        'api_url'         => env('KKIAPAY_API_URL', 'https://api.kkiapay.me'),
        'sandbox_api_url' => env('KKIAPAY_SANDBOX_API_URL', 'https://api-sandbox.kkiapay.me'),
        'timeout'         => (int) env('KKIAPAY_TIMEOUT', 15),
    ],

];
