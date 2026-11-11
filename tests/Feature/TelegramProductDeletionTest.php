<?php

namespace Tests\Feature;

use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\PrepareProductDeletion;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiOperation;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramCatalogDeletion;
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
            // The fixture's product is off sale already: there is nothing to take off, so two buttons, one under the other.
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.callback_data') === "product:delete:cancel:{$prepared['operation_id']}");
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

    public function test_old_confirmation_cannot_delete_a_changed_secondary_configuration(): void
    {
        [$product, $update, $path] = $this->productAndUpdate(withImage: true);
        $product->variants()->create(['name' => 'Default', 'currency' => 'CZK', 'is_default' => true]);
        $other = $product->variants()->create(['name' => 'Other', 'price' => 500, 'currency' => 'CZK']);
        $prepared = json_decode((new PrepareProductDeletion($update))->handle(new Request(['product_id' => $product->id])), true);
        $other->update(['price' => 600]);

        $this->postDeletionCallback(71004, "product:delete:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'stale']);
        Storage::disk('public')->assertExists($path);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Товар изменился'));
    }

    public function test_confirmation_expires_and_never_refreshes_on_tool_retry(): void
    {
        [$product, $update] = $this->productAndUpdate();
        $tool = new PrepareProductDeletion($update);
        $prepared = json_decode($tool->handle(new Request(['product_id' => $product->id])), true);
        $this->travel(11)->minutes();
        $tool->handle(new Request(['product_id' => $product->id]));
        $this->postDeletionCallback(71005, "product:delete:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'expired']);
    }

    public function test_cancel_then_old_confirm_never_deletes(): void
    {
        [$product, $update] = $this->productAndUpdate();
        $prepared = json_decode((new PrepareProductDeletion($update))->handle(new Request(['product_id' => $product->id])), true);
        $this->postDeletionCallback(71006, "product:delete:cancel:{$prepared['operation_id']}");
        $this->postDeletionCallback(71007, "product:delete:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 71007, 'status' => 'already_processed']);
    }

    public function test_other_authorized_operator_cannot_confirm_delete_or_cancel_search(): void
    {
        [$product, $update] = $this->productAndUpdate();
        config(['services.telegram.allowed_user_ids' => ['12345', '67890']]);
        $prepared = json_decode((new PrepareProductDeletion($update))->handle(new Request(['product_id' => $product->id])), true);
        $this->postDeletionCallback(71008, "product:delete:confirm:{$prepared['operation_id']}", userId: 67890);
        $this->postDeletionCallback(71009, "search:cancel:{$update->id}", userId: 67890);
        $this->postDeletionCallback(71010, "search:cancel:{$update->id}", chatId: 88888);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame('awaiting_confirmation', AiOperation::find($prepared['operation_id'])->status);
        $this->assertNull($update->fresh()->cancel_requested_at);
        foreach ([71008, 71009, 71010] as $id) {
            $this->assertDatabaseHas('telegram_updates', ['update_id' => $id, 'status' => 'rejected']);
        }
    }

    public function test_the_confirmation_offers_to_take_a_product_off_sale_instead_and_then_nothing_is_deleted(): void
    {
        [$product, $update, $path] = $this->productAndUpdate(withImage: true);
        $product->putBackOnSale();
        $prepared = json_decode((new PrepareProductDeletion($update))->handle(new Request(['product_id' => $product->id])), true);
        [$question, $buttons] = app(TelegramCatalogDeletion::class)->confirmation(AiOperation::findOrFail($prepared['operation_id']));
        $this->assertStringContainsString('продажи останутся в учёте', $question);
        $this->assertSame(["product:delete:confirm:{$prepared['operation_id']}", "product:delete:sleep:{$prepared['operation_id']}",
            "product:delete:cancel:{$prepared['operation_id']}"], collect($buttons['inline_keyboard'])->flatten(1)->pluck('callback_data')->all());

        $this->postDeletionCallback(71011, "product:delete:sleep:{$prepared['operation_id']}");

        $this->assertFalse($product->fresh()->isOnSale());
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'cancelled']);
        Storage::disk('public')->assertExists($path);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'снят с продажи вместо удаления'));
        // The «delete» button of that confirmation is dead now.
        $this->postDeletionCallback(71012, "product:delete:confirm:{$prepared['operation_id']}");
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_the_card_buttons_put_a_product_to_sleep_and_wake_it(): void
    {
        [$product] = $this->productAndUpdate();
        $product->putBackOnSale();

        $this->postDeletionCallback(71013, "product:sleep:{$product->id}");
        $this->assertFalse($product->fresh()->isOnSale());
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'снят с продажи')
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.callback_data') === "product:wake:{$product->id}");

        $this->postDeletionCallback(71014, "product:wake:{$product->id}");
        $this->assertTrue($product->fresh()->isOnSale());
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'снова в продаже') && str_contains($request['text'], 'В наличии 0 шт.')
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.callback_data') === "product:sleep:{$product->id}");
        // Each press is in the audit, as the assistant's own switch is.
        $this->assertSame(['deactivate_product', 'activate_product'], AiOperation::query()->where('target_id', $product->id)
            ->whereIn('action', ['deactivate_product', 'activate_product'])->orderBy('id')->pluck('action')->all());
        foreach ([71013, 71014] as $id) {
            $this->assertDatabaseHas('telegram_updates', ['update_id' => $id, 'status' => 'command']);
        }
        // A product that is gone is answered plainly.
        $this->postDeletionCallback(71015, 'product:wake:999999');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage') && str_contains($request['text'], 'Товар не найден'));
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

    private function postDeletionCallback(int $updateId, string $data, int $userId = 12345, int $chatId = 98765): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => "callback-{$updateId}",
                'from' => ['id' => $userId, 'username' => 'admin'],
                'data' => $data,
                'message' => [
                    'message_id' => 81,
                    'chat' => ['id' => $chatId],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
