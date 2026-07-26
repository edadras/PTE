<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform identity
    |--------------------------------------------------------------------------
    */

    'platform' => [
        'name' => env('PTE_PLATFORM_NAME', 'PTE Platform'),
        'root_domain' => env('PTE_ROOT_DOMAIN', 'pte-platform.test'),
        'webhook_base_url' => env('PTE_WEBHOOK_BASE_URL', env('APP_URL')),
        'support_email' => env('PTE_SUPPORT_EMAIL', 'support@pte-platform.test'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    */

    'tenancy' => [
        'cache_ttl' => 3600,
        'reserved_slugs' => [
            'www', 'api', 'admin', 'app', 'panel', 'platform', 'status',
            'mail', 'cdn', 'static', 'assets', 'webhook', 'horizon',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | Telegram's own limits: ~30 messages/second per bot, 1 message/second in a
    | private chat, 20 messages/minute in a group. We stay a notch below.
    |
    */

    'telegram' => [
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'timeout' => 15,
        'connect_timeout' => 5,
        'max_connections' => 40,
        'allowed_updates' => [
            'message',
            'edited_message',
            'callback_query',
            'pre_checkout_query',
            'successful_payment',
            'my_chat_member',
        ],
        'rate_limits' => [
            'per_bot_per_second' => 25,
            'per_chat_per_second' => 1,
        ],
        'state_ttl' => 86400,
        'callback_payload_ttl' => 3600,
        'update_dedupe_ttl' => 3600,
        'max_flow_hops' => 50,
        'broadcast_chunk_size' => 100,
        'download_max_bytes' => 20 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media processing
    |--------------------------------------------------------------------------
    */

    'media' => [
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'target_sample_rate' => 16000,
        'target_channels' => 1,
        'min_speech_seconds' => 2.0,
        'max_speech_seconds' => 180.0,
        'silence_rms_threshold' => 0.008,
        'answer_retention_days' => 90,
        'signed_url_ttl_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI gateway
    |--------------------------------------------------------------------------
    */

    'ai' => [
        'default_temperature' => 0.3,
        'default_max_output_tokens' => 1200,
        'request_timeout' => 60,
        'cache_ttl_days' => 7,
        'max_retries' => 1,

        'circuit_breaker' => [
            'window_seconds' => 300,
            'failure_ratio' => 0.3,
            'min_samples' => 5,
            'open_seconds' => 300,
        ],

        'low_confidence_threshold' => 0.6,

        'providers' => [
            'gemini' => [
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'api_key' => env('GEMINI_API_KEY'),
            ],
            'openai' => [
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('OPENAI_API_KEY'),
            ],
            'anthropic' => [
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
                'api_key' => env('ANTHROPIC_API_KEY'),
                'version' => '2023-06-01',
            ],
            'whisper' => [
                'base_url' => env('WHISPER_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('WHISPER_API_KEY', env('OPENAI_API_KEY')),
            ],
            'google_stt' => [
                'base_url' => env('GOOGLE_STT_BASE_URL', 'https://speech.googleapis.com/v1'),
                'api_key' => env('GOOGLE_STT_API_KEY'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    */

    'queues' => [
        'telegram_in' => 'telegram-in',
        'telegram_out' => 'telegram-out',
        'telegram_setup' => 'telegram-setup',
        'media' => 'media',
        'ai_scoring' => 'ai-scoring',
        'notifications' => 'notifications',
        'reports' => 'reports',
        'maintenance' => 'maintenance',
    ],

    /*
    |--------------------------------------------------------------------------
    | Commerce
    |--------------------------------------------------------------------------
    |
    | Rates are basis points (900 = 9.00%) so nothing here is ever a float.
    | usd_to_irr is a placeholder: AI spend is billed in USD while academies
    | pay in IRR, so profitability reporting needs a real FX feed before these
    | numbers can be trusted for pricing decisions.
    |
    */

    'commerce' => [
        'tax_rate_bp' => (int) env('PTE_TAX_RATE_BP', 900),
        'default_gateway' => env('PTE_DEFAULT_GATEWAY', 'zarinpal'),
        'platform_fee_bp' => (int) env('PTE_PLATFORM_FEE_BP', 300),
        'profitability_alert_ratio' => (float) env('PTE_PROFITABILITY_ALERT_RATIO', 0.5),
        'usd_to_irr' => (int) env('PTE_USD_TO_IRR', 600_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | SMS ships with a null driver. Swapping in a real gateway is a container
    | binding for SmsDriver — the dispatcher already resolves it.
    |
    */

    'notifications' => [
        'sms' => [
            'driver' => env('PTE_SMS_DRIVER', 'null'),
            'sender' => env('PTE_SMS_SENDER'),
            'api_key' => env('PTE_SMS_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention (days)
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'telegram_updates' => 30,
        'telegram_messages' => 90,
        'ai_logs' => 7,
        'activity_logs' => 365,
        'exports' => 1,
        'reports' => 7,
    ],

];
