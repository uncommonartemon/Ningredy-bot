<?php

namespace Tests\Feature;

use App\Ai\Agents\CardHighlightsAgent;
use App\Jobs\ChooseCardHighlights;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CardHighlights;
use App\Services\Catalog\ProductCardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Operator, 2026-11-08: a laptop's catalog card showed its camera, Bluetooth, card reader
 * and Ethernet, because the card took the first four characteristics by group. A model
 * now chooses what the card shows when the product is formed.
 */
class CardHighlightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_model_chooses_from_the_products_own_characteristics_only(): void
    {
        $prompts = [];
        CardHighlightsAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['keys' => ['cpu', 'invented', 'gpu', 'cpu', 'camera', 'bluetooth']];
        })->preventStrayPrompts();
        $product = $this->laptop();

        $keys = app(CardHighlights::class)->choose($product);

        $this->assertSame(['cpu', 'gpu', 'camera', 'bluetooth'], $keys);
        $this->assertSame($keys, $product->fresh()->card_attribute_keys);
        $this->assertStringContainsString('Intel Core Ultra 9 275HX', $prompts[0]);
        $this->assertStringContainsString('Bluetooth 5.4', $prompts[0]);
        $this->assertSame('completed', AiRun::query()->latest('id')->value('status'));
        // The storefront card gets the choice and shows it first.
        $this->assertSame($keys, app(ProductCardPayload::class)->for($product->fresh(['variants', 'attributes.definition', 'category.translations']))['card_keys']);
    }

    public function test_a_failed_choice_keeps_the_usual_order_and_is_not_retried_by_itself(): void
    {
        CardHighlightsAgent::fake(fn () => throw new RuntimeException('provider down'))->preventStrayPrompts();
        $product = $this->laptop();

        (new ChooseCardHighlights($product->id))->handle(app(CardHighlights::class));

        $this->assertSame([], $product->fresh()->card_attribute_keys);
        $this->assertSame('failed', AiRun::query()->latest('id')->value('status'));
        $this->assertSame([], app(CardHighlights::class)->pendingProductIds(10));
    }

    public function test_the_schedule_hands_over_only_products_never_chosen_that_have_characteristics(): void
    {
        Queue::fake();
        $waiting = $this->laptop();
        $chosen = $this->laptop(['card_attribute_keys' => ['cpu']]);
        $bare = Product::query()->create(['title' => 'Nothing known yet', 'category_id' => $this->category(), 'product_type' => 'laptop', 'status' => 'draft']);

        $this->artisan('catalog:card-highlights')->assertSuccessful();
        Queue::assertPushed(ChooseCardHighlights::class, 1);
        Queue::assertPushed(ChooseCardHighlights::class, fn (ChooseCardHighlights $job): bool => $job->productId === $waiting->id);

        // Asked again on purpose, for a product that already has a choice.
        $this->artisan('catalog:card-highlights', ['--product' => [$chosen->id]])->assertSuccessful();
        Queue::assertPushed(ChooseCardHighlights::class, fn (ChooseCardHighlights $job): bool => $job->productId === $chosen->id);
        Queue::assertNotPushed(ChooseCardHighlights::class, fn (ChooseCardHighlights $job): bool => $job->productId === $bare->id);
    }

    private function laptop(array $overrides = []): Product
    {
        $product = Product::query()->create(['title' => 'MSI Vector 17 HX AI A2XWJG-009FR', 'category_id' => $this->category(),
            'product_type' => 'laptop', 'status' => 'published', 'is_active' => true, ...$overrides]);
        $variant = $product->variants()->create(['condition' => 'new', 'currency' => 'CZK', 'quantity' => 1,
            'stock_status' => 'in_stock', 'is_default' => true, 'is_active' => true]);
        foreach ([['camera', 'Camera', '1920 × 1080 pixels, Full HD'], ['bluetooth', 'Bluetooth', 'Bluetooth 5.4'],
            ['cpu', 'Processor', 'Intel Core Ultra 9 275HX'], ['gpu', 'Graphics', 'NVIDIA GeForce RTX 5080']] as [$key, $label, $value]) {
            $definition = AttributeDefinition::query()->firstOrCreate(['key' => $key], ['label' => $label, 'data_type' => 'text']);
            $product->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => $value,
                ...($key === 'gpu' ? ['product_variant_id' => $variant->id] : [])]);
        }

        return $product->fresh();
    }

    private function category(): int
    {
        return (int) Category::query()->where('slug', 'laptops')->value('id');
    }
}
