<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\Telegram\CatalogTelegramNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramCatalogNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
            'services.telegram.proxy_url' => 'https://catalog.example',
        ]);
    }

    public function test_admin_command_and_button_offer_native_crud_without_ai_or_jobs(): void
    {
        foreach (['/admin', '🛠 Админка'] as $index => $text) {
            $this->message($text, 910000 + $index);
        }
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.url') === 'https://catalog.example/admin/products/create');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ai_runs', 0);
        $this->get('/admin/products/create')->assertRedirect('/admin/login');
    }

    public function test_catalog_links_to_the_exact_product_edit_form(): void
    {
        $product = Product::create([
            'title' => 'Manual navigation laptop', 'product_type' => 'laptop',
            'category_id' => Category::where('slug', 'laptops')->value('id'),
            'status' => 'published', 'is_active' => true,
        ]);
        $product->variants()->create([
            'condition' => 'new', 'currency' => 'CZK', 'stock_status' => 'in_stock',
            'quantity' => 1, 'is_active' => true, 'is_default' => true,
        ]);

        $this->message('/catalog', 910003);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && data_get($request->data(), 'reply_markup.inline_keyboard.2.0.url') === 'https://catalog.example/admin/products/'.$product->id.'/edit');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_missing_or_unsafe_address_does_not_produce_a_broken_admin_button(): void
    {
        foreach (['', 'javascript:alert(1)', 'https://user:password@catalog.example', 'https://catalog.example?redirect=other'] as $url) {
            config(['services.telegram.proxy_url' => $url]);
            $markup = app(CatalogTelegramNavigation::class)->catalog();
            $this->assertSame('product:create', $markup['inline_keyboard'][0][0]['callback_data']);
            $this->assertArrayNotHasKey('url', $markup['inline_keyboard'][0][0]);
        }
        config(['services.telegram.proxy_url' => '']);
        $this->message('/admin', 910004);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'product:create');
        Queue::assertNothingPushed();
    }

    public function test_unauthorized_user_does_not_receive_management_navigation(): void
    {
        $this->message('/admin', 910005, 777);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function message(string $text, int $id, int $userId = 12345): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $id, 'message' => ['message_id' => $id,
                'from' => ['id' => $userId], 'chat' => ['id' => $userId], 'text' => $text],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
