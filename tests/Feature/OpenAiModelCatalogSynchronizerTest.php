<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Services\Ai\AiModelCatalog;
use App\Services\Ai\OpenAiModelCatalogSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiModelCatalogSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_availability_and_standard_token_prices(): void
    {
        config([
            'ai.providers.openai.key' => 'test-key',
            'ai.providers.openai.url' => 'https://api.openai.test/v1',
        ]);

        Http::fake([
            'https://api.openai.test/v1/models' => Http::response([
                'data' => [['id' => 'gpt-5-mini']],
            ]),
            'https://developers.openai.com/*' => Http::response(<<<'HTML'
                <html><body>
                    <div>Input</div><div>$0.30</div>
                    <div>Cached input</div><div>$0.03</div>
                    <div>Output</div><div>$2.10</div>
                </body></html>
                HTML),
        ]);

        $result = app(OpenAiModelCatalogSynchronizer::class)->sync();
        $model = AiModel::query()->where('model', 'gpt-5-mini')->firstOrFail();

        $this->assertTrue($model->is_available);
        $this->assertSame(0.3, $model->input_per_million);
        $this->assertSame(0.03, $model->cached_input_per_million);
        $this->assertSame(2.1, $model->output_per_million);
        $this->assertSame(1, $result['available']);
        $this->assertSame(AiModel::query()->where('provider', 'openai')->count() - 1, $result['unavailable']);
        $this->assertSame(AiModel::query()->where('provider', 'openai')->count(), $result['prices_updated']);
        $this->assertSame([], $result['errors']);
    }

    public function test_an_emptied_table_is_refilled_from_the_catalog_and_an_unreadable_price_is_an_error(): void
    {
        // Live case (2026-09-27): the table was empty, the selector fell back to
        // the config file, and the button reported "0 available, 0 unavailable,
        // 0 prices updated" as a success. gpt-6-astra could never appear.
        AiModel::query()->delete();
        config([
            'ai.providers.openai.key' => 'test-key',
            'ai.providers.openai.url' => 'https://api.openai.test/v1',
        ]);
        Http::fake([
            'https://api.openai.test/v1/models' => Http::response(['data' => [['id' => 'gpt-6-astra']]]),
            // The model page's markup no longer has the label/price div pairs.
            'https://developers.openai.com/*' => Http::response('<html><body><p>Text tokens Input $10.00</p></body></html>'),
        ]);

        $result = app(OpenAiModelCatalogSynchronizer::class)->sync();

        $astra = AiModel::query()->where('model', 'gpt-6-astra')->firstOrFail();
        $this->assertTrue($astra->is_available);
        $this->assertTrue($astra->is_enabled);
        $this->assertSame(10.0, $astra->input_per_million);
        $this->assertSame(50.0, $astra->output_per_million);
        $this->assertSame(1, $result['available']);
        $this->assertSame(0, $result['prices_updated']);
        $this->assertArrayHasKey('gpt-6-astra', $result['errors']);
        $this->assertSame(
            count((array) config('ai_model_catalog.providers.openai.models')),
            AiModel::query()->where('provider', 'openai')->count(),
        );
        $this->assertArrayHasKey('gpt-6-astra', app(AiModelCatalog::class)->options('openai'));
        $this->assertArrayNotHasKey('gpt-5.4', app(AiModelCatalog::class)->options('openai'), 'not available to this key');
    }
}
