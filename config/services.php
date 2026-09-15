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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'proxy_url' => env('TELEGRAM_PROXY_URL'),
        'allowed_user_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEGRAM_ALLOWED_USER_IDS', '')),
        ))),
    ],

    'product_research' => [
        'provider' => 'openai',
        'model' => env('AI_PRODUCT_RESEARCH_MODEL', 'gpt-5.4'),
    ],

    'product_image_vision' => [
        'provider' => 'openai',
        'model' => env('AI_PRODUCT_IMAGE_VISION_MODEL', 'gpt-5.4-mini'),
        'timeout' => (int) env('AI_PRODUCT_IMAGE_VISION_TIMEOUT', 45),
    ],

    'image_upscale' => [
        'provider' => 'openai',
        'model' => env('AI_IMAGE_UPSCALE_MODEL', 'gpt-image-2'),
        'timeout' => (int) env('AI_IMAGE_UPSCALE_TIMEOUT', 90),
    ],

    'product_image_discovery' => [
        'provider' => 'openai',
        'model' => env('AI_PRODUCT_IMAGE_DISCOVERY_MODEL', 'gpt-5.4'),
        'timeout' => (int) env('AI_PRODUCT_IMAGE_DISCOVERY_TIMEOUT', 75),
    ],

    'product_source_identity' => [
        'provider' => 'openai',
        'model' => env('AI_PRODUCT_SOURCE_IDENTITY_MODEL', 'gpt-5.4-mini'),
        'timeout' => (int) env('AI_PRODUCT_SOURCE_IDENTITY_TIMEOUT', 20),
    ],

    'product_specification_reconciliation' => [
        'provider' => 'openai',
        'model' => env('AI_PRODUCT_SPECIFICATION_RECONCILIATION_MODEL', 'gpt-5.4-mini'),
        // Real production runs (2026-09-14, draft #5) hit exactly this
        // ceiling twice in a row - cURL error 28 at ~30000ms/~30016ms, the
        // model still generating when our own client gave up - burning the
        // shared search budget on a guaranteed-empty result instead of
        // actually getting an answer. This call has tools (HasTools, up to
        // MaxSteps(3) round trips, one of which can launch a full
        // Playwright browser session) and a non-trivial structured output
        // (the whole card), closer in shape to product_image_discovery's
        // 75s or product_image_upscale's 90s above than to the
        // tool-free, single-verdict product_source_identity judge's 20s -
        // 30s was never enough for what this call actually does.
        // ProductSearchTimeBudget::timeoutFor() still caps this at however
        // much shared search time actually remains when that is tighter.
        'timeout' => (int) env('AI_PRODUCT_SPECIFICATION_RECONCILIATION_TIMEOUT', 90),
    ],

    'gallery_recipe_training' => [
        'provider' => 'openai',
        'model' => env('AI_GALLERY_RECIPE_TRAINING_MODEL', 'gpt-5.4'),
        'timeout' => (int) env('AI_GALLERY_RECIPE_TRAINING_TIMEOUT', 180),
    ],

    'server_assistant' => [
        'provider' => 'openai',
        'model' => env('AI_SERVER_ASSISTANT_MODEL', 'gpt-5.4'),
    ],

    'voice_transcription' => [
        'provider' => 'openai',
        'model' => env('AI_TRANSCRIPTION_MODEL', 'gpt-4o-transcribe'),
        'max_seconds' => (int) env('TELEGRAM_VOICE_MAX_SECONDS', 300),
        'max_bytes' => (int) env('TELEGRAM_VOICE_MAX_BYTES', 20971520),
    ],

    'telegram_photo' => [
        'max_bytes' => (int) env('TELEGRAM_PHOTO_MAX_BYTES', 10485760),
    ],

    'ai_usage' => [
        'prices' => [
            'openai' => [
                'gpt-5.4' => [
                    'input_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_INPUT_PER_1M'),
                    'cached_input_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_CACHED_INPUT_PER_1M'),
                    'output_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_OUTPUT_PER_1M'),
                    'reasoning_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_REASONING_PER_1M'),
                ],
                'gpt-5.4-mini' => [
                    'input_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_MINI_INPUT_PER_1M'),
                    'cached_input_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_MINI_CACHED_INPUT_PER_1M'),
                    'output_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_MINI_OUTPUT_PER_1M'),
                    'reasoning_per_million' => env('AI_PRICE_OPENAI_GPT_5_4_MINI_REASONING_PER_1M'),
                ],
                'gpt-4o-transcribe' => [
                    'input_per_million' => env('AI_PRICE_OPENAI_GPT_4O_TRANSCRIBE_INPUT_PER_1M'),
                    'output_per_million' => env('AI_PRICE_OPENAI_GPT_4O_TRANSCRIBE_OUTPUT_PER_1M'),
                ],
            ],
            'deepseek' => [
                'deepseek-v4-flash' => [
                    'input_per_million' => env('AI_PRICE_DEEPSEEK_V4_FLASH_INPUT_PER_1M'),
                    'cached_input_per_million' => env('AI_PRICE_DEEPSEEK_V4_FLASH_CACHED_INPUT_PER_1M'),
                    'output_per_million' => env('AI_PRICE_DEEPSEEK_V4_FLASH_OUTPUT_PER_1M'),
                    'reasoning_per_million' => env('AI_PRICE_DEEPSEEK_V4_FLASH_REASONING_PER_1M'),
                ],
            ],
        ],
    ],

    'admin' => [
        'name' => env('ADMIN_NAME', 'ningredy'),
        'email' => env('ADMIN_EMAIL', 'ningredy@local.test'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
