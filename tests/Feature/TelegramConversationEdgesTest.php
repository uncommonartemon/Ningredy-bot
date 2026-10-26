<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\AiRun;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductIdentityKey;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramAssistantReplyDelivery;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/** Branches of the Telegram interface found unhandled in the 2026-10-20 audit. */
class TelegramConversationEdgesTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 77000;

    private int $apiStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Sleep::fake();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['123']]);
        Http::fake(['https://api.telegram.org/*' => fn () => $this->apiStatus === 200
            ? Http::response(['ok' => true, 'result' => ['message_id' => 700]])
            : Http::response(['ok' => false, 'description' => 'Bad Request: message is not modified'], $this->apiStatus)]);
    }

    public function test_an_expired_prompt_is_mentioned_once_and_the_repeated_request_is_searched(): void
    {
        $draft = $this->draft();
        $this->press("draft:query:{$draft->id}");
        $this->travel(11)->minutes();

        $this->message('Lenovo Legion 5 16IRX9');
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->assertSentText('Время ввода истекло');

        $this->message('Lenovo Legion 5 16IRX9');
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
    }

    public function test_a_prompt_forgotten_for_hours_does_not_block_a_new_request(): void
    {
        $draft = $this->draft();
        $this->press("draft:source-hint:{$draft->id}");
        $this->travel(3)->hours();

        $this->message('Найди Acer Swift 14');

        Queue::assertPushed(ProcessTelegramMessage::class, 1);
    }

    public function test_the_errors_command_is_never_taken_as_the_awaited_text(): void
    {
        foreach (['/errors', '⚠️ Ошибки'] as $command) {
            $draft = $this->draft();
            $this->press("draft:query:{$draft->id}");
            $this->message($command);
            $this->assertNull(app(DraftTelegramInteractionState::class)->get('900', '123'));
        }

        Queue::assertPushed(ProcessTelegramMessage::class, 2);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'Ваш текст'));
    }

    public function test_voice_while_photos_are_awaited_gets_the_hint_and_a_button_confirmation_lets_it_through(): void
    {
        $draft = $this->draft();
        $this->press("draft:restage:{$draft->id}");
        $this->update(['voice' => ['file_id' => 'voice-1', 'duration' => 3]]);
        // A button confirmation is not answered by voice: the voice is the next
        // request, and the confirmation keeps waiting on its buttons (live #153-#155).
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'ожидаю подтверждение кнопкой'));
        $this->assertSame('confirmation', app(DraftTelegramInteractionState::class)->get('900', '123')['kind'] ?? null);
        Queue::assertPushed(TranscribeTelegramVoice::class, 1);

        app(DraftTelegramInteractionState::class)->remember('900', '123', $draft, 'manual_photos');
        $this->update(['voice' => ['file_id' => 'voice-2', 'duration' => 3]]);
        $this->assertSentText('ожидаю фото для выбранной карточки');

        Queue::assertPushed(TranscribeTelegramVoice::class, 1);
    }

    public function test_files_and_stickers_are_answered_but_service_messages_are_not(): void
    {
        $this->update(['document' => ['file_id' => 'doc-1', 'mime_type' => 'application/pdf']]);
        $this->update(['sticker' => ['file_id' => 'sticker-1']]);
        $this->update(['pinned_message' => ['message_id' => 5]]);

        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Такие сообщения бот не обрабатывает')));
        Queue::assertNothingPushed();
    }

    public function test_catalog_search_without_a_match_says_so_and_offers_the_web_search(): void
    {
        $this->message('/catalog Zenbook S 16');

        $this->assertSentText('По запросу «Zenbook S 16» в каталоге ничего не найдено');
        Queue::assertNothingPushed();
    }

    public function test_a_deep_link_start_opens_the_help(): void
    {
        $this->message('/start from-site');

        $this->assertSentText('Управление Ningredy');
        Queue::assertNothingPushed();
    }

    public function test_a_refused_draft_action_closes_its_update(): void
    {
        $draft = $this->draft(['primary_source_url' => null]);

        $update = $this->press("draft:source-hint:{$draft->id}");

        $this->assertNotNull($update->fresh()->processed_at);
    }

    public function test_the_assistant_text_beside_a_draft_is_shown_only_when_it_adds_something(): void
    {
        foreach (['research_result' => 0, 'catalog_results' => 1, 'answer' => 1] as $type => $expected) {
            [$update, $draft, $run] = $this->readyDraft();
            $delivery = app(TelegramAssistantReplyDelivery::class);
            $delivery->deliver($delivery->prepareAssistant($update, $run->id, [
                'response' => ['response_type' => $type, 'message' => "Пояснение {$type}", 'draft_id' => $draft->id,
                    'product_ids' => [], 'operation_ids' => []],
                'draft_id' => $draft->id, 'draft_generation' => $draft->telegram_update_id, 'footnote' => '',
            ]), app(TelegramClient::class));

            $this->assertCount($expected, Http::recorded(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), "Пояснение {$type}")), $type);
            // One photo goes out as sendPhoto, several as an album.
            Http::assertSent(fn (Request $request): bool => preg_match('#/send(Photo|MediaGroup)$#', $request->url()) === 1
                && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), "Черновик #{$draft->id}"));
        }
    }

    public function test_a_long_assistant_answer_arrives_whole_in_parts(): void
    {
        [$update, , $run] = $this->readyDraft();
        $long = implode("\n", array_map(fn (int $line): string => "Строка {$line}: подробности об ошибке очереди и её причине.", range(1, 160)));
        $delivery = app(TelegramAssistantReplyDelivery::class);

        $delivery->deliver($delivery->prepareAssistant($update, $run->id, [
            'response' => ['response_type' => 'answer', 'message' => $long, 'draft_id' => null, 'product_ids' => [], 'operation_ids' => []],
            'footnote' => '',
        ]), app(TelegramClient::class));

        $parts = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))
            ->map(fn (array $pair): string => (string) $pair[0]['text']);
        $this->assertGreaterThan(1, $parts->count());
        $this->assertTrue($parts->every(fn (string $part): bool => mb_strlen($part) <= 4096));
        $this->assertSame(str_replace("\n", '', $long), str_replace("\n", '', $parts->implode('')));
    }

    public function test_a_permanent_telegram_refusal_is_not_retried_but_an_outage_is(): void
    {
        $this->apiStatus = 400;
        try {
            app(TelegramClient::class)->editMessageText('900', 5, 'same text');
        } catch (RuntimeException) {
        }
        $this->apiStatus = 502;
        try {
            app(TelegramClient::class)->sendMessage('900', 'during an outage');
        } catch (RuntimeException) {
        }

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')));
        // retry(2) is two attempts in all.
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')));
    }

    public function test_draft_controls_say_when_the_catalog_already_has_this_model_or_item(): void
    {
        $brand = Brand::firstOrCreate(['slug' => 'apple'], ['name' => 'Apple']);
        $model = 'MacBook Pro 14-inch (M3 Pro, 2023)';
        $product = Product::create(['brand_id' => $brand->id, 'category_id' => Category::where('slug', 'laptops')->value('id'),
            'canonical_key' => ProductIdentityKey::for('Apple', $model, 'Apple MacBook Pro').'-id-0123456789abcdef01234567',
            'title' => 'Apple MacBook Pro 14-inch M3 Pro', 'model' => $model, 'product_type' => 'laptop',
            'status' => 'published', 'slug' => 'macbook-pro-14', 'is_active' => true, 'published_at' => now()]);
        $product->variants()->create(['mpn' => 'MRX33CZ/A', 'fingerprint' => 'mbp', 'condition' => 'new', 'is_default' => true, 'is_active' => true]);
        $card = ['brand' => 'Apple', 'model' => $model, 'title' => 'Apple MacBook Pro 14-inch (M3 Pro, Space Black)'];

        $regional = $this->draft([...$card, 'specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => 'MRX33LL/A']]]);
        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '900', $regional);
        $this->assertSentText("Эта модель уже есть в каталоге: #{$product->id} (MRX33CZ/A)");

        $same = $this->draft([...$card, 'specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => 'MRX33CZ/A']]]);
        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '900', $same);
        $this->assertSentText("Этот товар уже есть в каталоге: #{$product->id}. «Добавить в каталог» обновит его");
    }

    private function assertSentText(string $needle): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), $needle));
    }

    private function draft(array $overrides = []): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => ++$this->sequence, 'telegram_user_id' => '123',
            'chat_id' => '900', 'text' => 'Lenovo', 'payload' => [], 'status' => 'completed', 'processed_at' => now()]);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai',
            'model' => 'test', 'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '123',
            'title' => 'Test laptop', 'status' => 'pending_review', 'primary_source_url' => 'https://shop.example/product',
            'specifications_reconciled_source_url' => 'https://shop.example/product', 'images_staged_at' => now(),
            'specifications' => [], 'sources' => [], 'image_urls' => [], 'telegram_review_chat_id' => '900', 'telegram_control_message_ids' => [700], ...$overrides]);
    }

    /** @return array{TelegramUpdate, ProductDraft, AiRun} */
    private function readyDraft(): array
    {
        $draft = $this->draft(['gallery_status' => 'complete', 'category' => 'laptops', 'product_type' => 'laptop',
            'description' => 'Verified description.', 'telegram_control_message_ids' => []]);
        $path = "drafts/{$draft->id}/1.jpg";
        Storage::disk('public')->put($path, 'fake-image');
        $draft->media()->create(['disk' => 'public', 'path' => $path, 'checksum' => hash('sha256', 'fake-image'.$draft->id),
            'width' => 1000, 'height' => 800, 'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 1]);
        $update = $draft->telegramUpdate;
        $update->update(['status' => 'processing', 'processed_at' => null]);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai',
            'model' => 'test', 'status' => 'completed', 'prompt' => 'assistant', 'started_at' => now(), 'completed_at' => now()]);

        return [$update, $draft, $run];
    }

    private function press(string $data): TelegramUpdate
    {
        $id = ++$this->sequence;
        $this->postJson('/api/telegram/webhook', ['update_id' => $id, 'callback_query' => [
            'id' => "callback-{$id}", 'from' => ['id' => 123], 'data' => $data,
            'message' => ['message_id' => 700, 'chat' => ['id' => 900]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        return TelegramUpdate::query()->where('update_id', $id)->sole();
    }

    private function message(string $text): void
    {
        $this->update(['text' => $text]);
    }

    private function update(array $content): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->sequence, 'message' => [
            'message_id' => $this->sequence, 'from' => ['id' => 123], 'chat' => ['id' => 900], ...$content,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
