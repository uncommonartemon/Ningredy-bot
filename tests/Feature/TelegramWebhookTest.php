<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramVoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
    }

    public function test_it_rejects_an_invalid_webhook_secret(): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->assertForbidden();
    }

    public function test_it_stores_and_queues_an_allowed_text_message_once(): void
    {
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1001);
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'];

        $this->postJson('/api/telegram/webhook', $payload, $headers)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->postJson('/api/telegram/webhook', $payload, $headers)
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 1001,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'text' => 'Найди Lenovo Legion 5 серого цвета с 32 ГБ RAM',
            'status' => 'received',
        ]);
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
    }

    public function test_it_does_not_queue_messages_from_users_outside_the_allowlist(): void
    {
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1002, userId: 777);

        $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk()->assertJson(['ignored' => true]);

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 1002,
            'status' => 'rejected',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_it_queues_allowed_voice_messages_for_transcription(): void
    {
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1003);
        unset($payload['message']['text']);
        $payload['message']['voice'] = [
            'file_id' => 'voice-file',
            'duration' => 8,
            'file_size' => 1024,
        ];

        $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk()->assertJson(['voice' => true]);

        Queue::assertPushed(TranscribeTelegramVoice::class, 1);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    private function messagePayload(int $updateId, int $userId = 12345): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 55,
                'from' => [
                    'id' => $userId,
                    'username' => 'admin',
                ],
                'chat' => ['id' => 98765],
                'text' => 'Найди Lenovo Legion 5 серого цвета с 32 ГБ RAM',
            ],
        ];
    }
}
