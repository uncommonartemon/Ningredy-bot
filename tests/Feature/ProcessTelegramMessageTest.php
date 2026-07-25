<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\ResearchProduct;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Products\ProductIdentityKey;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ProcessTelegramMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_assistant_replies_to_telegram_and_records_the_run(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        ServerAssistantAgent::fake([[
            'response_type' => 'answer',
            'message' => 'Сервер работает нормально.',
            'draft_id' => null,
            'product_ids' => [],
            'operation_ids' => [],
        ]]);
        $update = $this->update();

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        $this->assertDatabaseHas('ai_runs', ['telegram_update_id' => $update->id, 'status' => 'completed']);
        $this->assertDatabaseHas('telegram_updates', ['id' => $update->id, 'status' => 'completed']);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '98765'
            && $request['text'] === 'Сервер работает нормально.');
    }

    public function test_ready_draft_is_sent_as_one_photo_approval_card_with_buttons(): void
    {
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $update = $this->update();
        $researchRun = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'status' => 'completed',
            'prompt' => 'find product',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $researchRun->id,
            'requested_by_telegram_user_id' => $update->telegram_user_id,
            'title' => 'MSI Aegis Exact Black',
            'brand' => 'MSI',
            'model' => 'Aegis Exact',
            'color' => 'Black',
            'description' => 'Готовое описание товара для каталога.',
            'specifications' => [['key' => 'cpu', 'name' => 'Процессор', 'value' => 'Intel Core Ultra 9']],
            'sources' => [['title' => 'Amazon', 'url' => 'https://amazon.com/dp/EXACT', 'type' => 'marketplace']],
            'primary_source_url' => 'https://amazon.com/dp/EXACT',
            'image_urls' => [],
            'images_staged_at' => now(),
            'confidence' => 0.98,
        ]);
        $path = "drafts/{$draft->id}/primary-test.webp";
        Storage::disk('public')->put($path, 'fake-image');
        $draft->media()->create([
            'disk' => 'public',
            'path' => $path,
            'source_url' => 'https://m.media-amazon.com/images/I/exact.jpg',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'checksum' => hash('sha256', 'fake-image'),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        ServerAssistantAgent::fake([[
            'response_type' => 'draft',
            'message' => 'Черновик готов.',
            'draft_id' => $draft->id,
            'product_ids' => [],
            'operation_ids' => [],
        ]]);

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        Http::assertSent(function (HttpRequest $request) use ($draft): bool {
            if (! str_ends_with($request->url(), '/sendPhoto')) {
                return false;
            }

            $parts = collect($request->data())->keyBy('name');
            $caption = (string) ($parts->get('caption')['contents'] ?? '');
            $replyMarkup = json_decode((string) ($parts->get('reply_markup')['contents'] ?? ''), true);

            return str_contains($caption, "Черновик #{$draft->id} готов к добавлению")
                && str_contains($caption, 'Проверено фото: 1')
                && data_get($replyMarkup, 'inline_keyboard.0.0.callback_data') === "draft:add:{$draft->id}";
        });
        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_reply_to_text_is_prepended_as_context_for_the_agent(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $seenPrompt = null;
        ServerAssistantAgent::fake(function (string $prompt) use (&$seenPrompt): array {
            $seenPrompt = $prompt;

            return [
                'response_type' => 'answer',
                'message' => 'ок',
                'draft_id' => null,
                'product_ids' => [],
                'operation_ids' => [],
            ];
        });
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 9000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'апскейль второе фото',
            'reply_to_text' => "#28 · ROG NUC (2025) Gaming Mini PC\nASUS ROG · NUC15JNK",
            'payload' => [],
            'status' => 'received',
        ]);

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        $this->assertStringContainsString('ROG NUC (2025) Gaming Mini PC', (string) $seenPrompt);
        $this->assertStringContainsString('апскейль второе фото', (string) $seenPrompt);
    }

    public function test_reply_includes_a_token_usage_footnote_when_tokens_were_spent(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        ServerAssistantAgent::fake([[
            'response_type' => 'answer',
            'message' => 'Сервер работает нормально.',
            'draft_id' => null,
            'product_ids' => [],
            'operation_ids' => [],
        ]]);
        $update = $this->update();
        // Simulates a nested tool call (e.g. ResearchProduct) having already
        // spent real tokens earlier in this same Telegram interaction.
        AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'test',
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 300],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Сервер работает нормально.')
            && str_contains((string) $request['text'], 'Токены: 1 200')
            && str_contains((string) $request['text'], '(~$0.0068)'));
    }

    public function test_catalog_results_are_sent_as_photo_cards(): void
    {
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        [$withPhoto, $withoutPhoto] = $this->products();
        ServerAssistantAgent::fake([[
            'response_type' => 'catalog_results',
            'message' => 'Вот товары из каталога:',
            'draft_id' => null,
            'product_ids' => [$withPhoto->id, $withoutPhoto->id],
            'operation_ids' => [],
        ]]);
        $update = $this->update();

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '98765'
            && $request['text'] === 'Вот товары из каталога:');
        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/sendPhoto')) {
                return false;
            }
            $parts = collect($request->data())->keyBy('name');
            $caption = (string) ($parts->get('caption')['contents'] ?? '');

            return $parts->get('chat_id')['contents'] === '98765'
                && str_contains($caption, 'Lenovo Card One')
                && str_contains($caption, '999 USD');
        });
        // The product without a local image falls back to a text card.
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Lenovo Card Two'));
        $this->assertDatabaseHas('telegram_updates', ['id' => $update->id, 'status' => 'completed']);
    }

    public function test_catalog_card_sends_every_stored_photo_as_one_album(): void
    {
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'lenovo'], ['name' => 'Lenovo', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => 'lenovo-gallery-test',
            'product_type' => 'laptop',
            'status' => 'published',
            'slug' => 'lenovo-gallery-test',
            'title' => 'Lenovo Gallery Test',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'fingerprint' => 'lenovo-gallery-test-variant',
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);
        foreach (range(0, 2) as $index) {
            Storage::disk('public')->put("products/{$product->id}/photo-{$index}.webp", "bytes-{$index}");
            $product->media()->create([
                'product_variant_id' => $variant->id,
                'type' => 'image',
                'disk' => 'public',
                'path' => "products/{$product->id}/photo-{$index}.webp",
                'verification_status' => 'verified',
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }
        ServerAssistantAgent::fake([[
            'response_type' => 'catalog_results',
            'message' => 'Вот товар:',
            'draft_id' => null,
            'product_ids' => [$product->id],
            'operation_ids' => [],
        ]]);
        $update = $this->update();

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/sendMediaGroup')) {
                return false;
            }
            $parts = collect($request->data())->keyBy('name');
            $media = json_decode((string) ($parts->get('media')['contents'] ?? ''), true) ?? [];

            return count($media) === 3;
        });
        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendPhoto'));
    }

    public function test_catalog_card_includes_specifications_and_sources(): void
    {
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'lenovo'], ['name' => 'Lenovo', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => 'lenovo-specs-test',
            'product_type' => 'laptop',
            'status' => 'published',
            'slug' => 'lenovo-specs-test',
            'title' => 'Lenovo Specs Test',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'fingerprint' => 'lenovo-specs-test-variant',
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);
        $ram = AttributeDefinition::query()->firstOrCreate(
            ['key' => 'ram'],
            ['label' => 'RAM', 'data_type' => 'text', 'is_filterable' => true, 'is_variant' => true],
        );
        $variant->attributes()->create(['attribute_definition_id' => $ram->id, 'value' => '32', 'unit' => 'GB']);
        $product->sources()->create([
            'title' => 'Lenovo store', 'url' => 'https://www.lenovo.com/specs-test', 'domain' => 'lenovo.com',
            'source_type' => 'manufacturer',
        ]);
        ServerAssistantAgent::fake([[
            'response_type' => 'catalog_results',
            'message' => 'Вот товар:',
            'draft_id' => null,
            'product_ids' => [$product->id],
            'operation_ids' => [],
        ]]);
        $update = $this->update();

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Оперативная память: 32 GB')
            && str_contains((string) ($request['text'] ?? ''), 'lenovo.com/specs-test'));
    }

    public function test_failed_hook_does_not_duplicate_the_notification_handle_already_sent(): void
    {
        // Real production bug (2026-07-23): on the final retry attempt,
        // handle()'s catch block notifies the user and re-throws (tries
        // exhausted), then Laravel calls failed() with that same exception -
        // which used to notify a second time for the identical failure.
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        ServerAssistantAgent::fake(fn () => throw new \RuntimeException('Request timed out.'))
            ->preventStrayPrompts();
        $update = $this->update();
        $job = new ProcessTelegramMessage($update->id);

        try {
            $job->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
            $this->fail('Retryable error should have been re-thrown.');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Повторю запрос автоматически'));
    }

    public function test_research_tool_creates_an_audited_pending_draft(): void
    {
        ProductResearchAgent::fake([[
            'status' => 'found',
            'clarification_question' => null,
            'title' => 'Lenovo Legion 5 16IRX9',
            'brand' => 'Lenovo',
            'model' => 'Legion 5 16IRX9',
            'category' => 'laptops',
            'product_type' => 'laptop',
            'color' => 'Luna Grey',
            'description' => 'Игровой ноутбук.',
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB']],
            'sources' => [
                ['title' => 'Amazon', 'url' => 'https://www.amazon.com/dp/LEGION', 'type' => 'marketplace'],
                ['title' => 'Lenovo', 'url' => 'https://www.lenovo.com/example', 'type' => 'manufacturer'],
            ],
            'primary_source_url' => 'https://www.amazon.com/dp/LEGION',
            'official_source_url' => 'https://www.lenovo.com/example',
            'research_notes' => null,
            'image_urls' => collect(range(1, 12))
                ->map(fn (int $index): string => "https://example.com/legion-{$index}.jpg")
                ->all(),
            'confidence' => 0.95,
        ]]);
        $update = $this->update();

        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([]);
        $imageStorage = $this->mock(ProductImageStorage::class);
        $imageStorage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft): int {
            $draft->update(['images_staged_at' => now()]);

            return 3;
        });
        $result = json_decode((new ResearchProduct($update, $resolver, imageStorage: $imageStorage))->handle(new Request([
            'query' => 'Lenovo Legion 5 32 GB',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $this->assertSame('found', $result['status']);
        $this->assertSame(3, $result['image_count']);
        $this->assertDatabaseHas('product_drafts', [
            'telegram_update_id' => $update->id,
            'title' => 'Lenovo Legion 5 16IRX9',
            'category' => 'laptops',
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('ai_operations', [
            'telegram_update_id' => $update->id,
            'action' => 'create_product_draft',
            'status' => 'completed',
        ]);
        $this->assertCount(10, ProductDraft::query()->firstOrFail()->image_urls);
    }

    public function test_research_recognizes_a_product_already_in_the_catalog_instead_of_duplicating_it(): void
    {
        // Real production bug (2026-07-24): a later message about a product
        // that was already researched and approved re-triggered
        // ResearchProduct, creating a duplicate draft instead of being
        // recognized as the existing catalog entry.
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'lenovo'], ['name' => 'Lenovo', 'is_active' => true]);
        $existing = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => ProductIdentityKey::for('Lenovo', 'Legion 5 16IRX9', 'Lenovo Legion 5 16IRX9'),
            'product_type' => 'laptop',
            'status' => 'published',
            'slug' => 'lenovo-legion-5-16irx9',
            'title' => 'Lenovo Legion 5 16IRX9',
            'is_active' => true,
            'published_at' => now(),
        ]);
        ProductResearchAgent::fake([[
            'status' => 'found',
            'clarification_question' => null,
            'title' => 'Lenovo Legion 5 16IRX9',
            'brand' => 'Lenovo',
            'model' => 'Legion 5 16IRX9',
            'category' => 'laptops',
            'product_type' => 'laptop',
            'color' => 'Luna Grey',
            'description' => 'Игровой ноутбук.',
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB']],
            'sources' => [
                ['title' => 'Amazon', 'url' => 'https://www.amazon.com/dp/LEGION', 'type' => 'marketplace'],
                ['title' => 'Lenovo', 'url' => 'https://www.lenovo.com/example', 'type' => 'manufacturer'],
            ],
            'primary_source_url' => 'https://www.amazon.com/dp/LEGION',
            'official_source_url' => 'https://www.lenovo.com/example',
            'research_notes' => null,
            'image_urls' => [],
            'confidence' => 0.95,
        ]]);
        $update = $this->update();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([]);

        $result = json_decode((new ResearchProduct($update, $resolver))->handle(new Request([
            'query' => 'Lenovo Legion 5 32 GB',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $this->assertSame('already_in_catalog', $result['status']);
        $this->assertSame($existing->id, $result['product_id']);
        $this->assertSame(0, ProductDraft::query()->count());
    }

    /** @return array{Product, Product} */
    private function products(): array
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'lenovo'], ['name' => 'Lenovo', 'is_active' => true]);
        $make = function (string $slug, string $title, ?float $price) use ($category, $brand): array {
            $product = Product::query()->create([
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'canonical_key' => $slug,
                'product_type' => 'laptop',
                'status' => 'published',
                'slug' => $slug,
                'title' => $title,
                'is_active' => true,
                'published_at' => now(),
            ]);
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'fingerprint' => $slug.'-variant',
                'name' => 'Default',
                'price' => $price,
                'currency' => 'USD',
                'stock_status' => 'in_stock',
                'is_default' => true,
                'is_active' => true,
            ]);

            return [$product, $variant];
        };
        [$withPhoto, $variantOne] = $make('lenovo-card-one', 'Lenovo Card One', 999);
        [$withoutPhoto] = $make('lenovo-card-two', 'Lenovo Card Two', 499);

        Storage::disk('public')->put("products/{$withPhoto->id}/primary-test.webp", 'fake-image-bytes');
        $withPhoto->media()->create([
            'product_variant_id' => $variantOne->id,
            'type' => 'image',
            'disk' => 'public',
            'path' => "products/{$withPhoto->id}/primary-test.webp",
            'url' => "/storage/products/{$withPhoto->id}/primary-test.webp",
            'verification_status' => 'verified',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        return [$withPhoto, $withoutPhoto];
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 9000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'Проверь состояние сервера',
            'payload' => ['update_id' => 2001],
            'status' => 'received',
        ]);
    }
}
