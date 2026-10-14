<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductGalleryRecipeProof;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class TrainingBrowserContextTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_replay_does_not_replace_the_training_pages_observation(): void
    {
        config([
            'product-images.browser_fallback.enabled' => true,
            'product-images.browser_fallback.script' => 'tests/Fixtures/rejected-product-scout.mjs',
            'product-images.browser_fallback.host_visit_spacing_seconds' => 0,
        ]);
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $browser = (new ReflectionProperty($trainer, 'browser'))->getValue($trainer);
        $browser->scout('https://shop.example/model-009it');
        $observation = $browser->exportObservation();
        $recipe = ProductGalleryRecipe::create([
            'domain' => 'shop.example', 'path_pattern' => '*', 'status' => 'active',
            'success_count' => 1, 'recipe' => ['collect_selectors' => ['.gallery img']],
        ]);
        app(ProductGalleryRecipeProof::class)->remember($recipe, 'https://shop.example/control', ['passed' => true]);

        $result = (new ReflectionMethod($trainer, 'canaryFailure'))->invoke(
            $trainer, $recipe, $recipe->recipe, 'https://shop.example/model-009it', null, null,
        );

        $this->assertSame('interrupted', $result['status']);
        $this->assertSame($observation, $browser->exportObservation());
        $this->assertSame('https://shop.example/model-008it', $browser->lastProductPageUrl());
    }

    public function test_rejected_scout_reaches_resolver_browser_with_real_distinct_container_instances(): void
    {
        config([
            'product-images.browser_fallback.enabled' => true,
            'product-images.browser_fallback.script' => 'tests/Fixtures/rejected-product-scout.mjs',
            'product-images.browser_fallback.host_visit_spacing_seconds' => 0,
        ]);
        ProductGalleryPreflightAgent::fake()->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake()->preventStrayPrompts();
        $request = TelegramUpdate::create([
            'update_id' => 918281, 'chat_id' => '1', 'telegram_user_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'MODEL-009IT',
        ]);
        $run = AiRun::create([
            'telegram_update_id' => $request->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'research', 'started_at' => now(), 'completed_at' => now(),
        ]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $request->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'MODEL-009IT', 'model' => 'MODEL-009IT', 'product_type' => 'laptop',
            'sources' => [], 'image_urls' => [],
            'specifications' => [['key' => 'sku', 'value' => 'MODEL-009IT']],
        ]);
        $resolver = app(ProductImageResolver::class);
        $outer = (new ReflectionProperty($resolver, 'browser'))->getValue($resolver);
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $inner = (new ReflectionProperty($trainer, 'browser'))->getValue($trainer);
        $this->assertNotSame($outer, $inner);

        $this->assertSame([], $outer->extract('https://shop.example/model-009it',
            context: [ProductRequestContext::CONTEXT_KEY => $draft->id]));

        $this->assertSame('https://shop.example/model-008it', $outer->lastProductPageUrl());
        $this->assertSame('MODEL-008IT', $outer->lastProductIdentity()['sku']);
        $this->assertSame('Actual product SKU: MODEL-008IT', $outer->lastSpecificationText());
        $this->assertSame('conflicting', $outer->lastSourceIdentityAssessment()['verdict']);
        $this->assertSame('MODEL-008IT', $outer->exportObservation()['scout']['title']);
        ProductGalleryPreflightAgent::assertNeverPrompted();
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
    }
}
