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
 * came back as an empty card with no photo in twenty seconds. The reader that
 * transcribes the photo also says what the operator wants, seeing the caption and
 * the picture together; word lists decide nothing but explicit instructions.
 */
class TelegramPhotoRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.webhook_secret' => 'test-secret', 'services.telegram.allowed_user_ids' => ['12345']]);
    }

    public function test_only_an_explicit_refusal_or_the_always_manual_setting_skips_reading_the_photo(): void
    {
        $detector = app(ProductSearchIntentDetector::class);

        foreach (['Не ищи в интернете, добавь по описанию', '/add Dell Latitude', "Don't search, add as is"] as $caption) {
            $this->assertTrue($detector->photoIsManualBeforeReading($caption, AiSettings::PHOTO_CAPTION_LABEL), $caption);
        }
        // A price, a condition or a description is not an instruction: the reader sees it with the picture.
        foreach (['Dell Latitude 5450, used, 16 GB RAM', 'ThinkPad T480 б/у 8/256', 'Lenovo IdeaPad 12 500 Kč', 'Micron 16GB DDR5', ''] as $caption) {
            $this->assertFalse($detector->photoIsManualBeforeReading($caption, AiSettings::PHOTO_CAPTION_LABEL), $caption);
        }
        $this->assertTrue($detector->photoIsManualBeforeReading('Micron 16GB DDR5', AiSettings::PHOTO_CAPTION_MANUAL));
        $this->assertFalse($detector->photoIsManualBeforeReading('найди Micron 16GB DDR5', AiSettings::PHOTO_CAPTION_MANUAL));
        $this->assertFalse($detector->photoIsManualBeforeReading('', AiSettings::PHOTO_CAPTION_MANUAL));
    }

    public function test_a_single_photo_is_read_first_unless_the_caption_refuses_or_the_setting_says_always_manual(): void
    {
        Queue::fake();
        $this->assertSame(AiSettings::PHOTO_CAPTION_LABEL, app(AiSettings::class)->photoCaptionRouting());

        $this->photo(7001, 'Micron 16GB DDR5')->assertJson(['photo' => true]);
        $this->photo(7002, 'ThinkPad T480 б/у 8/256')->assertJson(['photo' => true]);
        $this->photo(7003, 'Не ищи в интернете, добавь по описанию')->assertJson(['upload' => true]);
        Queue::assertPushed(TranscribeTelegramPhoto::class, 2);
        Queue::assertPushed(ProcessTelegramProductUpload::class, 1);

        app(AiSettings::class)->savePhotoCaptionRouting(AiSettings::PHOTO_CAPTION_MANUAL);
        $this->photo(7004, 'Micron 16GB DDR5')->assertJson(['upload' => true]);
        $this->photo(7005, 'найди Micron 16GB DDR5')->assertJson(['photo' => true]);
        app(AiSettings::class)->savePhotoCaptionRouting(AiSettings::PHOTO_CAPTION_SEARCH);
        $this->photo(7006, 'Micron 16GB DDR5')->assertJson(['photo' => true]);
        // An explicit refusal means the operator's own product whatever the setting says.
        $this->photo(7007, '/add ThinkPad T480 б/у 8/256')->assertJson(['upload' => true]);
    }

    public function test_the_reader_says_what_the_operator_wants_and_explicit_words_or_the_setting_outrank_it(): void
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
        $prompts = [];

        // A label with a part number and a neutral caption: the reader says search; the caption stays the operator's words.
        TelegramPhotoTextAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return [...$this->label(), 'operator_intent' => 'search', 'intent_reason' => 'На наклейке код детали.'];
        })->preventStrayPrompts();
        $label = $this->update(8001, 'Micron 16GB');
        (new TranscribeTelegramPhoto($label->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        $this->assertSame('transcribed', $label->fresh()->status);
        $this->assertStringContainsString('MTC8C1084S1SC48BA1', (string) $label->fresh()->text);
        $this->assertStringContainsString('«Micron 16GB»', (string) end($prompts));
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $label->id);

        // A picture with a price: the reader says own product, and the operator hears why.
        TelegramPhotoTextAgent::fake(fn (): array => [...$this->picture(), 'operator_intent' => 'own_product', 'intent_reason' => 'В подписи указана цена.'])
            ->preventStrayPrompts();
        $offer = $this->update(8002, 'ThinkPad T480 8/256, 4500 грн');
        (new TranscribeTelegramPhoto($offer->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Queue::assertPushed(ProcessTelegramProductUpload::class, fn ($job) => $job->telegramUpdateId === $offer->id);
        $this->assertTrue((bool) data_get($offer->fresh()->payload, '_manual_product_upload'));
        Queue::assertNotPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $offer->id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Принял как свой товар: В подписи указана цена.'));

        // The reader unsure: a product code on the picture is a search, none is the operator's own item.
        TelegramPhotoTextAgent::fake(fn (): array => [...$this->label(), 'operator_intent' => 'unclear', 'intent_reason' => 'Подпись не говорит, чего хочет оператор.'])
            ->preventStrayPrompts();
        $unsureLabel = $this->update(8003, 'Micron 16GB');
        (new TranscribeTelegramPhoto($unsureLabel->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $unsureLabel->id);
        TelegramPhotoTextAgent::fake(fn (): array => [...$this->picture(), 'operator_intent' => 'unclear', 'intent_reason' => 'Подпись не говорит, чего хочет оператор.'])
            ->preventStrayPrompts();
        $unsurePicture = $this->update(8004, 'ThinkPad T480 8/256');
        (new TranscribeTelegramPhoto($unsurePicture->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Queue::assertPushed(ProcessTelegramProductUpload::class, fn ($job) => $job->telegramUpdateId === $unsurePicture->id);

        // A screenshot with a request about a product goes to the assistant, its read text in the message.
        TelegramPhotoTextAgent::fake(fn (): array => ['text' => 'CrystalDiskInfo Samsung PM991 Health Status Good 95 % Power On Hours 1234 hours', 'has_text' => true,
            'identifiers' => [], 'observations' => [], 'operator_intent' => 'request', 'intent_reason' => 'В подписи просьба дописать значения в товар.'])->preventStrayPrompts();
        $smart = $this->update(8007, 'добавь в товар 15');
        (new TranscribeTelegramPhoto($smart->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $smart->id);
        Queue::assertNotPushed(ProcessTelegramProductUpload::class, fn ($job) => $job->telegramUpdateId === $smart->id);
        $this->assertStringContainsString('Power On Hours 1234', (string) $smart->fresh()->text);
        $this->assertStringContainsString('добавь в товар 15', (string) $smart->fresh()->text);

        // «найди» in the caption, or the setting «всегда поиск», is a search whatever the reader thinks.
        TelegramPhotoTextAgent::fake(fn (): array => [...$this->picture(), 'operator_intent' => 'own_product', 'intent_reason' => 'Похоже на свой товар.'])
            ->preventStrayPrompts();
        $asked = $this->update(8005, 'найди ThinkPad T480 8/256');
        (new TranscribeTelegramPhoto($asked->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $asked->id);
        app(AiSettings::class)->savePhotoCaptionRouting(AiSettings::PHOTO_CAPTION_SEARCH);
        $forced = $this->update(8006, 'ThinkPad T480 8/256');
        (new TranscribeTelegramPhoto($forced->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => $job->telegramUpdateId === $forced->id);
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

    /** @return array<string, mixed> */
    private function label(): array
    {
        return ['text' => 'Micron MTC8C1084S1SC48BA1 16GB DDR5-4800 SO-DIMM', 'has_text' => true,
            'identifiers' => [['kind' => 'mpn', 'value' => 'MTC8C1084S1SC48BA1', 'raw_value' => 'MTC8C1084S1SC48BA1', 'source_excerpt' => 'MTC8C1084S1SC48BA1',
                'confidence' => 0.95, 'uncertain_characters' => null, 'source_kind' => 'photo_label']], 'observations' => []];
    }

    /** @return array<string, mixed> */
    private function picture(): array
    {
        return ['text' => 'Серебристый ноутбук на столе.', 'has_text' => false, 'identifiers' => [], 'observations' => []];
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
