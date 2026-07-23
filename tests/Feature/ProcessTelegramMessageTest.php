<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\ResearchProduct;
use App\Jobs\ProcessTelegramMessage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Products\ProductImageResolver;
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
            'color' => 'Luna Grey',
            'description' => 'Игровой ноутбук.',
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB']],
            'sources' => [['title' => 'Lenovo', 'url' => 'https://www.lenovo.com/example']],
            'image_urls' => ['https://example.com/legion.jpg'],
            'confidence' => 0.95,
        ]]);
        $update = $this->update();

        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([]);
        $result = json_decode((new ResearchProduct($update, $resolver))->handle(new Request([
            'query' => 'Lenovo Legion 5 32 GB',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $this->assertSame('found', $result['status']);
        $this->assertDatabaseHas('product_drafts', [
            'telegram_update_id' => $update->id,
            'title' => 'Lenovo Legion 5 16IRX9',
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('ai_operations', [
            'telegram_update_id' => $update->id,
            'action' => 'create_product_draft',
            'status' => 'completed',
        ]);
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
