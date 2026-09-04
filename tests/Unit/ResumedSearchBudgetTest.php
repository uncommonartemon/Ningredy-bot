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

    public function test_a_job_delivered_a_second_time_starts_its_clock_over(): void
    {
        // Live case (2026-09-04): a search started at 13:40, its worker died,
        // the health check released the job, and after a restart it resumed
        // measuring against 13:40 - announced "time reserve reached" nine
        // seconds in and closed the draft with no photographs.
        $update = $this->update();
        $this->aiRun($update, '-90 minutes');
        $budget = app(ProductSearchTimeBudget::class);

        $this->assertSame(0, $budget->remainingWorkingSeconds($update->id));

        $budget->restartSession($update->id);

        $this->assertGreaterThan(60 * 20, (int) $budget->remainingWorkingSeconds($update->id));
    }

    public function test_a_slow_search_never_grants_itself_a_new_budget(): void
    {
        // The rule that matters more: PROJECT_STRATEGY reserves a fresh budget
        // for an explicit "continue" press. An earlier version of this handed
        // one out after ten minutes of silence, which would have let every slow
        // search - and every automatic continuation - take the button's
        // privilege for itself.
        $update = $this->update();
        $this->aiRun($update, '-90 minutes');

        $this->assertSame(0, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
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

    private function aiRun(TelegramUpdate $update, string $startedAt): void
    {
        AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'status' => 'completed',
            'prompt' => 'search',
            'started_at' => now()->modify($startedAt),
            'completed_at' => now()->modify($startedAt),
        ]);
    }
}
