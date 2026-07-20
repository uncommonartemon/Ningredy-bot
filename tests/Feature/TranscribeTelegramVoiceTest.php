<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Transcription;
use Tests\TestCase;

class TranscribeTelegramVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_is_transcribed_and_sent_to_the_same_ai_pipeline(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Queue::fake();
        Transcription::fake(['Найди в интернете Lenovo Legion']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'voice/test.oga']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response('fake-ogg-data');
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
        $update = TelegramUpdate::query()->create([
            'update_id' => 3001,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 56,
            'payload' => ['message' => ['voice' => ['file_id' => 'voice-file', 'duration' => 5, 'file_size' => 100]]],
            'status' => 'received',
        ]);

        (new TranscribeTelegramVoice($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        $this->assertDatabaseHas('telegram_updates', [
            'id' => $update->id,
            'text' => 'Найди в интернете Lenovo Legion',
            'status' => 'transcribed',
        ]);
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $update->id);
        Transcription::assertGenerated(fn ($prompt) => true);
    }
}
