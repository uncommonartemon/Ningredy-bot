<?php

namespace Tests\Feature;

use App\Models\AiModel;
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
}
