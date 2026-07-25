<?php

namespace Tests\Unit;

use App\Services\Ai\AiModelCatalog;
use Tests\TestCase;

class AiModelCatalogTest extends TestCase
{
    public function test_every_selectable_openai_model_has_complete_pricing_and_an_official_source(): void
    {
        $catalog = app(AiModelCatalog::class);

        $this->assertNotEmpty($catalog->models('openai'));

        foreach ($catalog->models('openai') as $model => $details) {
            $this->assertIsNumeric($details['input_per_million'] ?? null, "{$model} input price is missing");
            $this->assertIsNumeric($details['cached_input_per_million'] ?? null, "{$model} cached price is missing");
            $this->assertIsNumeric($details['output_per_million'] ?? null, "{$model} output price is missing");
            $this->assertStringStartsWith(
                'https://developers.openai.com/api/docs/models/',
                (string) ($details['source_url'] ?? ''),
                "{$model} official source is missing",
            );
        }
    }

    public function test_options_show_prices_next_to_model_names(): void
    {
        $option = app(AiModelCatalog::class)->options('openai')['gpt-5-mini'];

        $this->assertStringContainsString('$0.25 вход', $option);
        $this->assertStringContainsString('$0.025 кеш', $option);
        $this->assertStringContainsString('$2 выход', $option);
    }
}
