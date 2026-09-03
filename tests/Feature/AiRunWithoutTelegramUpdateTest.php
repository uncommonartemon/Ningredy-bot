<?php

namespace Tests\Feature;

use App\Models\AiRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiRunWithoutTelegramUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ai_call_started_outside_telegram_can_still_be_recorded(): void
    {
        // ai_runs.telegram_update_id used to be NOT NULL, which asserted that
        // every AI call comes from a Telegram message. Retraining launched from
        // the Filament recipe screen has no update, so those runs were either
        // skipped (losing the cost trail) or - once the gallery agent's Vision
        // tool tried to record one - failed at the database level.
        $run = AiRun::query()->create([
            'telegram_update_id' => null,
            'provider' => 'openai',
            'model' => 'gpt-5.4-mini',
            'status' => 'running',
            'prompt' => '{}',
            'started_at' => now(),
        ]);

        $this->assertNull($run->refresh()->telegram_update_id);
        $this->assertDatabaseHas('ai_runs', ['id' => $run->id, 'telegram_update_id' => null]);
    }
}
