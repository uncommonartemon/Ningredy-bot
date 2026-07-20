<?php

namespace Tests\Feature;

use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\PrepareProductDeletion;
use App\Jobs\ProcessTelegramMessage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class TelegramProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_preparing_deletion_does_not_delete_and_job_sends_confirmation_buttons(): void
    {
        [$product, $sourceUpdate] = $this->productAndUpdate();
        $prepared = json_decode(
            (new PrepareProductDeletion($sourceUpdate))->handle(new Request(['product_id' => $product->id])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($prepared['confirmation_required']);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('ai_operations', [
            'id' => $prepared['operation_id'],
            'status' => 'awaiting_confirmation',
        ]);

        ServerAssistantAgent::fake([[
            'response_type' => 'delete_confirmation',
            'message' => 'Требуется подтверждение удаления.',
            'draft_id' => null,
            'product_ids' => [$product->id],
            'operation_ids' => [$prepared['operation_id']],
        ]]);
        (new ProcessTelegramMessage($sourceUpdate->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === "product:delete:confirm:{$prepared['operation_id']}"
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.callback_data') === "product:delete:cancel:{$prepared['operation_id']}");
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_confirmation_permanently_deletes_product_and_local_images(): void
    {
        [$product, $sourceUpdate, $path] = $this->productAndUpdate(withImage: true);
        $prepared = json_decode(
            (new PrepareProductDeletion($sourceUpdate))->handle(new Request(['product_id' => $product->id])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->postDeletionCallback(71002, "product:delete:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('ai_operations', [
            'id' => $prepared['operation_id'],
            'status' => 'completed',
        ]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_cancellation_keeps_product_and_local_images(): void
    {
        [$product, $sourceUpdate, $path] = $this->productAndUpdate(withImage: true);
        $prepared = json_decode(
            (new PrepareProductDeletion($sourceUpdate))->handle(new Request(['product_id' => $product->id])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->postDeletionCallback(71003, "product:delete:cancel:{$prepared['operation_id']}");

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('ai_operations', [
            'id' => $prepared['operation_id'],
            'status' => 'cancelled',
        ]);
        Storage::disk('public')->assertExists($path);
    }

    /** @return array{Product, TelegramUpdate, string} */
    private function productAndUpdate(bool $withImage = false): array
    {
        $category = Category::query()->where('slug', 'components')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'nvidia'], ['name' => 'NVIDIA', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => 'nvidia-rtx-5070',
            'product_type' => 'component',
            'status' => 'published',
            'slug' => 'nvidia-rtx-5070',
            'title' => 'NVIDIA GeForce RTX 5070',
            'is_active' => false,
            'published_at' => now(),
        ]);
        $path = "products/{$product->id}/primary-test.webp";

        if ($withImage) {
            Storage::disk('public')->put($path, 'image-bytes');
            $product->media()->create([
                'type' => 'image',
                'disk' => 'public',
                'path' => $path,
                'role' => 'primary',
                'url' => '/storage/'.$path,
                'is_primary' => true,
            ]);
        }

        $update = TelegramUpdate::query()->create([
            'update_id' => 71001,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 80,
            'text' => 'Удалить NVIDIA RTX 5070',
            'payload' => ['update_id' => 71001],
            'status' => 'received',
        ]);

        return [$product, $update, $path];
    }

    private function postDeletionCallback(int $updateId, string $data): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => "callback-{$updateId}",
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => $data,
                'message' => [
                    'message_id' => 81,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
