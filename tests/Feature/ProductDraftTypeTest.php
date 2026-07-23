<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDraftTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_product_type_wins_over_keyword_guessing(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Lenovo IdeaPad Slim 3 15AMN8', 'IdeaPad Slim 3 15AMN8', 'laptop'),
        );

        $this->assertSame('laptop', $product->product_type);
        $this->assertSame('laptops', $product->category->slug);
    }

    public function test_ideapad_without_explicit_type_is_guessed_as_laptop(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Lenovo IdeaPad Slim 3 15AMN8', 'IdeaPad Slim 3 15AMN8', null),
        );

        $this->assertSame('laptop', $product->product_type);
    }

    public function test_specs_with_cpu_ram_do_not_turn_a_laptop_into_a_component(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Ноутбук Acer Aspire Lite', 'Aspire Lite AL15', null),
        );

        $this->assertSame('laptop', $product->product_type);
    }

    private function draft(string $title, string $model, ?string $productType): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 90000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => $title,
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => $title,
            'started_at' => now(),
        ]);

        return ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => $title,
            'brand' => 'Lenovo',
            'model' => $model,
            'product_type' => $productType,
            'description' => 'Описание товара.',
            'specifications' => [
                ['key' => 'cpu', 'name' => 'CPU', 'value' => 'AMD Ryzen 5'],
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB'],
                ['key' => 'storage', 'name' => 'SSD', 'value' => '512 GB'],
            ],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product']],
            'image_urls' => [],
            'confidence' => 0.9,
        ]);
    }
}
