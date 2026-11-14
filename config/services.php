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
        // Réseaux couverts (affichage seulement — le widget choisit le réseau).
        'networks'        => array_filter(explode(',', env('KKIAPAY_NETWORKS', 'mtn,moov'))),
    ],

    // DA-20 : FedaPay, second agrégateur (transaction créée côté serveur, page de paiement FedaPay).
    'fedapay' => [
        'public_key'        => env('FEDAPAY_PUBLIC_KEY'),
        'secret_key'        => env('FEDAPAY_SECRET_KEY'),
        'webhook_secret'    => env('FEDAPAY_WEBHOOK_SECRET'),
        'sandbox'           => (bool) env('FEDAPAY_SANDBOX', true),
        'api_url'           => env('FEDAPAY_API_URL', 'https://api.fedapay.com/v1'),
        'sandbox_api_url'   => env('FEDAPAY_SANDBOX_API_URL', 'https://sandbox-api.fedapay.com/v1'),
        'timeout'           => (int) env('FEDAPAY_TIMEOUT', 15),
        'webhook_tolerance' => (int) env('FEDAPAY_WEBHOOK_TOLERANCE', 300),
        // Page de l'app membre où FedaPay renvoie après paiement (?payment={id} ajouté).
        'return_url'        => env('FEDAPAY_RETURN_URL'),
        'networks'          => array_filter(explode(',', env('FEDAPAY_NETWORKS', 'mtn,moov,celtiis'))),
    ],

];
