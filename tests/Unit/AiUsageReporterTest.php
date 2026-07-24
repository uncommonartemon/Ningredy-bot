<?php

namespace Tests\Unit;

use App\Models\AiRun;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiUsageReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_estimates_cost_for_a_model_name_containing_a_dot(): void
    {
        // Real bug: config('services.ai_usage.prices.openai.gpt-5.4...')
        // parses every dot as a nested array key, so "gpt-5.4" (part of the
        // actual model name) silently never matched and cost was always null.
        config(['services.ai_usage.prices.openai' => [
            'gpt-5.4' => [
                'input_per_million' => 2.50,
                'output_per_million' => 15.00,
            ],
        ]]);
        AiRun::query()->create([
            'telegram_update_id' => $this->update()->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'test',
            'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000],
            'started_at' => now(),
        ]);

        $summary = app(AiUsageReporter::class)->summary();

        $this->assertSame(17.5, $summary['all_time']['estimated_cost_usd']);
    }

    public function test_for_telegram_update_scopes_to_one_interaction_and_respects_since(): void
    {
        $update1 = $this->update();
        $update2 = $this->update();

        $earlier = AiRun::query()->create([
            'telegram_update_id' => $update1->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'research',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            'started_at' => now(),
        ]);
        $earlier->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();

        $later = AiRun::query()->create([
            'telegram_update_id' => $update1->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'vision',
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 25],
            'started_at' => now(),
        ]);

        AiRun::query()->create([
            'telegram_update_id' => $update2->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'unrelated update',
            'usage' => ['prompt_tokens' => 9999, 'completion_tokens' => 9999],
            'started_at' => now(),
        ]);

        $reporter = app(AiUsageReporter::class);

        $wholeUpdate = $reporter->forTelegramUpdate($update1->id);
        $this->assertSame(2, $wholeUpdate['runs']);
        $this->assertSame(375, $wholeUpdate['tokens']['total']);

        $sinceLater = $reporter->forTelegramUpdate($update1->id, $later->created_at->subSecond());
        $this->assertSame(1, $sinceLater['runs']);
        $this->assertSame(225, $sinceLater['tokens']['total']);
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 90000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'test',
            'payload' => [],
            'status' => 'completed',
        ]);
    }
}
