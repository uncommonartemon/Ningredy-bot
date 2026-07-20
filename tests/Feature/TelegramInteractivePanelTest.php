<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramInteractivePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);

        Http::fake([
            'https://example.com/product.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_start_command_shows_the_main_keyboard_without_starting_ai(): void
    {
        Queue::fake();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3001,
            'message' => [
                'message_id' => 70,
                'from' => ['id' => 12345, 'username' => 'admin'],
                'chat' => ['id' => 98765],
                'text' => '/start',
            ],
        ], $this->headers())->assertOk();

        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 3001,
            'status' => 'command',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['reply_markup']['keyboard'][0][0]['text'] === '🔎 Найти товар'
        );
    }

    public function test_admin_can_approve_a_draft_with_an_inline_button(): void
    {
        Queue::fake();
        $sourceUpdate = TelegramUpdate::query()->create([
            'update_id' => 3002,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 71,
            'text' => 'Find a laptop',
            'payload' => ['update_id' => 3002],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'Find a laptop',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Lenovo Legion',
            'brand' => 'Lenovo',
            'model' => 'Legion',
            'description' => "Closest product found for the user's request. This is a family-level match rather than one exact SKU.",
            'specifications' => [
                ['name' => 'RAM', 'value' => '32 GB DDR5'],
            ],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product']],
            'image_urls' => ['https://example.com/product.jpg'],
            'confidence' => 0.8,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3003,
            'callback_query' => [
                'id' => 'callback-1',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:approve:{$draft->id}",
                'message' => [
                    'message_id' => 72,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->assertDatabaseHas('product_drafts', [
            'id' => $draft->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('products', [
            'title' => 'Lenovo Legion',
            'status' => 'published',
            'is_active' => true,
        ]);
        $product = \App\Models\Product::query()->where('title', 'Lenovo Legion')->firstOrFail();
        $this->assertStringNotContainsString('Closest', (string) $product->description);
        $this->assertStringContainsString('RAM: 32 GB DDR5', (string) $product->description);
        $this->assertStringContainsString('family-level match', (string) $draft->fresh()->research_notes);
        $this->assertDatabaseHas('product_variants', [
            'name' => 'Базовая конфигурация',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->assertNotNull($draft->fresh()->approved_product_id);
        $this->assertNotNull($draft->fresh()->approved_variant_id);
        $this->assertDatabaseHas('brands', ['name' => 'Lenovo', 'slug' => 'lenovo']);
        $this->assertDatabaseHas('attribute_values', [
            'product_variant_id' => $draft->fresh()->approved_variant_id,
            'value' => '32 GB DDR5',
            'numeric_value' => 32,
            'unit' => 'GB',
        ]);
        $this->assertDatabaseHas('product_sources', [
            'product_draft_id' => $draft->id,
            'url' => 'https://example.com/product',
        ]);
        $this->assertDatabaseCount('product_media', 0);
        Queue::assertPushed(StoreProductImages::class, fn (StoreProductImages $job): bool => $job->draftId === $draft->id);
        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 3003,
            'status' => 'completed',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'callback-1'
        );
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageReplyMarkup')
            && $request['reply_markup']['inline_keyboard'] === []
        );
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    private function headers(): array
    {
        return ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'];
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(900, 700);
        $background = imagecolorallocate($image, 240, 240, 240);
        $foreground = imagecolorallocate($image, 20, 30, 40);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 140, 120, 760, 580, $foreground);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }
}
