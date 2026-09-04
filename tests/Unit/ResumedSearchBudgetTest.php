<?php

namespace Tests\Unit;

use App\Models\AiRun;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResumedSearchBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_search_resumed_after_a_dead_worker_gets_its_own_clock(): void
    {
        // Live case (2026-09-04): a search started at 13:40, its worker died,
        // the health check released the job, and after a restart it resumed
        // measuring against 13:40 - announced "time reserve reached" nine
        // seconds in and closed the draft with no photographs.
        $update = $this->update();
        $this->aiRun($update, '-90 minutes', '-88 minutes');
        $this->aiRun($update, '-1 minute', null);

        $this->assertGreaterThan(
            60 * 20,
            (int) app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id),
        );
    }

    public function test_a_search_that_is_merely_slow_keeps_spending_its_budget(): void
    {
        // The other direction matters more: research can occupy a single call
        // for minutes, and that must not look like an interruption or the limit
        // would reset itself forever.
        $update = $this->update();
        $this->aiRun($update, '-40 minutes', '-25 minutes');
        $this->aiRun($update, '-24 minutes', '-23 minutes');

        $this->assertLessThan(
            60 * 5,
            (int) app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id),
        );
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::query()->create([
            'update_id' => random_int(9000, 99000),
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'processing',
        ]);
    }

    private function aiRun(TelegramUpdate $update, string $startedAt, ?string $completedAt): void
    {
        AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'status' => $completedAt ? 'completed' : 'running',
            'prompt' => 'search',
            'started_at' => now()->modify($startedAt),
            'completed_at' => $completedAt ? now()->modify($completedAt) : null,
        ]);
    }
}
