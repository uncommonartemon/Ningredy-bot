<?php

/*
|--------------------------------------------------------------------------
| AI model catalog and standard API prices
|--------------------------------------------------------------------------
|
| Prices are USD per 1,000,000 text tokens for the standard service tier.
| Update this catalog from the official model pages linked below. The model
| selector and usage calculator intentionally share this single source.
|
*/

return [
    'pricing_checked_at' => '2026-07-25',

    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'models' => [
                'gpt-5.4' => ['label' => 'GPT-5.4', 'input_per_million' => 2.50, 'cached_input_per_million' => 0.25, 'output_per_million' => 15.00, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5.4'],
                'gpt-5.4-mini' => ['label' => 'GPT-5.4 mini', 'input_per_million' => 0.75, 'cached_input_per_million' => 0.075, 'output_per_million' => 4.50, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5.4-mini'],
                'gpt-5.4-nano' => ['label' => 'GPT-5.4 nano', 'input_per_million' => 0.20, 'cached_input_per_million' => 0.02, 'output_per_million' => 1.25, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5.4-nano'],
                'gpt-5.1' => ['label' => 'GPT-5.1', 'input_per_million' => 1.25, 'cached_input_per_million' => 0.125, 'output_per_million' => 10.00, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5.1'],
                'gpt-5' => ['label' => 'GPT-5', 'input_per_million' => 1.25, 'cached_input_per_million' => 0.125, 'output_per_million' => 10.00, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5'],
                'gpt-5-mini' => ['label' => 'GPT-5 mini', 'input_per_million' => 0.25, 'cached_input_per_million' => 0.025, 'output_per_million' => 2.00, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5-mini'],
                'gpt-5-nano' => ['label' => 'GPT-5 nano', 'input_per_million' => 0.05, 'cached_input_per_million' => 0.005, 'output_per_million' => 0.40, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-5-nano'],
                'gpt-4.1' => ['label' => 'GPT-4.1', 'input_per_million' => 2.00, 'cached_input_per_million' => 0.50, 'output_per_million' => 8.00, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-4.1'],
                'gpt-4.1-mini' => ['label' => 'GPT-4.1 mini', 'input_per_million' => 0.40, 'cached_input_per_million' => 0.10, 'output_per_million' => 1.60, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-4.1-mini'],
                'gpt-4.1-nano' => ['label' => 'GPT-4.1 nano', 'input_per_million' => 0.10, 'cached_input_per_million' => 0.025, 'output_per_million' => 0.40, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-4.1-nano'],
                'gpt-4o' => ['label' => 'GPT-4o', 'input_per_million' => 2.50, 'cached_input_per_million' => 1.25, 'output_per_million' => 10.00, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-4o'],
                'gpt-4o-mini' => ['label' => 'GPT-4o mini', 'input_per_million' => 0.15, 'cached_input_per_million' => 0.075, 'output_per_million' => 0.60, 'source_url' => 'https://developers.openai.com/api/docs/models/gpt-4o-mini'],
                'o3' => ['label' => 'o3', 'input_per_million' => 2.00, 'cached_input_per_million' => 0.50, 'output_per_million' => 8.00, 'source_url' => 'https://developers.openai.com/api/docs/models/o3'],
                'o4-mini' => ['label' => 'o4-mini', 'input_per_million' => 1.10, 'cached_input_per_million' => 0.275, 'output_per_million' => 4.40, 'source_url' => 'https://developers.openai.com/api/docs/models/o4-mini'],
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'models' => [
                'deepseek-v4-flash' => ['label' => 'DeepSeek V4 Flash', 'input_per_million' => 0.14, 'cached_input_per_million' => 0.0028, 'output_per_million' => 0.28, 'source_url' => 'https://api-docs.deepseek.com/quick_start/pricing'],
            ],
        ],
    ],
];
