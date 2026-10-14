<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Playwright\BrowserRunner;
use App\Services\Playwright\BrowserRunRequest;
use App\Services\Playwright\BrowserRunResult;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Products\ProductRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DraftGalleryTopUpPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://93.184.216.34/product/cpu';

    private const IMAGE = 'https://93.184.216.34/front.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'product-images.max_images' => 3,
            'product-images.max_images_by_type.component' => 3,
            'product-images.browser_fallback.enabled' => true,
            'product-images.browser_fallback.host_visit_spacing_seconds' => 0,
        ]);
        Http::preventStrayRequests();
    }

    public function test_one_frame_component_reuses_recipe_once_without_training_or_damaging_health(): void
    {
        Category::where('slug', 'components')->update([
            'gallery_search_strategy' => Category::GALLERY_SEARCH_VISION_FIRST,
            'minimum_verified_images' => 1, 'minimum_image_width' => 500,
        ]);
        $draft = $this->draft();
        $original = $draft->media()->firstOrFail();
        $bytes = Storage::disk('public')->get($original->path);
        $callback = $this->update();
        $recipe = ProductGalleryRecipe::create([
            'domain' => '93.184.216.34', 'path_pattern' => '*', 'status' => 'active', 'failure_count' => 0,
            'recipe' => ['gallery_present' => true, 'content_confirmed_product' => true, 'collect_selectors' => ['#gallery img']],
        ]);
        Http::fake([self::PAGE => Http::response('<html><h1>Intel Core i5-14600K</h1></html>', 200, ['Content-Type' => 'text/html'])]);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');
        $runner = Mockery::mock(BrowserRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function (BrowserRunRequest $request) use ($draft): BrowserRunResult {
            $this->assertSame(1, $request->context['minimum_verified_images']);
            $this->assertSame(500, $request->context['minimum_image_width']);
            $this->assertSame('components', $request->context['category_slug']);
            $this->assertSame($draft->id, $request->context[ProductRequestContext::CONTEXT_KEY]);

            return new BrowserRunResult(['images' => [self::IMAGE], 'diagnostics' => [
                'strict_recipe' => true, 'partial' => false, 'observed_gallery_count' => 0,
                'dom_candidates' => 1, 'raw_dom_candidates' => 1,
                'distinct_dom_assets' => 1, 'validated_candidates' => 1,
                'validated_image_evidence' => [['url' => self::IMAGE, 'width' => 1024, 'height' => 1024, 'source' => 'recipe_dom']],
                'action_plan' => ['required' => false, 'complete' => true],
            ]], hasObservation: true);
        });
        $this->app->instance(BrowserRunner::class, $runner);
        $messages = [];
        $stored = app(ProductImageStorage::class)->topUpDraftMedia(
            $draft, function (string $message) use (&$messages): void {
                $messages[] = $message;
            }, $callback->id,
        );

        $this->assertSame(0, $stored);
        $this->assertSame(0, $recipe->fresh()->failure_count, (string) $recipe->fresh()->last_error);
        $this->assertSame(1, $recipe->fresh()->success_count);
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertSame([$original->id], $draft->media()->pluck('id')->all());
        $this->assertSame($bytes, Storage::disk('public')->get($original->path));
        $this->assertStringNotContainsString('выполняю дополнительный поиск', implode(' ', $messages));
        $this->assertSame(1, ProductSourceAttempt::where('telegram_update_id', $callback->id)
            ->where('product_draft_id', $draft->id)->where('phase', 'active_recipe')->count());
        Http::assertSentCount(1);
    }

    #[DataProvider('policies')]
    public function test_top_up_propagates_category_policy_and_does_not_resolve_an_exhausted_source_twice(
        string $strategy, int $minimum, int $width, bool $reuseOnly,
    ): void {
        Category::where('slug', 'components')->update([
            'gallery_search_strategy' => $strategy, 'minimum_verified_images' => $minimum,
            'minimum_image_width' => $width, 'minimum_image_height' => 250,
        ]);
        $draft = $this->draft();
        $callback = $this->update();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('isConfirmedGalleryImage', 'isPartialGalleryImage')->andReturn(false);
        $resolver->shouldReceive('sourceContextForPage')->andReturn(null);
        $resolver->shouldReceive('resolve')->once()->andReturnUsing(
            function (array $sources, int $limit, $debug, ?int $runId, bool $forceInteractive = false,
                bool $staticOnly = false, bool $activeRecipeOnly = false) use ($draft, $callback, $minimum, $width, $reuseOnly): array {
                $this->assertSame($callback->id, $runId);
                $this->assertSame($draft->id, $sources[0][ProductRequestContext::SOURCE_KEY]);
                $this->assertSame($minimum, $sources[0]['_minimum_verified_images']);
                $this->assertSame($width, $sources[0]['_minimum_image_width']);
                $this->assertSame(250, $sources[0]['_minimum_image_height']);
                $this->assertSame('components', $sources[0]['_category_slug']);
                $this->assertSame($reuseOnly, $activeRecipeOnly);

                return [];
            },
        );
        $messages = [];
        $this->assertSame(0, app(ProductImageStorage::class)->topUpDraftMedia(
            $draft, function (string $message) use (&$messages): void {
                $messages[] = $message;
            }, $callback->id,
        ));
        $this->assertSame(1, $draft->media()->count());
        $this->assertStringNotContainsString('выполняю дополнительный поиск', implode(' ', $messages));
        Http::assertNothingSent();
    }

    public static function policies(): array
    {
        return [
            'vision first' => [Category::GALLERY_SEARCH_VISION_FIRST, 1, 500, true],
            'playwright first' => [Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST, 4, 900, false],
            'auto' => [Category::GALLERY_SEARCH_AUTO, 2, 650, false],
        ];
    }

    public function test_a_new_verified_frame_is_appended_without_replacing_the_original(): void
    {
        $draft = $this->draft();
        $original = $draft->media()->firstOrFail();
        $originalBytes = Storage::disk('public')->get($original->path);
        $newUrl = 'https://93.184.216.34/back.jpg';
        $resolver = Mockery::mock(app(ProductImageResolver::class))->makePartial();
        $resolver->shouldReceive('resolve')->once()->andReturn([$newUrl]);
        $this->app->instance(ProductImageResolver::class, $resolver);

        $image = imagecreatetruecolor(800, 800);
        imagefilledrectangle($image, 0, 0, 399, 799, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Http::fake([$newUrl => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg'])]);
        $this->mock(ProductImageVisionVerifier::class)->shouldReceive('select')->once()
            ->andReturnUsing(function (ProductDraft $draft, array $candidates) use ($newUrl): array {
                $this->assertCount(1, $candidates);
                $this->assertSame($newUrl, $candidates[0]['source_url']);

                return $candidates;
            });

        $this->assertSame(1, app(ProductImageStorage::class)->topUpDraftMedia($draft));
        $this->assertSame(2, $draft->media()->count());
        $this->assertSame($originalBytes, Storage::disk('public')->get($original->path));
        $this->assertTrue($draft->media()->whereKey($original->id)->exists());
        $this->assertSame(self::PAGE, $draft->fresh()->primary_source_url);
        $added = $draft->media()->where('source_url', $newUrl)->firstOrFail();
        $this->assertFalse($added->is_primary);
        Storage::disk('public')->assertExists($added->path);
        Http::assertSentCount(1);
    }

    public function test_missing_source_does_not_start_a_different_gallery_or_touch_existing_photos(): void
    {
        $draft = $this->draft();
        $draft->update(['primary_source_url' => null, 'sources' => []]);
        $this->mock(ProductImageResolver::class, function ($mock): void {
            $mock->shouldReceive('isConfirmedGalleryImage', 'isPartialGalleryImage')->andReturn(false);
            $mock->shouldNotReceive('resolve');
        });
        $this->assertSame(0, app(ProductImageStorage::class)->topUpDraftMedia($draft));
        $this->assertSame(1, $draft->media()->count());
        Http::assertNothingSent();
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => random_int(10000, 999999),
            'telegram_user_id' => '1', 'chat_id' => '1', 'payload' => [], 'status' => 'completed']);
    }

    private function draft(): ProductDraft
    {
        $update = $this->update();
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'status' => 'pending_review', 'title' => 'Intel Core i5-14600K', 'brand' => 'Intel', 'model' => 'i5-14600K',
            'category' => 'components', 'product_type' => 'component', 'specifications' => [], 'confidence' => 1,
            'primary_source_url' => self::PAGE, 'image_urls' => [self::IMAGE],
            'sources' => [['url' => self::PAGE, 'type' => 'retailer', 'image_urls' => [self::IMAGE]]],
        ]);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0S0AAAAASUVORK5CYII=');
        $path = 'drafts/'.$draft->id.'/existing.png';
        Storage::disk('public')->put($path, $bytes);
        $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => self::IMAGE,
            'mime_type' => 'image/png', 'role' => 'primary', 'sort_order' => 0,
            'checksum' => hash('sha256', $bytes), 'is_primary' => true, 'verification_status' => 'verified']);

        return $draft;
    }
}
