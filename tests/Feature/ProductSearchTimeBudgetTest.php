<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProductSearchTimeBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clamps_operations_and_preserves_the_finalization_reserve(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');
        AppSetting::put('ai.search_max_seconds', '1200');
        AppSetting::put('ai.search_reserve_seconds', '120');
        $update = TelegramUpdate::query()->create([
            'update_id' => 81001,
            'telegram_user_id' => '12345',
            'chat_id' => '12345',
            'message_id' => '1',
            'text' => 'test product',
            'payload' => [],
            'status' => 'processing',
        ]);
        AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'status' => 'running',
            'prompt' => 'test',
            'started_at' => now()->subSeconds(900),
        ]);

        $budget = app(ProductSearchTimeBudget::class);

        $this->assertSame(180, $budget->remainingWorkingSeconds($update->id));
        $this->assertSame(180, $budget->timeoutFor($update->id, 300));
        $this->assertTrue($budget->canStart($update->id, 180));
        $this->assertFalse($budget->canStart($update->id, 181));

        Carbon::setTestNow();
    }
}
