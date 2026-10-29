<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\ProcessTelegramProductUpload;
use App\Jobs\SendDiagnosticReport;
use App\Jobs\TranscribeTelegramPhoto;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\TelegramChatState;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'reply_to_text' => null,
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

    #[DataProvider('searchPhotoCaptions')]
    public function test_it_queues_allowed_photo_messages_for_transcription(?string $caption): void
    {
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1004);
        unset($payload['message']['text']);
        $payload['message']['caption'] = $caption;
        $payload['message']['photo'] = [
            ['file_id' => 'photo-small', 'width' => 90, 'height' => 68, 'file_size' => 900],
            ['file_id' => 'photo-large', 'width' => 800, 'height' => 600, 'file_size' => 90000],
        ];

        $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk()->assertJson(['photo' => true]);

        Queue::assertPushed(TranscribeTelegramPhoto::class, 1);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        Queue::assertNotPushed(ProcessTelegramProductUpload::class);
    }

    public static function searchPhotoCaptions(): array
    {
        return [
            'bare label' => [null],
            'English search' => ['Search this as a new product and prepare a draft.'],
            'Russian search' => ['Найди фотографии и характеристики этого ноутбука'],
            'short photo search' => ['поиск по фото'],
            'web enrichment' => ['Дополни товар через поиск в интернете'],
        ];
    }

    public function test_descriptions_and_negative_search_captions_remain_manual_uploads(): void
    {
        Queue::fake();
        foreach (['Dell Latitude 5450, used, 16 GB RAM', 'Не ищи в интернете, добавь по описанию',
            "Don't search the web, use this description.", '/add Dell Latitude, search details later'] as $index => $caption) {
            $payload = $this->messagePayload(updateId: 9050 + $index);
            unset($payload['message']['text']);
            $payload['message']['caption'] = $caption;
            $payload['message']['photo'] = [['file_id' => 'operator-photo', 'width' => 800, 'height' => 600]];
            $this->postJson('/api/telegram/webhook', $payload, [
                'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
            ])->assertOk()->assertJson(['upload' => true]);
        }

        Queue::assertPushed(ProcessTelegramProductUpload::class, 4);
        Queue::assertNotPushed(TranscribeTelegramPhoto::class);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_it_stores_the_replied_to_message_text(): void
    {
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1002);
        $payload['message']['text'] = 'апскейль второе фото';
        $payload['message']['reply_to_message'] = [
            'message_id' => 40,
            'text' => "#28 · ROG NUC (2025) Gaming Mini PC\nASUS ROG · NUC15JNK",
        ];

        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])
            ->assertOk();

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 1002,
            'text' => 'апскейль второе фото',
            'reply_to_text' => "#28 · ROG NUC (2025) Gaming Mini PC\nASUS ROG · NUC15JNK",
        ]);
    }

    public function test_it_falls_back_to_the_replied_to_photo_caption(): void
    {
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1003);
        $payload['message']['reply_to_message'] = [
            'message_id' => 41,
            'caption' => '#28 · ROG NUC (2025) Gaming Mini PC',
        ];

        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])
            ->assertOk();

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 1003,
            'reply_to_text' => '#28 · ROG NUC (2025) Gaming Mini PC',
        ]);
    }

    public function test_reset_command_cancels_stuck_updates_and_clears_chat_state(): void
    {
        Queue::fake();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $stuck = TelegramUpdate::create([
            'update_id' => 5001,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'text' => 'Найди Lenovo Legion 5',
            'status' => 'received',
            'payload' => [],
        ]);
        $otherChat = TelegramUpdate::create([
            'update_id' => 5002,
            'telegram_user_id' => '99999',
            'chat_id' => '11111',
            'text' => 'Найди Mac mini',
            'status' => 'received',
            'payload' => [],
        ]);
        TelegramChatState::create(['chat_id' => '98765', 'conversation_id' => 'conv-1']);

        $payload = $this->messagePayload(updateId: 5003);
        $payload['message']['text'] = '/reset';

        $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertNotNull($stuck->refresh()->processed_at);
        $this->assertSame('cancelled', $stuck->status);
        $this->assertNull($otherChat->refresh()->processed_at);
        $this->assertDatabaseMissing('telegram_chat_states', ['chat_id' => '98765']);
        Queue::assertNothingPushed();
    }

    public function test_report_command_queues_the_diagnostic_archive_for_the_chat(): void
    {
        // Operator, 2026-10-29: the person the bot was handed to sends /report and the
        // developer gets one file; the webhook answers at once, the archive follows.
        Queue::fake();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $payload = $this->messagePayload(updateId: 5010);
        $payload['message']['text'] = '/report';

        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])
            ->assertOk()->assertJson(['ok' => true]);

        Queue::assertPushed(SendDiagnosticReport::class, fn (SendDiagnosticReport $job): bool => $job->chatId === '98765');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage') && str_contains((string) ($request['text'] ?? ''), 'отчёт'));
        $this->assertNotNull(TelegramUpdate::query()->where('update_id', 5010)->sole()->processed_at);
    }

    public function test_a_copied_progress_line_is_stripped_down_to_the_query(): void
    {
        // Real production bug (2026-08-05): the user re-asks a search by
        // copying the bot's live progress line, which carries the timer
        // chrome - and the bot then searched for "1с · Acer ..." verbatim,
        // burning a full rate-limited search on garbage.
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1010);
        $payload['message']['text'] = '1с · Acer nitro V16 AI, R7 260, RTX 5060';

        $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 1010,
            'text' => 'Acer nitro V16 AI, R7 260, RTX 5060',
        ]);
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
    }

    public function test_a_fully_copied_progress_line_strips_nested_timers_and_the_limit_tail(): void
    {
        Queue::fake();
        $payload = $this->messagePayload(updateId: 1011);
        $payload['message']['text'] = '⏳ 1с · 1с · Acer nitro V16 AI, R7 260, RTX 5060 · лимит 300 сек.';

        $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 1011,
            'text' => 'Acer nitro V16 AI, R7 260, RTX 5060',
        ]);
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
