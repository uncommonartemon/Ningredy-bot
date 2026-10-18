<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramPhotoTextAgent;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramPhoto;
use App\Models\AiRun;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Products\ProductInputContext;
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
                return Http::response($this->jpeg());
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
        $run = AiRun::sole();
        $this->assertSame($update->id, $run->telegram_update_id);
        $this->assertSame('read_text', $run->activity['vision_operation']);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->usage);
        $input = ProductInputContext::forUpdate($update->fresh());
        $this->assertNull($input['original_operator_request']);
        $this->assertSame('photo_transcription', $input['input_evidence'][0]['source_kind']);
        $this->assertSame('telegram-photo:'.$update->id.':1', $input['input_evidence'][0]['source_ref']);
        $this->assertNotEmpty(data_get($update->fresh()->payload, '_product_input.photos.0.sha256'));
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(32, 16);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
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

    public function test_structured_label_evidence_survives_the_queued_search_handoff(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Queue::fake();
        TelegramPhotoTextAgent::fake(fn (): array => [
            'text' => 'Acer AG15-42P-R732 LED LCD', 'has_text' => true,
            'identifiers' => [[
                'kind' => 'model', 'value' => 'AG15-42P-R732', 'raw_value' => 'AG15-42P-R732',
                'source_excerpt' => 'AG15-42P-R732', 'source_kind' => 'photo_label',
                'confidence' => 0.99, 'uncertain_characters' => null,
            ]],
            'observations' => [[
                'key' => 'display_backlight', 'name' => 'Backlight', 'value' => 'LED', 'raw_value' => 'LED',
                'source_excerpt' => '15.6 FHD Acer ComfyView LED LCD', 'source_kind' => 'photo_label',
                'confidence' => 0.99, 'uncertain_characters' => null,
            ]],
        ])->preventStrayPrompts();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photo/label.jpg']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($this->jpeg());
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
        $update = TelegramUpdate::query()->create([
            'update_id' => 4003, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'message_id' => 59,
            'payload' => ['message' => ['photo' => [['file_id' => 'label', 'file_unique_id' => 'label-unique']]]],
            'status' => 'received',
        ]);

        (new TranscribeTelegramPhoto($update->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));

        $context = ProductInputContext::forUpdate($update->fresh());
        $this->assertNull($context['original_operator_request']);
        $this->assertSame('AG15-42P-R732', $context['search_plan'][0]['query']);
        $this->assertSame('photo_label', $context['input_evidence'][1]['source_kind']);
        $this->assertSame('15.6 FHD Acer ComfyView LED LCD', $context['input_evidence'][1]['source_excerpt']);
        $this->assertSame('label-unique', data_get($update->fresh()->payload, '_product_input.photos.0.file_unique_id'));
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $update->id);
    }
}
