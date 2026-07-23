<?php

return [
    'default' => 'openai',
    'default_for_images' => 'openai',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',

    'conversations' => [
        'generate_title' => false,
    ],

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', false),
        ],
    ],
];
