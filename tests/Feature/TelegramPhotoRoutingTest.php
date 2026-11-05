<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramPhotoTextAgent;
use App\Ai\Tools\ManageSearchSettings;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\ProcessTelegramProductUpload;
use App\Jobs\TranscribeTelegramPhoto;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductSearchIntentDetector;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

/**
 * Module 71: a photographed label is a search. The colleague's #140 (2026-11-02): a
 * label photo with a few words of caption went to the manual upload intake and
 * came back as an empty card with no photo in twenty seconds.
 */
class TelegramPhotoRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.webhook_secret' => 'test-secret', 'services.telegram.allowed_user_ids' => ['12345']]);
    }

    public function test_a_caption_that_names_a_price_or_condition_describes_the_operators_own_offer(): void
    {
        $detector = app(ProductSearchIntentDetector::class);

        foreach (['Dell Latitude 5450, used, 16 GB RAM', 'ThinkPad T480 б/у 8/256', 'Lenovo IdeaPad 12 500 Kč', 'HP 250 G8 cena 9 990 Kč', 'SSD 512, стан відмінний'] as $caption) {
            $this->assertTrue($detector->describesOwnOffer($caption), $caption);
        }
        foreach (['Micron 16GB DDR5', 'KVR32S22S8/16', 'что это за диск', ''] as $caption) {
            $this->assertFalse($detector->describesOwnOffer($caption), $caption);
        }
        $this->assertTrue($detector->refusesSearch('Не ищи в интернете, добавь по описанию'));
        $this->assertTrue($detector->refusesSearch('/add Dell Latitude'));
        $this->assertFalse($detector->refusesSearch('Micron 16GB DDR5'));
    }

    public function test_a_photo_with_a_neutral_caption_is_read_first_by_default_and_uploaded_only_when_the_caption_or_setting_says_so(): void
    {
        Queue::fake();
        $this->assertSame(AiSettings::PHOTO_CAPTION_LABEL, app(AiSettings::class)->photoCaptionRouting());

        $this->photo(7001, 'Micron 16GB DDR5')->assertJson(['photo' => true]);
        $this->photo(7002, 'ThinkPad T480 б/у 8/256')->assertJson(['upload' => true]);
        $this->photo(7003, 'Не ищи в интернете, добавь по описанию')->assertJson(['upload' => true]);
        Queue::assertPushed(TranscribeTelegramPhoto::class, 1);
        Queue::assertPushed(ProcessTelegramProductUpload::class, 2);

        app(AiSettings::class)->savePhotoCaptionRouting(AiSettings::PHOTO_CAPTION_MANUAL);
        $this->photo(7004, 'Micron 16GB DDR5')->assertJson(['upload' => true]);
        app(AiSettings::class)->savePhotoCaptionRouting(AiSettings::PHOTO_CAPTION_SEARCH);
        $this->photo(7005, 'ThinkPad T480 б/у 8/256')->assertJson(['photo' => true]);
        $this->photo(7006, 'Не ищи в интернете, добавь по описанию')->assertJson(['upload' => true]);
    }

    public function test_the_read_photo_decides_between_search_and_upload_by_what_it_shows(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Queue::fake();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photo/test.jpg']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($this->jpeg());
            }

            return Http::response(['ok' => true, 'result' => []]);
        });

        // A label with a part number: a search, the caption stays the operator's words.
        TelegramPhotoTextAgent::fake(fn (): array => ['text' => 'Micron MTC8C1084S1SC48BA1 16GB DDR5-4800 SO-DIMM', 'has_text' => true,
            'identifiers' => [['kind' => 'mpn', 'value' => 'MTC8C1084S1SC48BA1', 'raw_value' => 'MTC8C1084S1SC48BA1', 'source_excerpt' => 'MTC8C1084S1SC48BA1',
                'confidence' => 'high', 'uncertain_characters' => null, 'source_kind' => 'photo_label']], 'observations' => []])->preventStrayPrompts();
        $label = $this->update(8001, 'Micron 16GB');
        (new TranscribeTelegramPhoto($label->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        $this->assertSame('transcribed', $label->fresh()->status);
        $this->assertStringContainsString('MTC8C1084S1SC48BA1', (string) $label->fresh()->text);
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $label->id);

        // A plain picture of a laptop with a description: the operator's own product, uploaded.
        TelegramPhotoTextAgent::fake(fn (): array => ['text' => 'Серебристый ноутбук на столе.', 'has_text' => false, 'identifiers' => [], 'observations' => []])
            ->preventStrayPrompts();
        $picture = $this->update(8002, 'ThinkPad T480 8/256');
        (new TranscribeTelegramPhoto($picture->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Queue::assertPushed(ProcessTelegramProductUpload::class, fn ($job) => $job->telegramUpdateId === $picture->id);
        $this->assertTrue((bool) data_get($picture->fresh()->payload, '_manual_product_upload'));
        Queue::assertNotPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $picture->id);
    }

    public function test_the_routing_is_a_setting_the_chat_can_show_and_change(): void
    {
        $update = TelegramUpdate::query()->create(['update_id' => 9001, 'telegram_user_id' => '111', 'chat_id' => '222', 'message_id' => 1,
            'text' => 'фото с подписью всегда искать', 'payload' => [], 'status' => 'processing']);
        $tool = new ManageSearchSettings($update);

        $shown = json_decode($tool->handle(new ToolRequest(['action' => 'show'])), true);
        $this->assertSame('label', $shown['settings']['photo_caption_routing']);

        $set = json_decode($tool->handle(new ToolRequest(['action' => 'set', 'setting' => 'photo_caption_routing', 'value' => 'search'])), true);
        $this->assertTrue($set['ok']);
        $this->assertSame('search', app(AiSettings::class)->photoCaptionRouting());
        $this->assertFalse(json_decode($tool->handle(new ToolRequest(['action' => 'set', 'setting' => 'photo_caption_routing', 'value' => 'sometimes'])), true)['ok']);
    }

    private function photo(int $updateId, string $caption): TestResponse
    {
        return $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'message' => ['message_id' => 55,
            'from' => ['id' => 12345, 'username' => 'admin'], 'chat' => ['id' => 98765], 'caption' => $caption,
            'photo' => [['file_id' => 'operator-photo-'.$updateId, 'width' => 800, 'height' => 600, 'file_size' => 90000]]]],
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function update(int $updateId, string $caption): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => $updateId, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'message_id' => 57,
            'payload' => ['message' => ['caption' => $caption, 'photo' => [['file_id' => 'large-file', 'width' => 800, 'height' => 600, 'file_size' => 90000]]]],
            'status' => 'received']);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
