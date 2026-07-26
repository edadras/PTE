<?php

declare(strict_types=1);

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

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | Card data never touches this application — every gateway redirects and we
    | keep only the reference. Callbacks are always verified server-side.
    |
    */

    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox' => (bool) env('ZARINPAL_SANDBOX', true),
    ],

    'idpay' => [
        'api_key' => env('IDPAY_API_KEY'),
        'sandbox' => (bool) env('IDPAY_SANDBOX', true),
    ],

    'zibal' => [
        'merchant' => env('ZIBAL_MERCHANT'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'telegram_payments' => [
        'provider_token' => env('TELEGRAM_PROVIDER_TOKEN'),
        // Per-academy bot tokens override this via the payment request metadata.
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'secret_token' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

];
