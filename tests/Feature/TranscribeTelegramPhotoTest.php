<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramPhotoTextAgent;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramPhoto;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TranscribeTelegramPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_photo_with_a_readable_label_is_transcribed_and_sent_to_the_same_ai_pipeline(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Queue::fake();
        TelegramPhotoTextAgent::fake(fn (): array => [
            'text' => 'HP OmniBook 3 Laptop 17-dp0005sfx, AMD Ryzen 5 40 APU, SSD 512 Go, 8 Go RAM',
            'has_text' => true,
        ])->preventStrayPrompts();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photo/test.jpg']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response('fake-jpeg-bytes');
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
        $update = TelegramUpdate::query()->create([
            'update_id' => 4001,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 57,
            'payload' => ['message' => ['photo' => [
                ['file_id' => 'small-file', 'width' => 90, 'height' => 68, 'file_size' => 900],
                ['file_id' => 'large-file', 'width' => 800, 'height' => 600, 'file_size' => 90000],
            ]]],
            'status' => 'received',
        ]);

        (new TranscribeTelegramPhoto($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        $this->assertDatabaseHas('telegram_updates', [
            'id' => $update->id,
            'text' => 'HP OmniBook 3 Laptop 17-dp0005sfx, AMD Ryzen 5 40 APU, SSD 512 Go, 8 Go RAM',
            'status' => 'transcribed',
        ]);
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $update->id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/getFile')
            && $request['file_id'] === 'large-file');
    }

    public function test_a_photo_without_a_file_id_is_rejected_without_calling_vision(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Queue::fake();
        TelegramPhotoTextAgent::fake()->preventStrayPrompts();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => []]));
        $update = TelegramUpdate::query()->create([
            'update_id' => 4002,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 58,
            'payload' => ['message' => ['photo' => []]],
            'status' => 'received',
        ]);

        (new TranscribeTelegramPhoto($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        $this->assertDatabaseHas('telegram_updates', [
            'id' => $update->id,
            'status' => 'rejected',
        ]);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }
}
