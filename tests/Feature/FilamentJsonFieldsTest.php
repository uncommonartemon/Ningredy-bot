<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentJsonFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_array_payloads_without_render_errors(): void
    {
        $admin = User::factory()->create(['name' => 'ningredy', 'is_admin' => true]);
        $update = TelegramUpdate::query()->create([
            'update_id' => 9200,
            'telegram_user_id' => '123',
            'chat_id' => '123',
            'text' => 'test',
            'payload' => ['message' => ['text' => 'test']],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'test-model',
            'status' => 'completed',
            'prompt' => 'test',
            'response' => ['message' => 'ok'],
            'usage' => ['input_tokens' => 10],
            'started_at' => now(),
        ]);

        $this->actingAs($admin)->get("/admin/ai-runs/{$run->id}")->assertOk();
        $this->actingAs($admin)->get("/admin/telegram-updates/{$update->id}")->assertOk();
    }
}
