<?php

namespace Tests\Feature;

use App\Ai\Tools\GetProductSearchIntent;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GetProductSearchIntentToolTest extends TestCase
{
    use RefreshDatabase;

    private function draftRequesting(string $text): ProductDraft
    {
        $draft = new ProductDraft([
            'title' => 'ASUS Vivobook 15 Green',
            'brand' => 'ASUS',
            'model' => 'X1504VA',
            'color' => 'Green',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => 'X1504VA-BQ4485'],
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => $text]));

        return $draft;
    }

    public function test_returns_the_request_the_selected_product_and_the_category_hint(): void
    {
        Category::query()->create([
            'name' => 'Laptops',
            'slug' => 'zzz-scratch-laptops-intent',
            'product_search_hint' => 'не используй фото товара в коробке',
        ]);
        $context = ProductRequestContext::forDraft($this->draftRequesting('найди зелёный ноутбук асус, не серебристый'));

        $result = json_decode(
            (string) (new GetProductSearchIntent($context, 'zzz-scratch-laptops-intent'))
                ->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('найди зелёный ноутбук асус, не серебристый', $result['original_operator_request']);
        $this->assertSame('provided', $result['original_operator_request_status']);
        $this->assertSame('X1504VA', $result['selected_product']['model']);
        $this->assertSame([['key' => 'sku', 'value' => 'X1504VA-BQ4485']], $result['selected_product']['identifiers']);
        $this->assertSame('Green', $result['selected_product']['color']);
        $this->assertSame('не используй фото товара в коробке', $result['category_search_hint']);
    }

    public function test_an_unbound_run_says_the_request_is_unavailable(): void
    {
        $result = json_decode(
            (string) (new GetProductSearchIntent(ProductRequestContext::unavailable(), null))->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertNull($result['original_operator_request']);
        $this->assertSame('unavailable', $result['original_operator_request_status']);
        $this->assertNull($result['selected_product']);
        $this->assertNull($result['category_search_hint']);
    }

    public function test_a_missing_category_hint_does_not_block_the_request(): void
    {
        $context = ProductRequestContext::forDraft($this->draftRequesting('найди зелёный ноутбук асус'));

        $result = json_decode(
            (string) (new GetProductSearchIntent($context, 'no-such-category'))->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('найди зелёный ноутбук асус', $result['original_operator_request']);
        $this->assertNull($result['category_search_hint']);
    }
}
