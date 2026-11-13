<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramPhotoTextAgent;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramPhoto;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\ProductSearch\Recovery\RequestGuardian;
use App\Services\Telegram\PhotoReadingCheck;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Module 107, decision of 2026-11-13: what the bot read on a photo is put to the operator before
 * the search - right, correct it, read once more, cancel. The colleague's #333 was a memory
 * module whose code was read with two wrong characters at confidence 0.99: twenty minutes of
 * search for a part that does not exist and no photo.
 */
class PhotoReadingCheckTest extends TestCase
{
    use RefreshDatabase;

    private const string MISREAD = 'Samsung memory module. MPN/model: M471A1AG43MB1-CTD. Label also reads: 32GB 2Rx8 PC4-2666V-SE1-11.';

    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake([ProcessTelegramMessage::class, TranscribeTelegramPhoto::class]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
        app(AiSettings::class)->savePhotoReadingCheck(AiSettings::PHOTO_CHECK_ALWAYS);
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photo/label.jpg']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($this->jpeg());
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 900]]);
        });
        $this->reader(self::MISREAD, 'M471A1AG43MB1-CTD', null, 0.99);
    }

    public function test_the_reading_waits_for_the_operator_and_right_starts_the_search(): void
    {
        $photo = $this->photo();

        $this->read($photo);

        $this->assertSame(PhotoReadingCheck::AWAITING, $photo->fresh()->status);
        $this->assertNull($photo->fresh()->processed_at);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Прочитал с фото:')
            && str_contains((string) $request['text'], 'M471A1AG43MB1-CTD')
            && array_column(array_merge(...(array) data_get($request->data(), 'reply_markup.inline_keyboard', [])), 'callback_data')
                === ["pr:ok:{$photo->id}", "pr:fix:{$photo->id}", "pr:again:{$photo->id}", "pr:no:{$photo->id}"]);
        // The guardian leaves a request that waits for the operator alone.
        $this->assertSame(PhotoReadingCheck::AWAITING, $photo->fresh()->status);

        $this->press("pr:ok:{$photo->id}", 5001);
        $this->press("pr:ok:{$photo->id}", 5002);

        $this->assertSame('transcribed', $photo->fresh()->status);
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'editMessageText')
            && str_contains((string) $request['text'], '✅ Принято, ищу.'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'answerCallbackQuery')
            && str_contains((string) $request['text'], 'уже закрыт'));
    }

    public function test_a_correction_is_read_with_the_photo_and_the_search_starts_without_another_question(): void
    {
        $photo = $this->photo();
        $this->read($photo);

        $this->press("pr:fix:{$photo->id}", 5003);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Пришлите исправление'));
        $this->reader(str_replace('M471A1AG43MB1', 'M471A4G43MB1', self::MISREAD), 'M471A4G43MB1-CTD', null, 0.99);
        $this->say('код M471A4G43MB1-CTD', 5004);
        // The webhook queued the reading with the correction; run it as the worker would.
        $this->read($photo);

        $photo->refresh();
        $this->assertSame('transcribed', $photo->status);
        $this->assertStringContainsString('M471A4G43MB1-CTD', $photo->text);
        $this->assertStringContainsString('Исправление оператора: код M471A4G43MB1-CTD', $photo->text);
        $this->assertStringContainsString('the operator is right', end($this->prompts));
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), '✏️ Исправлено, ищу'));
    }

    public function test_when_the_photo_cannot_be_read_with_the_correction_the_search_goes_on_the_corrected_reading(): void
    {
        $photo = $this->photo();
        $this->read($photo);
        $this->press("pr:fix:{$photo->id}", 5005);
        $this->say('код M471A4G43MB1-CTD', 5006);
        TelegramPhotoTextAgent::fake(fn () => throw new RuntimeException('provider down'))->preventStrayPrompts();

        $this->read($photo);

        $photo->refresh();
        $this->assertSame('transcribed', $photo->status);
        $this->assertStringContainsString('M471A1AG43MB1-CTD', $photo->text);
        $this->assertStringContainsString('Исправление оператора: код M471A4G43MB1-CTD', $photo->text);
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
    }

    public function test_read_once_more_says_whether_the_reading_changed_and_stops_after_four_readings(): void
    {
        $photo = $this->photo();
        $this->read($photo);

        foreach ([5007, 5008, 5009] as $updateId) {
            $this->press("pr:again:{$photo->id}", $updateId);
            $this->assertSame('received', $photo->fresh()->status);
            $this->read($photo);
        }

        $this->assertStringContainsString('every character of every code anew', end($this->prompts));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), '🔁 Прочитал ещё раз — вышло то же самое.'));
        $this->press("pr:again:{$photo->id}", 5010);
        $this->assertSame(PhotoReadingCheck::AWAITING, $photo->fresh()->status);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'answerCallbackQuery')
            && str_contains((string) $request['text'], 'Прочитал уже 4 раза'));
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_cancel_closes_the_request_without_a_search(): void
    {
        $photo = $this->photo();
        $this->read($photo);

        $this->press("pr:no:{$photo->id}", 5011);

        $this->assertSame('cancelled', $photo->fresh()->status);
        $this->assertNotNull($photo->fresh()->processed_at);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_a_keyboard_button_while_a_correction_is_awaited_is_not_taken_for_one(): void
    {
        $photo = $this->photo();
        $this->read($photo);
        $this->press("pr:fix:{$photo->id}", 5012);

        $this->say('📋 Черновики', 5013);
        $this->say('код M471A4G43MB1-CTD', 5014);

        $this->assertSame(PhotoReadingCheck::AWAITING, $photo->fresh()->status);
        $this->assertArrayNotHasKey('correction', (array) data_get($photo->fresh()->payload, PhotoReadingCheck::PAYLOAD_KEY));
    }

    public function test_the_setting_decides_when_to_ask(): void
    {
        app(AiSettings::class)->savePhotoReadingCheck(AiSettings::PHOTO_CHECK_UNSURE);
        $sure = $this->photo(4101);
        $this->read($sure);
        $this->assertSame('transcribed', $sure->fresh()->status);

        $this->reader(self::MISREAD, 'M471A1AG43MB1-CTD', '1A', 0.7);
        $doubtful = $this->photo(4102);
        $this->read($doubtful);
        $this->assertSame(PhotoReadingCheck::AWAITING, $doubtful->fresh()->status);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'не уверен в «1A»'));

        app(AiSettings::class)->savePhotoReadingCheck(AiSettings::PHOTO_CHECK_NEVER);
        $never = $this->photo(4103);
        $this->read($never);
        $this->assertSame('transcribed', $never->fresh()->status);
    }

    public function test_a_photo_without_legible_text_is_not_asked_about(): void
    {
        TelegramPhotoTextAgent::fake(fn (): array => ['text' => 'Ноутбук серого цвета', 'has_text' => false, 'operator_intent' => 'search',
            'intent_reason' => 'Без подписи.', 'identifiers' => [], 'observations' => []])->preventStrayPrompts();
        $photo = $this->photo();

        $this->read($photo);

        $this->assertSame('transcribed', $photo->fresh()->status);
    }

    public function test_the_guardian_leaves_a_waiting_request_alone(): void
    {
        $photo = $this->photo();
        $this->read($photo);
        TelegramUpdate::query()->whereKey($photo->id)->update(['created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);

        app(RequestGuardian::class)->sweep();

        $this->assertSame(PhotoReadingCheck::AWAITING, $photo->fresh()->status);
        $this->assertNull($photo->fresh()->processed_at);
    }

    private function reader(string $text, string $code, ?string $unsure, float $confidence): void
    {
        TelegramPhotoTextAgent::fake(function (string $prompt) use ($text, $code, $unsure, $confidence): array {
            $this->prompts[] = $prompt;

            return ['text' => $text, 'has_text' => true, 'operator_intent' => 'search', 'intent_reason' => 'Этикетка с кодом.',
                'identifiers' => [['kind' => 'mpn', 'value' => $code, 'raw_value' => $code, 'source_excerpt' => $code,
                    'source_kind' => 'photo_label', 'confidence' => $confidence, 'uncertain_characters' => $unsure]],
                'observations' => []];
        })->preventStrayPrompts();
    }

    private function photo(int $updateId = 4100): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => $updateId, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'message_id' => $updateId, 'status' => 'received', 'payload' => ['message' => ['photo' => [
                ['file_id' => 'large-file', 'file_unique_id' => 'u'.$updateId, 'width' => 800, 'height' => 600, 'file_size' => 90000]]]]]);
    }

    private function read(TelegramUpdate $photo): void
    {
        (new TranscribeTelegramPhoto($photo->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
    }

    private function press(string $data, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'callback-'.$updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'data' => $data,
            'message' => ['message_id' => 900, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function say(string $text, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'message' => [
            'message_id' => $updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'chat' => ['id' => 98765], 'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(32, 16);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
