<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftTelegramMessageLifecycle;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DraftControlMessageNotModifiedTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_edit_that_changes_nothing_keeps_the_control_message_in_place(): void
    {
        $update = TelegramUpdate::query()->create(['update_id' => 8801, 'telegram_user_id' => '1', 'chat_id' => '98765',
            'text' => 'x', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'x', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review', 'title' => 'Draft',
            'specifications' => [], 'sources' => [], 'image_urls' => [], 'telegram_control_message_ids' => [80]]);

        // Live 2026-10-20: the edit was refused as unchanged, and the buttons were deleted and posted anew.
        $telegram = $this->mock(TelegramClient::class);
        $telegram->shouldReceive('editMessageText')->once()->andThrow(new RuntimeException(
            'HTTP request returned status code 400: {"ok":false,"error_code":400,"description":"Bad Request: message is not modified: specified new message content and reply markup are exactly the same"}'));
        $telegram->shouldNotReceive('sendMessage');
        $telegram->shouldNotReceive('deleteMessages');

        $response = app(DraftTelegramMessageLifecycle::class)->replaceControlMessage($telegram, $draft, '98765', 'Same text', []);

        $this->assertSame(80, $response['result']['message_id']);
        $this->assertSame([80], $draft->fresh()->telegram_control_message_ids);
    }
}
