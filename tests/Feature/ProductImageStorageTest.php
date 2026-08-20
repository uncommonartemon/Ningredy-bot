<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourceStat;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ConfirmedProductGalleryVerifier;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Products\ProductPhotoManager;
use App\Services\Products\WikimediaImageSearch;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_up_to_five_local_images_for_a_laptop_and_only_deletes_them_with_the_product(): void
    {
        Storage::fake('public');
        ProductImageVisionAgent::fake(function (string $prompt, $attachments): array {
            $this->assertStringContainsString('Lenovo Test', $prompt);
            $this->assertMatchesRegularExpression(
                '/#1 source: https:\/\/93\.184\.216\.34\/image-[1-5]\.jpg/',
                $prompt,
            );
            $this->assertLessThanOrEqual(4, $attachments->count());

            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => $index === 0 ? 'product' : 'detail',
                    'view' => $index === 0 ? 'front' : 'detail',
                    'gallery_rank' => $index + 1,
                    'score' => 95 - $index,
                    'reason' => 'Exact clean product image.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        Http::fake(function (Request $request) {
            preg_match('/image-(\d+)/', $request->url(), $matches);

            return Http::response(
                $this->jpeg((int) ($matches[1] ?? 1)),
                200,
                ['Content-Type' => 'image/jpeg'],
            );
        });
        [$product, $variant, $draft] = $this->records();

        app(ProductImageStorage::class)->store($product, $variant, $draft);

        $media = $product->media()->orderBy('sort_order')->get();
        $this->assertNotEmpty($media);
        $this->assertCount(5, $media);
        $this->assertTrue($media->first()->is_primary);
        $this->assertSame('primary', $media->first()->role);
        $this->assertSame('verified', $media->first()->verification_status);
        $this->assertSame('gpt-5.4-mini', $media->first()->verification_model);
        $this->assertNotNull($media->first()->verified_at);

        foreach ($media as $image) {
            Storage::disk('public')->assertExists($image->path);
            $this->assertSame('image/webp', $image->mime_type);
        }

        $paths = $media->pluck('path')->all();
        $product->update(['is_active' => false]);

        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $product->delete();

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_it_does_not_publish_images_rejected_by_vision(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(function (string $prompt, $attachments): array {
            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => false,
                    'publishable' => false,
                    'color_match' => true,
                    'kind' => 'logo',
                    'view' => 'other',
                    'gallery_rank' => $index + 1,
                    'score' => 10,
                    'reason' => 'Brand logo, not the physical product.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(1),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();

        app(ProductImageStorage::class)->store($product, $variant, $draft);

        $this->assertSame(0, $product->media()->count());
        Storage::disk('public')->assertDirectoryEmpty("products/{$product->id}");
    }

    public function test_components_can_keep_all_available_images_up_to_the_global_ten_limit(): void
    {
        Storage::fake('public');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => true,
                'kind' => $index === 0 ? 'product' : 'detail',
                'view' => $index === 0 ? 'front' : 'detail',
                'gallery_rank' => $index + 1,
                'score' => 95 - $index,
                'reason' => 'Exact clean component image.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            preg_match('/image-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, [
                'Content-Type' => 'image/jpeg',
            ]);
        });
        [$product, $variant, $draft] = $this->records();
        $product->update([
            'category_id' => Category::query()->where('slug', 'components')->value('id'),
            'product_type' => 'component',
        ]);

        app(ProductImageStorage::class)->store($product->fresh(), $variant, $draft);

        $this->assertSame(5, $product->media()->count());
    }

    public function test_it_requires_vision_even_for_an_exact_retailer_source(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 94,
                'reason' => 'Exact product and a clean publishable image.',
            ]],
        ])->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(77),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'ASUS',
            'model' => 'X1504VA-BQ4485',
            'sources' => [[
                'title' => 'Retailer exact ASUS Vivobook 15 X1504VA-BQ4485',
                'url' => 'https://93.184.216.34/products/asus-vivobook-15-x1504va-bq4485',
                'type' => 'retailer',
            ]],
            'image_urls' => ['https://93.184.216.34/images/asus-vivobook-15-x1504va-bq4485-quiet-blue-front.jpg'],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertDatabaseHas('product_media', [
            'product_id' => $product->id,
            'source_url' => 'https://93.184.216.34/images/asus-vivobook-15-x1504va-bq4485-quiet-blue-front.jpg',
            'verification_status' => 'verified',
        ]);
    }

    public function test_it_does_not_filter_candidates_by_source_type(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [
                [
                    'index' => 1,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => 'product',
                    'view' => 'front',
                    'gallery_rank' => 1,
                    'score' => 95,
                    'reason' => 'Clean front view.',
                ],
                [
                    'index' => 2,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => 'product',
                    'view' => 'side',
                    'gallery_rank' => 2,
                    'score' => 91,
                    'reason' => 'Clean side view.',
                ],
            ],
        ])->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(str_contains($request->url(), 'amazon') ? 90 : 91),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'ASUS',
            'model' => 'X1504VA-BQ4485',
            'sources' => [
                ['title' => 'Manufacturer listing', 'url' => 'https://www.lenovo.com/product/x1504va-bq4485', 'type' => 'manufacturer'],
                ['title' => 'Amazon listing', 'url' => 'https://www.amazon.com/dp/x1504va-bq4485', 'type' => 'marketplace'],
            ],
            'image_urls' => [
                'https://www.lenovo.com/images/x1504va-bq4485-front.jpg',
                'https://m.media-amazon.com/images/x1504va-bq4485-side.jpg',
            ],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $sources = $product->media()->pluck('source_url')->all();
        $this->assertContains('https://www.lenovo.com/images/x1504va-bq4485-front.jpg', $sources);
        $this->assertContains('https://m.media-amazon.com/images/x1504va-bq4485-side.jpg', $sources);
    }

    public function test_it_accepts_a_publishable_image_when_the_exact_model_is_supported_by_its_source(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => false,
                'publishable' => true,
                'color_match' => true,
                'kind' => 'packaging',
                'view' => 'packaging',
                'gallery_rank' => 1,
                'score' => 66,
                'reason' => 'The exact suffix is not visible on the front of the box.',
            ]],
        ])->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(8),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'title' => 'Intel Core i7-14700 Processor',
            'brand' => 'Intel',
            'model' => 'Core i7-14700',
            'image_urls' => ['https://93.184.216.34/intel-core-i7-14700.jpg'],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertDatabaseHas('product_media', [
            'product_id' => $product->id,
            'source_url' => 'https://93.184.216.34/intel-core-i7-14700.jpg',
            'verification_status' => 'verified',
        ]);
        $this->assertStringContainsString(
            'Exact identity is supported by the source URL.',
            (string) $product->media()->first()->verification_notes,
        );
    }

    public function test_it_rejects_an_official_product_image_when_vision_rejects_it(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => false,
                'publishable' => false,
                'color_match' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 4,
                'reason' => 'Official product render, but the exact regional SKU is not visible.',
            ]],
        ])->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(55),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'ASUS',
            'model' => 'X1504VA-BQ4485',
            'sources' => [[
                'title' => 'ASUS product page',
                'url' => 'https://www.asus.com/laptops/for-home/vivobook/asus-vivobook-15-x1504/',
                'type' => 'manufacturer',
            ]],
            'image_urls' => ['https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w800'],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertDatabaseMissing('product_media', [
            'product_id' => $product->id,
        ]);
    }

    public function test_it_keeps_candidate_order_when_no_extraction_history_exists(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(function (string $prompt, $attachments): array {
            $this->assertStringContainsString(
                '#1 source: https://example.com/catalog/image.jpg',
                $prompt,
            );
            $this->assertStringContainsString(
                '#2 source: https://www.lenovo.com/catalog/hero.jpg',
                $prompt,
            );

            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => $index === 0,
                    'publishable' => $index === 0,
                    'color_match' => true,
                    'kind' => $index === 0 ? 'product' : 'unrelated',
                    'view' => $index === 0 ? 'front' : 'other',
                    'gallery_rank' => $index + 1,
                    'score' => $index === 0 ? 95 : 5,
                    'reason' => $index === 0 ? 'Physical product is visually consistent.' : 'Unrelated image.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(str_contains($request->url(), 'lenovo.com') ? 11 : 12),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'Lenovo',
            'model' => 'Example 9000',
            'sources' => [[
                'title' => 'Lenovo product page',
                'url' => 'https://www.lenovo.com/product/example-9000',
                'type' => 'manufacturer',
            ]],
            'image_urls' => [
                'https://example.com/catalog/image.jpg',
                'https://www.lenovo.com/catalog/hero.jpg',
            ],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $media = $product->media()->firstOrFail();
        $this->assertSame('https://example.com/catalog/image.jpg', $media->source_url);
        $this->assertStringContainsString('Physical product is visually consistent.', (string) $media->verification_notes);
    }

    public function test_it_prefers_a_front_hero_shot_over_an_official_back_panel_as_primary(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [
                [
                    'index' => 1,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => 'detail',
                    'view' => 'back',
                    'gallery_rank' => 2,
                    'score' => 90,
                    'reason' => 'Rear panel with labeled ports.',
                ],
                [
                    'index' => 2,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => 'product',
                    'view' => 'front',
                    'gallery_rank' => 1,
                    'score' => 85,
                    'reason' => 'Clean front hero shot.',
                ],
            ],
        ])->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(str_contains($request->url(), 'lenovo.com') ? 31 : 32),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'Lenovo',
            'model' => 'Example 9000',
            'sources' => [[
                'title' => 'Lenovo product page',
                'url' => 'https://www.lenovo.com/product/example-9000',
                'type' => 'manufacturer',
            ]],
            'image_urls' => [
                'https://www.lenovo.com/catalog/back-panel.jpg',
                'https://93.184.216.34/front-hero.jpg',
            ],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $media = $product->media()->orderBy('sort_order')->get();
        $this->assertCount(2, $media);
        $this->assertSame('https://93.184.216.34/front-hero.jpg', $media->first()->source_url);
        $this->assertTrue((bool) $media->first()->is_primary);
        $this->assertSame('primary', $media->first()->role);
        $this->assertSame('https://www.lenovo.com/catalog/back-panel.jpg', $media->last()->source_url);
    }

    public function test_it_trusts_the_models_gallery_rank_when_model_ranking_is_enabled(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        config()->set('product-images.ranking', 'model');
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [
                [
                    'index' => 1,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => 'detail',
                    'view' => 'back',
                    'gallery_rank' => 1,
                    'score' => 90,
                    'reason' => 'Rear panel the model wants first.',
                ],
                [
                    'index' => 2,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => 'product',
                    'view' => 'front',
                    'gallery_rank' => 2,
                    'score' => 85,
                    'reason' => 'Front shot ranked second by the model.',
                ],
            ],
        ])->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(str_contains($request->url(), 'lenovo.com') ? 41 : 42),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'Lenovo',
            'model' => 'Example 9000',
            'sources' => [[
                'title' => 'Lenovo product page',
                'url' => 'https://www.lenovo.com/product/example-9000',
                'type' => 'manufacturer',
            ]],
            'image_urls' => [
                'https://www.lenovo.com/catalog/back-panel.jpg',
                'https://93.184.216.34/front-hero.jpg',
            ],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $media = $product->media()->orderBy('sort_order')->get();
        $this->assertCount(2, $media);
        $this->assertSame('https://www.lenovo.com/catalog/back-panel.jpg', $media->first()->source_url);
        $this->assertTrue((bool) $media->first()->is_primary);
    }

    public function test_it_checks_a_second_vision_batch_when_the_first_batch_is_rejected(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        $calls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$calls): array {
            $calls++;

            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => $calls === 2 && $index === 0,
                    'publishable' => $calls === 2 && $index === 0,
                    'color_match' => true,
                    'kind' => $calls === 2 && $index === 0 ? 'product' : 'logo',
                    'view' => $calls === 2 && $index === 0 ? 'front' : 'other',
                    'gallery_rank' => $index + 1,
                    'score' => $calls === 2 && $index === 0 ? 92 : 5,
                    'reason' => $calls === 2 && $index === 0 ? 'Exact product.' : 'Not a product photo.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg((int) preg_replace('/\D/', '', $request->url()) ?: 1),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'image_urls' => collect(range(1, 6))
                ->map(fn (int $index): string => "https://93.184.216.34/candidate-{$index}.jpg")
                ->all(),
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertSame(2, $calls);
        $this->assertSame(1, $product->media()->count());
    }

    public function test_loose_candidates_are_all_checked_when_the_source_was_not_confirmed_exact(): void
    {
        Storage::fake('public');
        $visionCalls = 0;
        ProductImageVisionAgent::fake(function () use (&$visionCalls): array {
            $visionCalls++;

            return [
                'images' => [[
                    'index' => 1,
                    'exact_match' => true,
                    'color_match' => true,
                    'publishable' => true,
                    'kind' => 'product',
                    'view' => 'front',
                    'gallery_rank' => 1,
                    'score' => 95,
                    'reason' => 'Exact product image.',
                ]],
            ];
        })->preventStrayPrompts();
        config()->set('product-images.vision_candidates', 1);
        config()->set('product-images.vision_max_batches', 5);
        [, , $draft] = $this->records();
        $draft->refresh();
        $candidates = collect(range(1, 3))->map(fn (int $index): array => [
            'bytes' => $this->jpeg($index),
            'source_url' => "https://93.184.216.34/gallery-{$index}.jpg",
            'mime_type' => 'image/jpeg',
            'width' => 720,
            'height' => 600,
            'confirmed_gallery' => true,
            'partial_gallery' => false,
            'image' => imagecreatefromstring($this->jpeg($index)),
        ])->all();

        $method = new \ReflectionMethod(ProductImageStorage::class, 'verify');
        $method->setAccessible(true);
        $selected = $method->invoke(app(ProductImageStorage::class), $draft, $candidates, 3, null);

        $this->assertCount(3, $selected);
        $this->assertSame(3, $visionCalls);
    }

    public function test_it_fails_closed_when_vision_is_unavailable(): void
    {
        Storage::fake('public');
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Vision unavailable'))->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(2),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();

        try {
            app(ProductImageStorage::class)->store($product, $variant, $draft);
            $this->fail('Vision failure should be retried by the image job.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Vision unavailable', $exception->getMessage());
        }

        $this->assertSame(0, $product->media()->count());
        $this->assertDatabaseHas('ai_runs', [
            'telegram_update_id' => $draft->telegram_update_id,
            'model' => 'gpt-5.4-mini',
            'status' => 'failed',
        ]);
    }

    public function test_it_uses_discovery_to_fill_gallery_after_one_initial_image_is_accepted(): void
    {
        Storage::fake('public');
        $visionCalls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$visionCalls): array {
            $visionCalls++;

            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => true,
                    'publishable' => true,
                    'color_match' => true,
                    'kind' => $index === 0 ? 'product' : 'detail',
                    'view' => $index === 0 ? 'front' : 'detail',
                    'gallery_rank' => $index + 1,
                    'score' => 96 - $index,
                    'reason' => 'Exact publishable product view.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('hasTerminalFailure')->andReturn(false)->byDefault();
        $discovery->shouldReceive('sourcePageForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('find')->once()->andReturn([
            'https://93.184.216.34/discovered-front.jpg',
            'https://93.184.216.34/discovered-back.jpg',
        ]);
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(match (true) {
                str_contains($request->url(), 'front') => 21,
                str_contains($request->url(), 'back') => 22,
                default => 20,
            }),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update(['image_urls' => ['https://93.184.216.34/initial-product.jpg']]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertSame(2, $visionCalls);
        $this->assertSame(3, $product->media()->count());
        $this->assertEqualsCanonicalizing([
            'https://93.184.216.34/initial-product.jpg',
            'https://93.184.216.34/discovered-front.jpg',
            'https://93.184.216.34/discovered-back.jpg',
        ], $product->media()->pluck('source_url')->all());
    }

    public function test_it_upgrades_a_numeric_wxh_rendition_segment_on_any_domain_before_downloading(): void
    {
        // Generic by construction (mirrors scripts/product-gallery-
        // utils.mjs normalizeImageCandidate()): no hostname check, no
        // site-specific magic number - only bumps a number already present
        // in a "WxH" path segment, whatever word (if any) prefixes it.
        $this->assertSame(
            'https://static.bhphoto.com/images/multiple_images/images1600x1600/1767181436_IMG_2646502.jpg',
            ProductImageStorage::normalizeCandidateUrl(
                'https://static.bhphoto.com/images/multiple_images/images500x500/1767181436_IMG_2646502.jpg',
            ),
        );
        $this->assertSame(
            'https://static.bhphoto.com/images/images1600x1600/1767181363_1932364.jpg',
            ProductImageStorage::normalizeCandidateUrl(
                'https://static.bhphoto.com/images/images750x750/1767181363_1932364.jpg',
            ),
        );
        $this->assertSame(
            'https://example-other-cdn.test/media/1600x1600/product.jpg',
            ProductImageStorage::normalizeCandidateUrl(
                'https://example-other-cdn.test/media/240x240/product.jpg',
            ),
        );
        // A word-only marker ("thumbnails") has no universal larger-size
        // spelling to guess across CDNs - left as observed rather than
        // inventing one.
        $this->assertSame(
            'https://static.bhphoto.com/images/multiple_images/thumbnails/1767181436_IMG_2646502.jpg',
            ProductImageStorage::normalizeCandidateUrl(
                'https://static.bhphoto.com/images/multiple_images/thumbnails/1767181436_IMG_2646502.jpg',
            ),
        );
    }

    public function test_it_upgrades_asus_cdn_thumbnails_before_downloading(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 80,
                'reason' => 'Official product family image.',
            ]],
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            $this->assertStringEndsWith('//w800', $request->url());

            return Http::response($this->jpeg(44), 200, ['Content-Type' => 'image/jpeg']);
        });
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'ASUS',
            'sources' => [[
                'title' => 'ASUS product page',
                'url' => 'https://www.asus.com/laptops/for-home/vivobook/asus-vivobook-15-x1504/',
                'type' => 'manufacturer',
            ]],
            'image_urls' => ['https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w48'],
        ]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertDatabaseHas('product_media', [
            'product_id' => $product->id,
            'source_url' => 'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w800',
            'verification_status' => 'verified',
        ]);
    }

    public function test_download_candidates_falls_back_to_the_observed_asus_rendition_when_w800_404s(): void
    {
        // The guessed //w800 upgrade is not guaranteed to exist for every
        // ASUS product either - same caveat as the generic WxH case.
        $observed = 'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w48';
        $guessed = 'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w800';
        Http::fake([
            $guessed => Http::response('Not Found', 404),
            $observed => Http::response($this->jpeg(21), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        [, , $draft] = $this->records();

        $downloadCandidates = new \ReflectionMethod(ProductImageStorage::class, 'downloadCandidates');
        $downloadCandidates->setAccessible(true);
        $candidates = $downloadCandidates->invoke(app(ProductImageStorage::class), [$observed], $draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($observed, $candidates[0]['source_url']);
        imagedestroy($candidates[0]['image']);
    }

    public function test_it_uses_fallback_discovery_when_research_only_returns_logos(): void
    {
        Storage::fake('public');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 96,
                'reason' => 'Exact physical product.',
            ]],
        ])->preventStrayPrompts();
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('hasTerminalFailure')->andReturn(false)->byDefault();
        $discovery->shouldReceive('sourcePageForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('find')->once()->andReturn(['https://93.184.216.34/exact-product.jpg']);
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(4),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update(['image_urls' => ['https://93.184.216.34/intel-logo.png']]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertDatabaseHas('product_media', [
            'product_id' => $product->id,
            'verification_status' => 'verified',
            'source_url' => 'https://93.184.216.34/exact-product.jpg',
        ]);
        $this->assertContains('https://93.184.216.34/exact-product.jpg', $draft->fresh()->image_urls);
    }

    public function test_replacing_one_draft_photo_falls_back_to_ai_web_search_when_the_source_page_has_nothing_new(): void
    {
        // Real gap (2026-08-05): replaceDraftMedia()'s only fallback after a
        // failed static scrape of the source page used to be re-scraping that
        // exact same page again (same method, same URL) - which almost never
        // finds anything new. This verifies it now reaches for the AI
        // web-search-by-model discovery instead, the same one used for full
        // gallery discovery/top-up elsewhere in this class.
        Storage::fake('public');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 96,
                'reason' => 'Exact physical product.',
            ]],
        ])->preventStrayPrompts();
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('hasTerminalFailure')->andReturn(false)->byDefault();
        $discovery->shouldReceive('sourcePageForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('find')->once()->andReturn(['https://93.184.216.34/found-by-websearch.jpg']);
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg(9),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [, , $draft] = $this->records();
        $draft->refresh();
        $draft->update([
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'sources' => [[
                'title' => 'Exact retailer listing',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
            ]],
        ]);
        $oldPath = "drafts/{$draft->id}/old-photo.webp";
        Storage::disk('public')->put($oldPath, 'old-photo-bytes');
        $media = $draft->media()->create([
            'disk' => 'public',
            'path' => $oldPath,
            'source_url' => 'https://93.184.216.34/old-photo.jpg',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'checksum' => hash('sha256', 'old-photo-bytes'),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $updated = app(ProductImageStorage::class)->replaceDraftMedia($draft->fresh(), $media);

        $this->assertSame('https://93.184.216.34/found-by-websearch.jpg', $updated->source_url);
        $this->assertSame('verified', $updated->verification_status);
    }

    public function test_it_drops_near_duplicate_images_selected_by_vision(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => true,
                'kind' => $index === 0 ? 'product' : 'detail',
                'view' => $index === 0 ? 'front' : 'detail',
                'gallery_rank' => $index + 1,
                'score' => 95 - $index,
                'reason' => 'Exact clean product image.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            str_contains($request->url(), 'copy') ? $this->jpegCopy(7) : $this->jpeg(7),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [$product, $variant, $draft] = $this->records();
        $draft->update(['image_urls' => [
            'https://93.184.216.34/original.jpg',
            'https://93.184.216.34/copy.jpg',
        ]]);

        app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $media = $product->media()->get();
        $this->assertCount(1, $media);
        $this->assertSame('https://93.184.216.34/original.jpg', $media->first()->source_url);
    }

    public function test_refind_keeps_current_photos_until_replacements_are_found(): void
    {
        Queue::fake();
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'approved_product_id' => $product->id,
            'approved_variant_id' => $variant->id,
        ]);
        $old = $product->media()->create([
            'product_variant_id' => $variant->id,
            'type' => 'image',
            'url' => 'https://example.com/current.jpg',
            'source_url' => 'https://example.com/current.jpg',
            'verification_status' => 'verified',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $started = app(ProductPhotoManager::class)->refind($product, fresh: true);

        $this->assertTrue($started);
        $this->assertDatabaseHas('product_media', ['id' => $old->id]);
        Queue::assertPushed(StoreProductImages::class, fn (StoreProductImages $job): bool => $job->productId === $product->id && $job->replaceMediaIds === [$old->id]);
    }

    public function test_refind_rejects_a_previous_photo_even_when_bytes_and_url_change(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 95,
                'reason' => 'Exact product image.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(fn () => Http::response($this->jpegCopy(7), 200, ['Content-Type' => 'image/jpeg']));
        [$product, $variant, $draft] = $this->records();
        $path = "products/{$product->id}/old-photo.webp";
        Storage::disk('public')->put($path, $this->jpeg(7));
        $old = $product->media()->create([
            'product_variant_id' => $variant->id,
            'type' => 'image',
            'disk' => 'public',
            'path' => $path,
            'source_url' => 'https://93.184.216.34/old-photo.jpg',
            'verification_status' => 'verified',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $draft->update(['image_urls' => ['https://93.184.216.34/same-photo-new-url.jpg']]);

        $stored = app(ProductImageStorage::class)->store($product, $variant, $draft->fresh(), [$old->id]);

        $this->assertSame(0, $stored);
        $this->assertDatabaseHas('product_media', ['id' => $old->id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_an_explicit_color_requires_vision_and_rejects_a_visible_mismatch(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(function (string $prompt): array {
            $this->assertStringContainsString('Required color/version: Black', $prompt);

            return ['images' => [[
                'index' => 1,
                'exact_match' => true,
                'publishable' => true,
                'color_match' => false,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 96,
                'reason' => 'Exact model, but the visible chassis is white.',
            ]]];
        })->preventStrayPrompts();
        Http::fake(fn () => Http::response($this->jpeg(88), 200, ['Content-Type' => 'image/jpeg']));
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'brand' => 'MSI',
            'model' => 'C2NVR9-1452US',
            'color' => 'Black',
            'sources' => [[
                'title' => 'Exact MSI retailer listing',
                'url' => 'https://93.184.216.34/products/C2NVR9-1452US',
                'type' => 'retailer',
            ]],
            'image_urls' => ['https://93.184.216.34/images/C2NVR9-1452US-white.jpg'],
        ]);

        $stored = app(ProductImageStorage::class)->store($product, $variant, $draft->fresh());

        $this->assertSame(0, $stored);
        $this->assertSame(0, $product->media()->count());
    }

    public function test_it_stages_one_verified_gallery_before_approval_and_adopts_it_without_searching_again(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        ProductImageVisionAgent::fake(function (string $prompt, $attachments): array {
            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => true,
                    'color_match' => true,
                    'publishable' => true,
                    'kind' => 'product',
                    'view' => $index === 0 ? 'front' : 'angle',
                    'gallery_rank' => $index + 1,
                    'score' => 96 - $index,
                    'reason' => 'Exact product and selected color.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/product-page')) {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            preg_match('/image-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, ['Content-Type' => 'image/jpeg']);
        });
        [$product, $variant, $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'brand' => 'Lenovo',
            'model' => 'Test',
            'color' => 'Black',
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'sources' => [[
                'title' => 'Exact retailer listing',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
            ]],
        ]);

        $storage = app(ProductImageStorage::class);
        $staged = $storage->stage($draft->fresh());

        $this->assertSame(3, $staged);
        $this->assertSame(0, $product->media()->count());
        $this->assertSame(3, $draft->media()->count());
        $this->assertNotNull($draft->fresh()->images_staged_at);
        $stagedIds = $draft->media()->pluck('id')->all();
        $stagedPaths = $draft->media()->pluck('path')->all();

        $restaged = $storage->stage($draft->fresh());

        $this->assertSame(3, $restaged);
        $this->assertSame($stagedIds, $draft->media()->pluck('id')->all());
        $this->assertSame($stagedPaths, $draft->media()->pluck('path')->all());
        foreach ($stagedPaths as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $adopted = $storage->adoptStaged($product, $variant, $draft);

        $this->assertSame(3, $adopted);
        $this->assertSame(3, $product->media()->count());
        $this->assertSame(0, $draft->media()->count());
        foreach ($stagedPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        foreach ($product->media as $media) {
            Storage::disk('public')->assertExists($media->path);
        }
    }

    public function test_restaging_with_a_fresh_update_id_is_not_blocked_by_the_original_searchs_expired_budget(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => $index === 0 ? 'front' : 'angle',
                'gallery_rank' => $index + 1,
                'score' => 96 - $index,
                'reason' => 'Exact product and selected color.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/product-page')) {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            preg_match('/image-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'brand' => 'Lenovo',
            'model' => 'Test',
            'color' => 'Black',
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'sources' => [[
                'title' => 'Exact retailer listing',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
            ]],
        ]);
        // Simulate a "find other photos" button pressed long after the
        // original search's own time budget (searchMaxSeconds, 1200s by
        // default) has elapsed - a realistic gap for a manual operator retry.
        AiRun::query()->where('telegram_update_id', $draft->telegram_update_id)
            ->update(['started_at' => now()->subSeconds(2000)]);

        $storage = app(ProductImageStorage::class);

        $staleAttempt = $storage->stage($draft->fresh());
        $this->assertSame(0, $staleAttempt, 'Reusing the draft\'s original (expired) update id must not silently find nothing.');

        $freshUpdate = TelegramUpdate::query()->create([
            'update_id' => 99002,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'completed',
        ]);
        $retried = $storage->stage($draft->fresh(), telegramUpdateId: $freshUpdate->id);

        $this->assertSame(3, $retried, 'A fresh update id must get its own full time budget instead of inheriting the expired one.');
    }

    public function test_staging_skips_a_source_without_photos_and_uses_the_next_complete_card_source(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 98,
                'reason' => 'Exact product and selected color.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'without-gallery')) {
                return Http::response('<html><body>No product photos</body></html>', 200, ['Content-Type' => 'text/html']);
            }

            if ($request->url() === 'https://93.184.216.34/complete-card') {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response($this->jpeg(2), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'brand' => 'Lenovo',
            'model' => 'Test',
            'color' => 'Black',
            'primary_source_url' => 'https://93.184.216.34/without-gallery',
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'First store without photos',
                    'url' => 'https://93.184.216.34/without-gallery',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
                [
                    'title' => 'Second complete product card',
                    'url' => 'https://93.184.216.34/complete-card',
                    'type' => 'retailer',
                    'image_urls' => ['https://93.184.216.34/complete-card-photo.jpg'],
                ],
            ],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(1, $stored);
        $this->assertSame('https://93.184.216.34/complete-card', $draft->fresh()->primary_source_url);
        $this->assertSame('partial', $draft->fresh()->gallery_status);
        $this->assertNotNull($draft->fresh()->gallery_notes);
        $this->assertSame(
            'https://93.184.216.34/complete-card-photo.jpg',
            $draft->media()->firstOrFail()->source_url,
        );
    }

    public function test_staging_never_opens_a_foreign_card_when_an_exact_sku_was_requested(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.memory', 3);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 98,
                'reason' => 'Exact requested memory module.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://93.184.216.35/products/3r2d42r4256s') {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            preg_match('/photo-(\d+)/', $request->url(), $match);

            return Http::response($this->jpeg((int) ($match[1] ?? 1)), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'memory',
            'title' => 'OWC 256GB DDR4-3200 RDIMM 3R2D42R4256S',
            'brand' => 'OWC',
            'model' => '3R2D42R4256S',
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'Kingston 256GB DDR4 memory',
                    'url' => 'https://93.184.216.34/products/kingston-256gb',
                    'type' => 'retailer',
                    'image_urls' => ['https://93.184.216.34/foreign-photo.jpg'],
                ],
                [
                    'title' => 'OWC 3R2D42R4256S',
                    'url' => 'https://93.184.216.35/products/3r2d42r4256s',
                    'type' => 'retailer',
                    'image_urls' => [
                        'https://93.184.216.35/photo-1.jpg',
                        'https://93.184.216.35/photo-2.jpg',
                        'https://93.184.216.35/photo-3.jpg',
                    ],
                ],
            ],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(3, $stored);
        $this->assertSame('https://93.184.216.35/products/3r2d42r4256s', $draft->fresh()->primary_source_url);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '93.184.216.34'));
    }

    public function test_cost_limit_is_checked_after_current_source_finishes_before_next_source_starts(): void
    {
        Storage::fake('public');
        ProductImageVisionAgent::fake()->preventStrayPrompts();
        $crossed = false;
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('exceeded')->andReturnUsing(function () use (&$crossed): bool {
            return $crossed;
        });
        $costBudget->shouldReceive('limit')->andReturn(0.50);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->once()->andReturnUsing(function () use (&$crossed): array {
            $crossed = true;

            return [];
        });
        $browser->shouldReceive('isConfirmedGalleryImage')->andReturn(false);
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        Http::fake(fn (Request $request) => Http::response(
            '<html><body>No static photos</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ));
        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'primary_source_url' => 'https://93.184.216.34/first-card',
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'First card',
                    'url' => 'https://93.184.216.34/first-card',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
                [
                    'title' => 'Second card',
                    'url' => 'https://93.184.216.35/second-card',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
            ],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(0, $stored);
        $this->assertSame('cost_budget', $draft->fresh()->gallery_search_stop_reason);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_url' => 'https://93.184.216.34/first-card',
            'phase' => 'source_resolution',
        ]);
        $this->assertDatabaseMissing('product_source_attempts', [
            'product_url' => 'https://93.184.216.35/second-card',
            'phase' => 'source_resolution',
        ]);
    }

    public function test_staging_accepts_ten_unique_photos_from_an_exact_playwright_slider_without_vision(): void
    {
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        config()->set('product-images.max_images_by_type.laptop', 10);
        ProductImageVisionAgent::fake()->preventStrayPrompts();
        $gallery = collect(range(1, 10))
            ->map(fn (int $index): string => 'https://93.184.216.34/gallery-'.$index.'.jpg')
            ->all();
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->once()->andReturn($gallery);
        $browser->shouldReceive('isConfirmedGalleryImage')
            ->andReturnUsing(fn (string $url): bool => str_contains($url, '/gallery-'));
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        // Vision's own spot check on a never-before-seen recipe is a
        // different mechanism (see the dedicated spot-check test below);
        // this recipe is pre-verified so this test can keep proving the
        // downstream promise - an already-vetted confirmed slider is
        // accepted with no Vision call at all.
        ProductGalleryRecipe::query()->create([
            'domain' => '93.184.216.34',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => [
                'gallery_verification_mode' => 'dedicated',
                'content_verified_by_vision' => true,
            ],
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/products/g533qs-ds76')) {
                return Http::response('<html><body><div class="slider"></div></body></html>', 200, [
                    'Content-Type' => 'text/html',
                ]);
            }

            preg_match('/gallery-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, [
                'Content-Type' => 'image/jpeg',
            ]);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'title' => 'ASUS ROG Strix Scar 15 G533QS-DS76',
            'brand' => 'ASUS',
            'model' => 'G533QS-DS76',
            'product_type' => 'laptop',
            'primary_source_url' => 'https://93.184.216.34/products/g533qs-ds76',
            'image_urls' => [],
            'sources' => [[
                'title' => 'ASUS ROG Strix Scar 15 G533QS-DS76',
                'url' => 'https://93.184.216.34/products/g533qs-ds76',
                'type' => 'retailer',
                'image_urls' => [],
            ]],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());
        $fresh = $draft->fresh();

        $this->assertSame(10, $stored);
        $this->assertSame('complete', $fresh->gallery_status);
        $this->assertSame($gallery, $fresh->image_urls);
        $this->assertCount(10, $fresh->media);
        $this->assertTrue($fresh->media->every(
            fn ($media): bool => $media->verification_status === 'source_verified'
                && $media->verification_model === null,
        ));
    }

    public function test_confirmed_slider_gets_exactly_one_vision_spot_check_the_first_time_and_it_is_remembered(): void
    {
        // A fully confirmed, identity-matched slider can still show
        // something other than the product itself (e.g. a manufacturing/
        // materials-story carousel using the same slider markup), and the AI
        // trainer's own claim that the content is confirmed isn't trusted
        // blindly - Vision spot-checks exactly one frame the first time this
        // recipe's confirmed path is used, then remembers the verdict on the
        // recipe so later searches against the same domain don't pay for it
        // again.
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        config()->set('product-images.max_images_by_type.laptop', 10);
        $visionCalls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$visionCalls): array {
            $visionCalls++;

            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => true,
                    'color_match' => true,
                    'publishable' => true,
                    'kind' => 'product',
                    'view' => 'front',
                    'gallery_rank' => 1,
                    'score' => 95,
                    'reason' => 'Genuine product photo.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        $gallery = collect(range(1, 5))
            ->map(fn (int $index): string => 'https://93.184.216.36/gallery-'.$index.'.jpg')
            ->all();
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->once()->andReturn($gallery);
        $browser->shouldReceive('isConfirmedGalleryImage')
            ->andReturnUsing(fn (string $url): bool => str_contains($url, '/gallery-'));
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => '93.184.216.36',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['content_confirmed_product' => true],
        ]);
        $recipe->versions()->create([
            'domain' => '93.184.216.36',
            'product_url' => 'https://93.184.216.36/products/g533qs-ds76',
            'trigger' => 'test',
            'status' => 'active',
            'provider' => 'fake',
            'model' => 'fake',
            'result' => [
                'action_trace' => [[
                    'phase' => 'open_expanded_gallery',
                    'clicked' => true,
                    'expanded_gallery_visible_after' => true,
                ]],
            ],
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/products/g533qs-ds76')) {
                return Http::response('<html><body><div class="slider"></div></body></html>', 200, [
                    'Content-Type' => 'text/html',
                ]);
            }

            preg_match('/gallery-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, [
                'Content-Type' => 'image/jpeg',
            ]);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'title' => 'ASUS ROG Strix Scar 15 G533QS-DS76',
            'brand' => 'ASUS',
            'model' => 'G533QS-DS76',
            'product_type' => 'laptop',
            'primary_source_url' => 'https://93.184.216.36/products/g533qs-ds76',
            'image_urls' => [],
            'sources' => [[
                'title' => 'ASUS ROG Strix Scar 15 G533QS-DS76',
                'url' => 'https://93.184.216.36/products/g533qs-ds76',
                'type' => 'retailer',
                'image_urls' => [],
            ]],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(5, $stored);
        $this->assertSame(1, $visionCalls);
        $this->assertTrue(
            ProductGalleryRecipe::query()->where('domain', '93.184.216.36')->firstOrFail()
                ->recipe['content_verified_by_vision'],
        );
        $this->assertSame(
            'dedicated',
            ProductGalleryRecipe::query()->where('domain', '93.184.216.36')->firstOrFail()
                ->recipe['gallery_verification_mode'],
        );
    }

    public function test_ambiguous_confirmed_carousel_is_reviewed_as_one_permissive_batch_and_only_keeps_product_frames(): void
    {
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        config()->set('product-images.max_images_by_type.laptop', 10);
        $visionCalls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$visionCalls): array {
            $visionCalls++;
            $this->assertCount(5, $attachments);
            $this->assertStringContainsString('Be deliberately permissive', $prompt);

            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => $index < 3,
                    'color_match' => true,
                    'publishable' => $index < 3,
                    'kind' => $index < 3 ? 'product' : 'screenshot',
                    'view' => $index === 0 ? 'front' : 'detail',
                    'gallery_rank' => $index + 1,
                    'score' => $index < 3 ? 85 : 10,
                    'reason' => $index < 3 ? 'Useful product feature frame.' : 'No useful product is visible.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        $gallery = collect(range(1, 5))
            ->map(fn (int $index): string => 'https://93.184.216.37/feature-'.$index.'.jpg')
            ->all();
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->once()->andReturn($gallery);
        $browser->shouldReceive('isConfirmedGalleryImage')
            ->andReturnUsing(fn (string $url): bool => str_contains($url, '/feature-'));
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => '93.184.216.37',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['content_confirmed_product' => true],
        ]);
        $recipe->versions()->create([
            'domain' => '93.184.216.37',
            'product_url' => 'https://93.184.216.37/products/g533qs-ds76',
            'trigger' => 'test',
            'status' => 'active',
            'provider' => 'fake',
            'model' => 'fake',
            'result' => [
                'diagnostics' => [
                    'observed_gallery_count' => 0,
                    'distinct_dom_assets' => 176,
                    'gallery_goal_reached' => false,
                ],
                'action_trace' => [[
                    'clicked' => true,
                    'expanded_gallery_visible_after' => false,
                ]],
            ],
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/products/g533qs-ds76')) {
                return Http::response('<html><body><div class=feature-slider></div></body></html>', 200, [
                    'Content-Type' => 'text/html',
                ]);
            }

            preg_match('/feature-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, [
                'Content-Type' => 'image/jpeg',
            ]);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'title' => 'ASUS ROG Strix Scar 15 G533QS-DS76',
            'brand' => 'ASUS',
            'model' => 'G533QS-DS76',
            'product_type' => 'laptop',
            'primary_source_url' => 'https://93.184.216.37/products/g533qs-ds76',
            'image_urls' => [],
            'sources' => [[
                'title' => 'ASUS ROG Strix Scar 15 G533QS-DS76',
                'url' => 'https://93.184.216.37/products/g533qs-ds76',
                'type' => 'retailer',
                'image_urls' => [],
            ]],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(3, $stored);
        $this->assertSame(1, $visionCalls);
        $this->assertSame($gallery[0], $draft->fresh()->media()->orderBy('sort_order')->firstOrFail()->source_url);
        $this->assertCount(1, ProductGalleryRecipe::query()
            ->where('domain', '93.184.216.37')
            ->firstOrFail()
            ->recipe['gallery_batch_verifications']);
        $this->assertSame(
            'ambiguous',
            ProductGalleryRecipe::query()->where('domain', '93.184.216.37')->firstOrFail()
                ->recipe['gallery_verification_mode'],
        );
    }

    public function test_a_rejected_dedicated_spot_check_falls_back_to_batch_instead_of_poisoning_the_recipe(): void
    {
        [, , $draft] = $this->records();
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'gallery.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['gallery_verification_mode' => 'dedicated'],
        ]);
        $candidates = collect(range(1, 5))->map(fn (int $index): array => [
            'source_url' => 'https://gallery.example/frame-'.$index.'.jpg',
        ]);
        $vision = $this->mock(ProductImageVisionVerifier::class);
        $vision->shouldReceive('select')->once()->with($draft, [$candidates->first()], 1, null)->andReturn([]);
        $vision->shouldReceive('selectGalleryFrames')->once()->andReturn($candidates->slice(1)->values()->all());

        $result = app(ConfirmedProductGalleryVerifier::class)->verify(
            'https://gallery.example/product/test',
            $candidates,
            $draft,
            3,
            null,
        );

        $this->assertSame('ambiguous', $result['mode']);
        $this->assertCount(4, $result['candidates']);
        $storedRecipe = $recipe->fresh()->recipe;
        $this->assertSame('ambiguous', $storedRecipe['gallery_verification_mode']);
        $this->assertArrayNotHasKey('content_verified_by_vision', $storedRecipe);
    }

    public function test_component_category_uses_vision_first_and_only_allows_active_recipes(): void
    {
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => $index === 0 ? 'front' : 'detail',
                'gallery_rank' => $index + 1,
                'score' => 98 - $index,
                'reason' => 'Exact component image.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'component-card')) {
                return Http::response('<html><body><div class=product-gallery></div></body></html>', 200, [
                    'Content-Type' => 'text/html',
                ]);
            }

            preg_match('/component-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, [
                'Content-Type' => 'image/jpeg',
            ]);
        });
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')
            ->once()
            ->withArgs(fn (...$arguments): bool => end($arguments) === true)
            ->andReturn([
                'https://93.184.216.34/component-1.jpg',
                'https://93.184.216.34/component-2.jpg',
                'https://93.184.216.34/component-3.jpg',
            ]);
        $browser->shouldReceive('isConfirmedGalleryImage')->andReturn(false);
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        [, , $draft] = $this->records();
        $draft->update([
            'category' => 'components',
            'product_type' => 'component',
            'primary_source_url' => 'https://93.184.216.34/component-card',
            'sources' => [[
                'title' => 'Exact component card',
                'url' => 'https://93.184.216.34/component-card',
                'type' => 'retailer',
                'image_urls' => [],
            ]],
            'image_urls' => [],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(3, $stored);
        $this->assertSame('complete', $draft->fresh()->gallery_status);
        $this->assertTrue($draft->fresh()->media->every(
            fn ($media): bool => $media->verification_status === 'verified',
        ));
    }

    public function test_staging_checks_the_next_page_for_a_slider_before_using_vision_on_static_photos(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        ProductImageVisionAgent::fake()->preventStrayPrompts();
        $slider = collect(range(1, 3))
            ->map(fn (int $index): string => 'https://93.184.216.35/slider-'.$index.'.jpg')
            ->all();
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->twice()->andReturnUsing(
            fn (string $url): array => str_contains($url, '93.184.216.35') ? $slider : [],
        );
        $browser->shouldReceive('isConfirmedGalleryImage')
            ->andReturnUsing(fn (string $url): bool => str_contains($url, '/slider-'));
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        // Pre-verified so this test can keep proving its own point (the
        // slider page wins over the earlier static-only card) without also
        // exercising the separate spot-check mechanism.
        ProductGalleryRecipe::query()->create([
            'domain' => '93.184.216.35',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => [
                'gallery_verification_mode' => 'dedicated',
                'content_verified_by_vision' => true,
            ],
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '-card')) {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            preg_match('/(?:static|slider)-(\d+)/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, [
                'Content-Type' => 'image/jpeg',
            ]);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'title' => 'ASUS ROG Strix Scar 15 G533QS-DS76',
            'brand' => 'ASUS',
            'model' => 'G533QS-DS76',
            'product_type' => 'laptop',
            'primary_source_url' => 'https://93.184.216.34/g533qs-ds76-first-card',
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'G533QS-DS76 first static card',
                    'url' => 'https://93.184.216.34/g533qs-ds76-first-card',
                    'type' => 'retailer',
                    'image_urls' => [
                        'https://93.184.216.34/static-1.jpg',
                        'https://93.184.216.34/static-2.jpg',
                    ],
                ],
                [
                    'title' => 'G533QS-DS76 second slider card',
                    'url' => 'https://93.184.216.35/g533qs-ds76-second-card',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
            ],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(3, $stored);
        $this->assertSame(
            'https://93.184.216.35/g533qs-ds76-second-card',
            $draft->fresh()->primary_source_url,
        );
        $this->assertSame($slider, $draft->fresh()->image_urls);
    }

    public function test_staging_finishes_without_crashing_when_deferred_vision_is_unavailable(): void
    {
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        AppSetting::put('ai.gallery_browser_mode', 'off');
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Vision unavailable'))
            ->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '-card')) {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response($this->jpeg(1), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'primary_source_url' => 'https://93.184.216.34/product-card',
            'sources' => [[
                'title' => 'Static product card',
                'url' => 'https://93.184.216.34/product-card',
                'type' => 'retailer',
                'image_urls' => ['https://93.184.216.34/static-photo.jpg'],
            ]],
            'image_urls' => [],
        ]);

        $this->assertSame(0, app(ProductImageStorage::class)->stage($draft->fresh()));
        $this->assertSame('missing', $draft->fresh()->gallery_status);
    }

    public function test_staging_tries_a_second_independent_source_when_first_vision_set_is_rejected(): void
    {
        Storage::fake('public');
        AppSetting::put('ai.gallery_browser_mode', 'off');
        config()->set('product-images.max_images_by_type.laptop', 3);
        config()->set('product-images.fallback_discovery', false);
        $visionCalls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$visionCalls): array {
            $visionCalls++;

            return ['images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => $visionCalls === 2,
                'color_match' => true,
                'publishable' => $visionCalls === 2,
                'kind' => $visionCalls === 2 ? 'product' : 'unrelated',
                'view' => $visionCalls === 2 ? 'angle' : 'other',
                'gallery_rank' => $index + 1,
                'score' => $visionCalls === 2 ? 90 : 5,
                'reason' => $visionCalls === 2 ? 'Exact product.' : 'Wrong product.',
            ])->all()];
        })->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '-card')) {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            preg_match('/(\d+)\.jpg/', $request->url(), $matches);

            return Http::response($this->jpeg((int) ($matches[1] ?? 1)), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'model' => null,
            'primary_source_url' => 'https://93.184.216.34/first-card',
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'First card',
                    'url' => 'https://93.184.216.34/first-card',
                    'type' => 'retailer',
                    'image_urls' => [
                        'https://93.184.216.34/first-1.jpg',
                        'https://93.184.216.34/first-2.jpg',
                        'https://93.184.216.34/first-6.jpg',
                        'https://93.184.216.34/first-7.jpg',
                    ],
                ],
                [
                    'title' => 'Second card',
                    'url' => 'https://93.184.216.35/second-card',
                    'type' => 'retailer',
                    'image_urls' => [
                        'https://93.184.216.35/second-3.jpg',
                        'https://93.184.216.35/second-4.jpg',
                        'https://93.184.216.35/second-5.jpg',
                    ],
                ],
            ],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(2, $visionCalls);
        $this->assertSame(3, $stored);
        $this->assertSame('https://93.184.216.35/second-card', $draft->fresh()->primary_source_url);
    }

    public function test_staging_keeps_the_selected_primary_source_first_until_its_direct_images_are_verified(): void
    {
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        AppSetting::put('ai.gallery_browser_mode', 'off');
        config()->set('product-images.max_images_by_type.laptop', 2);
        ProductSourceStat::query()->create([
            'domain' => '93.184.216.34',
            'attempt_count' => 1,
            'failure_count' => 1,
            'last_failure_kind' => 'http_error',
        ]);
        ProductSourceStat::query()->create([
            'domain' => '93.184.216.35',
            'attempt_count' => 1,
            'accepted_gallery_count' => 1,
            'accepted_image_count' => 2,
        ]);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => $index === 0 ? 'front' : 'angle',
                'gallery_rank' => $index + 1,
                'score' => 98 - $index,
                'reason' => 'Exact primary-source product photo.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '-card')) {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            $seed = str_ends_with($request->url(), 'photo-2.jpg') ? 22 : 21;

            return Http::response($this->jpeg($seed), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'primary_source_url' => 'https://93.184.216.34/primary-card',
            'image_urls' => [
                'https://93.184.216.34/primary-photo-1.jpg',
                'https://93.184.216.34/primary-photo-2.jpg',
            ],
            'sources' => [
                [
                    'title' => 'Selected primary source with a failed page probe',
                    'url' => 'https://93.184.216.34/primary-card',
                    'type' => 'manufacturer',
                    'image_urls' => [
                        'https://93.184.216.34/primary-photo-1.jpg',
                        'https://93.184.216.34/primary-photo-2.jpg',
                    ],
                ],
                [
                    'title' => 'Historically successful secondary source',
                    'url' => 'https://93.184.216.35/secondary-card',
                    'type' => 'retailer',
                    'image_urls' => [
                        'https://93.184.216.35/secondary-photo-1.jpg',
                        'https://93.184.216.35/secondary-photo-2.jpg',
                    ],
                ],
            ],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(2, $stored);
        $this->assertSame('https://93.184.216.34/primary-card', $draft->fresh()->primary_source_url);
        // The secondary source is still downloaded as a candidate set for
        // comparison (trying every known card is unconditional now), but
        // the primary source's own already-verified set wins the tie and is
        // what actually gets stored - a historically "successful" secondary
        // domain must not override the draft's own selected primary source.
        $this->assertTrue($draft->media->every(
            fn ($media): bool => str_contains($media->source_url, '93.184.216.34/primary-photo-'),
        ));
    }

    public function test_staging_runs_a_second_discovery_round_after_a_partial_first_result(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        config()->set('product-images.fallback_search_rounds', 3);
        AppSetting::put('ai.max_search_cost_usd', '0');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 98,
                'reason' => 'Exact product image.',
            ])->all(),
        ])->preventStrayPrompts();
        $round = 0;
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('hasTerminalFailure')->andReturn(false)->byDefault();
        $discovery->shouldReceive('find')->twice()->andReturnUsing(
            function ($draft, array $excludedUrls, bool $skipKnown, $progress, $updateId, array $excludedSources) use (&$round): array {
                $round++;
                if ($round === 2) {
                    $this->assertContains('https://93.184.216.34/model', $excludedSources);
                }

                return $round === 1
                    ? ['https://93.184.216.36/photo-1.jpg']
                    : [
                        'https://93.184.216.37/photo-1.jpg',
                        'https://93.184.216.37/photo-2.jpg',
                        'https://93.184.216.37/photo-3.jpg',
                    ];
            },
        );
        $discovery->shouldReceive('sourcePageForImage')->andReturnUsing(
            fn (string $url): string => str_contains($url, '216.36')
                ? 'https://93.184.216.34/model'
                : 'https://93.184.216.35/model',
        );
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg((int) (preg_match('/photo-(\d+)/', $request->url(), $match) ? $match[1] : 1) + (str_contains($request->url(), '216.37') ? 10 : 0)),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [, , $draft] = $this->records();
        $draft->update(['product_type' => 'laptop', 'sources' => [], 'image_urls' => []]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(2, $round);
        $this->assertSame(3, $stored);
        $this->assertSame('complete', $draft->fresh()->gallery_status);
        $this->assertSame('https://93.184.216.35/model', $draft->fresh()->primary_source_url);
    }

    public function test_staging_continues_after_an_empty_discovery_round(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        config()->set('product-images.fallback_search_rounds', 3);
        AppSetting::put('ai.max_search_cost_usd', '0');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'angle',
                'gallery_rank' => $index + 1,
                'score' => 98,
                'reason' => 'Exact product image.',
            ])->all(),
        ])->preventStrayPrompts();

        $round = 0;
        $urls = [
            'https://93.184.216.38/photo-1.jpg',
            'https://93.184.216.38/photo-2.jpg',
            'https://93.184.216.38/photo-3.jpg',
        ];
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('hasTerminalFailure')->andReturn(false)->byDefault();
        $discovery->shouldReceive('find')->twice()->andReturnUsing(
            function (
                $draft,
                array $excludedUrls,
                bool $skipKnown,
                $progress,
                $updateId,
                array $excludedSources,
                int $searchAttempt,
            ) use (&$round, $urls): array {
                $round++;
                $this->assertSame($round, $searchAttempt);

                return $round === 1 ? [] : $urls;
            },
        );
        $discovery->shouldReceive('sourcePageForImage')
            ->andReturn('https://93.184.216.38/exact-model');
        Http::fake(fn (Request $request) => Http::response(
            $this->jpeg((int) (preg_match('/photo-(\d+)/', $request->url(), $match) ? $match[1] : 1) + 30),
            200,
            ['Content-Type' => 'image/jpeg'],
        ));
        [, , $draft] = $this->records();
        $draft->update(['product_type' => 'laptop', 'sources' => [], 'image_urls' => []]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(2, $round);
        $this->assertSame(3, $stored);
        $this->assertSame('complete', $draft->fresh()->gallery_status);
    }

    public function test_paid_fallback_rechecks_cost_measurability_after_the_first_empty_round(): void
    {
        // A fresh continuation has no usage before its first AI call, so its
        // cost is briefly unmeasurable. That temporary state must not freeze
        // the deterministic test/budgetless round cap for the whole run.
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('limit')->andReturn(1.0)->byDefault();
        $costBudget->shouldReceive('unmeasurable')->once()->andReturn(true);

        $method = new \ReflectionMethod(ProductImageStorage::class, 'fallbackSearchSafetyCapReached');
        $method->setAccessible(true);
        $storage = app(ProductImageStorage::class);

        // Before the first call, unmeasurable is true, but zero completed
        // rounds never reaches a one-round safety cap.
        $this->assertFalse($method->invoke($storage, 0, 1, 99001, false));

        // The next loop iteration must ask the budget again. Once the first AI
        // operation supplied priced usage, the same one-round count is no
        // longer a reason to stop a paid production search.
        $costBudget->shouldReceive('unmeasurable')->once()->andReturn(false);
        $this->assertFalse($method->invoke($storage, 1, 1, 99001, false));
    }

    public function test_fallback_stops_on_a_confirmed_exact_gallery_even_when_loose_urls_came_first(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 10);
        config()->set('product-images.download_candidates', 8);
        $visionCalls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$visionCalls): array {
            $visionCalls++;

            return [
                'images' => [[
                    'index' => 1,
                    'exact_match' => true,
                    'color_match' => true,
                    'publishable' => true,
                    'kind' => 'product',
                    'view' => 'angle',
                    'gallery_rank' => 1,
                    'score' => 99,
                    'reason' => 'Spot-check confirms the exact product.',
                ]],
            ];
        })->preventStrayPrompts();

        $looseUrls = collect(range(1, 8))
            ->map(fn (int $index): string => 'https://loose.example/images/loose-'.$index.'.jpg')
            ->all();
        $confirmedUrls = collect(range(1, 7))
            ->map(fn (int $index): string => 'https://exact.example/images/confirmed-'.$index.'.jpg')
            ->all();
        $exactPage = 'https://exact.example/product/asus-rog-zephyrus-g16-gu605cw-qr133ws';
        ProductGalleryRecipe::query()->create([
            'domain' => 'exact.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['gallery_verification_mode' => 'dedicated'],
        ]);

        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $discovery->shouldReceive('hasTerminalFailure')->andReturn(false)->byDefault();
        $discovery->shouldReceive('find')->once()->andReturn([...$looseUrls, ...$confirmedUrls]);
        $discovery->shouldReceive('sourcePageForImage')->andReturnUsing(
            fn (string $url): string => str_contains($url, 'confirmed-')
                ? $exactPage
                : 'https://loose.example/product/other',
        );

        $downloaded = [];
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturnUsing(
            fn (string $url): bool => str_contains($url, 'confirmed-'),
        );
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false);
        $resolver->shouldReceive('download')->andReturnUsing(
            function (string $url) use (&$downloaded): array {
                $downloaded[] = $url;
                preg_match('/(\d+)\.jpg$/', $url, $match);
                $seed = (int) ($match[1] ?? 1) + (str_contains($url, 'confirmed-') ? 20 : 0);
                $bytes = $this->jpeg($seed);

                return [
                    'bytes' => $bytes,
                    'source_url' => $url,
                    'mime_type' => 'image/jpeg',
                    'width' => 720,
                    'height' => 600,
                    'confirmed_gallery' => str_contains($url, 'confirmed-'),
                    'partial_gallery' => false,
                ];
            },
        );

        [, , $draft] = $this->records();
        $draft->update([
            'title' => 'ASUS ROG Zephyrus G16 GU605CW-QR133WS',
            'brand' => 'ASUS',
            'model' => 'ROG Zephyrus G16 (GU605CW-QR133WS)',
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'GU605CW-QR133WS']],
            'sources' => [],
            'image_urls' => [],
        ]);

        $messages = [];
        $stored = app(ProductImageStorage::class)->stage(
            $draft->fresh(),
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame(7, $stored);
        $this->assertSame(1, $visionCalls);
        $this->assertCount(7, $downloaded);
        $this->assertTrue(collect($downloaded)->every(fn (string $url): bool => str_contains($url, 'confirmed-')));
        $this->assertSame($exactPage, $draft->fresh()->primary_source_url);
        $this->assertSame('complete', $draft->fresh()->gallery_status);
        $this->assertTrue($draft->media->every(fn ($media): bool => $media->verification_status === 'source_verified'));
        $this->assertTrue(collect($messages)->contains(
            fn (string $message): bool => str_contains($message, 'прекращаю обход источников'),
        ));
    }

    public function test_continuation_does_not_retry_a_terminally_failed_source(): void
    {
        Storage::fake('public');
        config()->set('product-images.fallback_discovery', false);
        config()->set('product-images.source_preflight', false);
        AppSetting::put('ai.fallback_sources_enabled', '0');
        ProductImageVisionAgent::fake()->preventStrayPrompts();

        $completedUrl = 'https://example.com/products/completed-source';
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $resolver->shouldNotReceive('download');

        [, , $draft] = $this->records();
        $draft->update([
            'sources' => [[
                'title' => 'Completed source',
                'url' => $completedUrl,
                'type' => 'retailer',
            ]],
            'primary_source_url' => $completedUrl,
            'image_urls' => [],
        ]);
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $draft->telegram_update_id,
            'product_draft_id' => $draft->id,
            'domain' => 'example.com',
            'product_url' => $completedUrl,
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
        ]);

        $stored = app(ProductImageStorage::class)->continueStage($draft->fresh());

        $this->assertSame(0, $stored);
    }

    public function test_continuation_retries_a_source_with_only_non_terminal_checkpoints(): void
    {
        Storage::fake('public');
        config()->set('product-images.fallback_discovery', false);
        config()->set('product-images.source_preflight', false);
        AppSetting::put('ai.fallback_sources_enabled', '0');
        ProductImageVisionAgent::fake()->preventStrayPrompts();

        $incompleteUrl = 'https://example.com/products/incomplete-source';
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([]);
        $resolver->shouldNotReceive('download');

        [, , $draft] = $this->records();
        $draft->update([
            'sources' => [[
                'title' => 'Lenovo Test',
                'url' => $incompleteUrl,
                'type' => 'retailer',
            ]],
            'primary_source_url' => $incompleteUrl,
            'image_urls' => [],
        ]);
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $draft->telegram_update_id,
            'product_draft_id' => $draft->id,
            'domain' => 'example.com',
            'product_url' => $incompleteUrl,
            'actor' => 'playwright',
            'phase' => 'source_resolution',
            'action' => 'resolve_gallery',
            'status' => 'completed',
        ]);
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $draft->telegram_update_id,
            'product_draft_id' => $draft->id,
            'domain' => 'example.com',
            'product_url' => $incompleteUrl,
            'actor' => 'vision',
            'phase' => 'image_verification',
            'action' => 'verify_deferred_source',
            'status' => 'interrupted',
            'decision' => 'retry_source_on_continuation',
        ]);

        $stored = app(ProductImageStorage::class)->continueStage($draft->fresh());

        $this->assertSame(0, $stored);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id,
            'product_url' => $incompleteUrl,
            'phase' => 'image_download',
            'status' => 'failed',
        ]);
    }

    public function test_staging_reports_a_final_outcome_line_with_the_winning_source_and_method(): void
    {
        // Real gap: progress logs used to go from a mid-flight failure
        // straight to "черновик готов, фото: N" with nothing in between
        // explaining which source/mechanism actually produced the photos.
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 98,
                'reason' => 'Exact product and selected color.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://93.184.216.34/product-page') {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            // Distinct seeds per URL - identical bytes would collide on
            // checksum and get silently deduped down to 1 stored image.
            $seed = str_ends_with($request->url(), 'photo-2.jpg') ? 3 : 2;

            return Http::response($this->jpeg($seed), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'brand' => 'Lenovo',
            'model' => 'Test',
            'color' => 'Black',
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'image_urls' => [],
            'sources' => [[
                'title' => 'Store',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
                'image_urls' => [
                    'https://93.184.216.34/photo-1.jpg',
                    'https://93.184.216.34/photo-2.jpg',
                ],
            ]],
        ]);

        $messages = [];
        $stored = app(ProductImageStorage::class)->stage(
            $draft->fresh(),
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame(2, $stored);
        $outcome = end($messages);
        $this->assertStringContainsString('Итог: 2 фото', $outcome);
        $this->assertStringContainsString('93.184.216.34', $outcome);
        $this->assertStringContainsString('статичной HTML-галереи', $outcome);
    }

    public function test_staging_reports_no_gallery_found_when_nothing_is_stored(): void
    {
        Storage::fake('public');
        Http::fake(fn (Request $request) => Http::response('<html><body>No product photos</body></html>', 200, ['Content-Type' => 'text/html']));
        config()->set('product-images.fallback_discovery', false);
        [, , $draft] = $this->records();
        $draft->update([
            'primary_source_url' => 'https://93.184.216.34/without-gallery',
            'image_urls' => [],
            'sources' => [[
                'title' => 'Store without photos',
                'url' => 'https://93.184.216.34/without-gallery',
                'type' => 'retailer',
                'image_urls' => [],
            ]],
        ]);

        $messages = [];
        $stored = app(ProductImageStorage::class)->stage(
            $draft->fresh(),
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertSame(0, $stored);
        $this->assertStringContainsString('Итог: подходящая галерея не найдена', end($messages));
        // Every known source was tried and none produced a gallery, but
        // neither the money nor the time budget was actually hit - only
        // those two limits are allowed to end a request for good, so this
        // must stay resumable (same "continue search" button/flow as a
        // budget/time stop) instead of a dead rejected draft.
        $this->assertSame('exhausted', $draft->fresh()->gallery_search_stop_reason);
    }

    public function test_staging_records_the_reason_each_rejected_candidate_url_failed(): void
    {
        // Real gap (2026-08-05): download_candidates attempts only recorded
        // aggregate counts (11 candidates in, 2 out) with no way to tell why
        // the other 9 were dropped. This verifies a per-URL reason now lands
        // in the attempt record.
        Storage::fake('public');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 98,
                'reason' => 'Exact product image.',
            ])->all(),
        ])->preventStrayPrompts();
        [, , $draft] = $this->records();
        $draft->refresh();
        $draft->update([
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'image_urls' => [],
            'sources' => [[
                'title' => 'Store',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
                'image_urls' => [
                    'https://93.184.216.34/good-photo.jpg',
                    'https://93.184.216.34/too-small.jpg',
                    'https://93.184.216.34/empty-body.jpg',
                ],
            ]],
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'too-small')) {
                return Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }

            if (str_contains($request->url(), 'empty-body')) {
                return Http::response('', 200, ['Content-Type' => 'image/jpeg']);
            }

            if (str_contains($request->url(), 'good-photo')) {
                return Http::response($this->jpeg(3), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
        });

        app(ProductImageStorage::class)->stage($draft->fresh());

        $attempt = ProductSourceAttempt::query()
            ->where('product_draft_id', $draft->id)
            ->where('action', 'download_candidates')
            ->firstOrFail();
        $reasons = collect($attempt->output['rejected_candidates'] ?? [])->pluck('reason', 'url');

        $this->assertSame(1, $attempt->output['downloaded_images']);
        $this->assertStringStartsWith('too_small', (string) $reasons['https://93.184.216.34/too-small.jpg']);
        $this->assertSame('empty_response', $reasons['https://93.184.216.34/empty-body.jpg']);
    }

    public function test_staging_still_opens_a_second_known_card_but_skips_broad_ai_search_when_reserve_is_disabled(): void
    {
        // "Пробовать резервные источники" only ever gates the costlier,
        // less precise mechanism - a brand new AI web search for sources
        // this draft's own research never found. Trying the next already-
        // known candidate card is core behaviour and must happen regardless
        // of this setting.
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        AppSetting::put('ai.gallery_browser_mode', 'off');
        ProductImageVisionAgent::fake()->preventStrayPrompts();
        ProductImageDiscoveryAgent::fake(fn () => throw new \RuntimeException('Broad AI search must not run while the reserve is disabled.'))
            ->preventStrayPrompts();
        Http::fake(fn (Request $request) => Http::response(
            '<html><body>No product photos</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ));
        [, , $draft] = $this->records();
        $draft->update([
            'primary_source_url' => 'https://93.184.216.34/first-card',
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'First card',
                    'url' => 'https://93.184.216.34/first-card',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
                [
                    'title' => 'Second card',
                    'url' => 'https://93.184.216.34/second-card',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
            ],
        ]);

        $this->assertSame(0, app(ProductImageStorage::class)->stage($draft->fresh()));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/first-card');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/second-card');
    }

    public function test_staging_keeps_the_previous_gallery_when_the_search_finds_nothing(): void
    {
        Storage::fake('public');
        ProductImageDiscoveryAgent::fake([[
            'sources' => [],
            'image_urls' => [],
            'page_urls' => [],
        ]])->preventStrayPrompts();
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Vision should not run without candidates'))
            ->preventStrayPrompts();
        Http::fake(fn () => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']));
        [, , $draft] = $this->records();
        $draft->update([
            'brand' => 'Lenovo',
            'model' => 'Test',
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'image_urls' => [],
            'sources' => [[
                'title' => 'Exact retailer listing',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
            ]],
        ]);
        $oldPath = "drafts/{$draft->id}/primary-old.webp";
        Storage::disk('public')->put($oldPath, 'old-photo-bytes');
        $old = $draft->media()->create([
            'disk' => 'public',
            'path' => $oldPath,
            'source_url' => 'https://93.184.216.34/old-photo.jpg',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'checksum' => hash('sha256', 'old-photo-bytes'),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(0, $stored);
        $this->assertSame(1, $draft->media()->count());
        $this->assertDatabaseHas('product_draft_media', ['id' => $old->id]);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_continue_button_reappears_when_a_round_adds_nothing_to_a_below_minimum_retained_gallery(): void
    {
        // Regression: a round that finds nothing new used to clear
        // gallery_search_stop_reason whenever ANY old media already existed,
        // even if that retained gallery was still below the category
        // minimum and the round was genuinely cut off by the cost budget -
        // silently hiding the "Continue search" button from the operator.
        Storage::fake('public');
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('exceeded')->andReturn(true);
        $costBudget->shouldReceive('limit')->andReturn(0.50);
        $costBudget->shouldReceive('unmeasurable')->andReturn(false);
        Http::fake(fn () => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']));
        [, , $draft] = $this->records();
        $draft->update([
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'image_urls' => [],
            'gallery_status' => 'partial',
            'sources' => [[
                'title' => 'Retailer',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
            ]],
        ]);
        $oldPath = "drafts/{$draft->id}/primary-old.webp";
        Storage::disk('public')->put($oldPath, 'old-photo-bytes');
        $old = $draft->media()->create([
            'disk' => 'public',
            'path' => $oldPath,
            'source_url' => 'https://93.184.216.34/old-photo.jpg',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'checksum' => hash('sha256', 'old-photo-bytes'),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(0, $stored);
        $this->assertSame(1, $draft->media()->count());
        $this->assertDatabaseHas('product_draft_media', ['id' => $old->id]);
        $this->assertSame('cost_budget', $draft->fresh()->gallery_search_stop_reason);
    }

    public function test_continue_button_stays_hidden_once_a_retained_gallery_was_already_confirmed_complete(): void
    {
        // The opposite case: a small gallery an earlier round already
        // confirmed as complete (gallery_status === 'complete') must not
        // start showing "Continue search" just because a later round found
        // nothing new and the budget happens to be exhausted - there is
        // genuinely nothing left to find.
        Storage::fake('public');
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('exceeded')->andReturn(true);
        $costBudget->shouldReceive('limit')->andReturn(0.50);
        $costBudget->shouldReceive('unmeasurable')->andReturn(false);
        Http::fake(fn () => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']));
        [, , $draft] = $this->records();
        $draft->update([
            'primary_source_url' => 'https://93.184.216.34/product-page',
            'image_urls' => [],
            'gallery_status' => 'complete',
            'sources' => [[
                'title' => 'Retailer',
                'url' => 'https://93.184.216.34/product-page',
                'type' => 'retailer',
            ]],
        ]);
        $oldPath = "drafts/{$draft->id}/primary-old.webp";
        Storage::disk('public')->put($oldPath, 'old-photo-bytes');
        $draft->media()->create([
            'disk' => 'public',
            'path' => $oldPath,
            'source_url' => 'https://93.184.216.34/old-photo.jpg',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'checksum' => hash('sha256', 'old-photo-bytes'),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(0, $stored);
        $this->assertSame(1, $draft->media()->count());
        $this->assertNull($draft->fresh()->gallery_search_stop_reason);
    }

    public function test_adopting_staged_photos_keeps_them_when_the_copy_fails(): void
    {
        Storage::fake('public');
        [$product, $variant, $draft] = $this->records();
        $path = "drafts/{$draft->id}/staged-1.webp";
        Storage::disk('public')->put($path, 'staged-bytes');
        $media = $draft->media()->create([
            'disk' => 'public',
            'path' => $path,
            'source_url' => 'https://93.184.216.34/staged-1.jpg',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'checksum' => hash('sha256', 'staged-bytes'),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('get')->andReturn('staged-bytes');
        $disk->shouldReceive('put')->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $stored = app(ProductImageStorage::class)->adoptStaged($product, $variant, $draft);

        $this->assertSame(0, $stored);
        $this->assertSame(0, $product->media()->count());
        $this->assertDatabaseHas('product_draft_media', ['id' => $media->id]);
    }

    public function test_image_discovery_is_cached_for_queue_retries(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]]),
        ]);
        ProductImageDiscoveryAgent::fake([[
            'sources' => [],
            'image_urls' => ['https://93.184.216.34/exact-product.jpg'],
            'page_urls' => [],
        ]])->preventStrayPrompts();
        [, , $draft] = $this->records();
        $discovery = app(ProductImageCandidateDiscovery::class);

        $first = $discovery->find($draft);
        $second = $discovery->find($draft);

        $this->assertSame($first, $second);
        $this->assertSame(['https://93.184.216.34/exact-product.jpg'], $second);
        $this->assertDatabaseCount('ai_runs', 2);
    }

    public function test_optional_image_discovery_failure_returns_empty_result_without_crashing_the_product_cycle(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => throw new \RuntimeException('discovery timeout'))
            ->preventStrayPrompts();
        $this->mock(WikimediaImageSearch::class)
            ->shouldReceive('find')
            ->once()
            ->andReturn([]);
        [, , $draft] = $this->records();
        $draft->update([
            'sources' => [],
            'image_urls' => [],
        ]);

        $images = app(ProductImageCandidateDiscovery::class)->find($draft->fresh());

        $this->assertSame([], $images);
        $this->assertDatabaseHas('ai_runs', [
            'telegram_update_id' => $draft->telegram_update_id,
            'status' => 'failed',
            'error' => 'discovery timeout',
        ]);
    }

    public function test_image_discovery_preserves_the_exact_page_for_each_image_on_a_shared_cdn(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]]),
        ]);
        ProductImageDiscoveryAgent::fake([[
            'sources' => [
                [
                    'page_url' => 'https://store-one.example/products/model-a',
                    'image_urls' => ['https://shared-cdn.example/model-a-front.jpg'],
                ],
                [
                    'page_url' => 'https://store-two.example/products/model-a',
                    'image_urls' => ['https://shared-cdn.example/model-a-back.jpg'],
                ],
            ],
            'image_urls' => [],
            'page_urls' => [],
        ]])->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('resolve')
            ->twice()
            ->andReturn([]);
        [, , $draft] = $this->records();
        $draft->update(['sources' => [], 'image_urls' => []]);
        $discovery = app(ProductImageCandidateDiscovery::class);

        $images = $discovery->find($draft->fresh());

        $this->assertContains('https://shared-cdn.example/model-a-front.jpg', $images);
        $this->assertContains('https://shared-cdn.example/model-a-back.jpg', $images);
        $this->assertSame(
            'https://store-one.example/products/model-a',
            $discovery->sourcePageForImage('https://shared-cdn.example/model-a-front.jpg'),
        );
        $this->assertSame(
            'https://store-two.example/products/model-a',
            $discovery->sourcePageForImage('https://shared-cdn.example/model-a-back.jpg'),
        );
    }

    public function test_image_discovery_rejects_a_returned_source_that_was_already_excluded(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]])]);
        ProductImageDiscoveryAgent::fake([[
            'sources' => [
                [
                    'page_url' => 'https://store-one.example/products/model-a',
                    'image_urls' => ['https://shared-cdn.example/old.jpg'],
                ],
                [
                    'page_url' => 'https://store-two.example/products/model-a',
                    'image_urls' => ['https://shared-cdn.example/new.jpg'],
                ],
            ],
            'image_urls' => [],
            'page_urls' => [],
        ]])->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('resolve')
            ->once()
            ->withArgs(fn (array $sources): bool => ($sources[0]['url'] ?? null) === 'https://store-two.example/products/model-a')
            ->andReturn([]);
        [, , $draft] = $this->records();
        $draft->update(['sources' => [], 'image_urls' => []]);

        $images = app(ProductImageCandidateDiscovery::class)->find(
            $draft->fresh(),
            skipKnownSources: true,
            additionalExcludedSourceUrls: ['https://store-one.example/products/model-a'],
        );

        $this->assertSame(['https://shared-cdn.example/new.jpg'], $images);
        $this->assertNull(app(ProductImageCandidateDiscovery::class)->sourcePageForImage('https://shared-cdn.example/old.jpg'));
    }

    public function test_image_discovery_ignores_official_header_assets_before_short_circuiting(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]]),
        ]);
        ProductImageDiscoveryAgent::fake([[
            'sources' => [],
            'image_urls' => ['https://dlcdnwebimgs.asus.com/gain/exact-product/w800'],
            'page_urls' => [],
        ]])->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('resolve')->once()->andReturn([
            'https://www.asus.com/media/Odin/images/header/ROG_normal.svg',
            'https://www.asus.com/media/Odin/images/header/ProArt_hover.svg',
            'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w48',
            'https://dlcdnwebimgs.asus.com/gain/a57df082-80c1-4b35-9493-ff9727e4e7a4//w184',
            'https://dlcdnwebimgs.asus.com/gain/7fb77e0a-97ef-4be0-babd-22892330add9//w48',
            'https://dlcdnwebimgs.asus.com/gain/7fb77e0a-97ef-4be0-babd-22892330add9//w184',
        ]);
        [, , $draft] = $this->records();
        $draft->update([
            'brand' => 'ASUS',
            'model' => 'X1504VA-BQ4485',
            'sources' => [[
                'title' => 'ASUS Vivobook 15 (X1504)',
                'url' => 'https://www.asus.com/laptops/for-home/vivobook/asus-vivobook-15-x1504/',
                'type' => 'manufacturer',
            ]],
        ]);

        $images = app(ProductImageCandidateDiscovery::class)->find($draft->fresh());

        $this->assertContains('https://dlcdnwebimgs.asus.com/gain/exact-product/w800', $images);
        $this->assertDatabaseHas('ai_runs', [
            'telegram_update_id' => $draft->telegram_update_id,
            'status' => 'completed',
        ]);
    }

    public function test_wikimedia_search_returns_only_relevant_physical_product_images(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response([
                'query' => [
                    'pages' => [
                        [
                            'index' => 1,
                            'title' => 'File:Apple iPad Air 2 (front).jpg',
                            'imageinfo' => [[
                                'mime' => 'image/jpeg',
                                'width' => 2400,
                                'height' => 1600,
                                'thumbwidth' => 1600,
                                'thumbheight' => 1067,
                                'thumburl' => 'https://upload.wikimedia.org/ipad-air-2.jpg',
                            ]],
                        ],
                        [
                            'index' => 2,
                            'title' => 'File:Apple iPad Air 2 Logo.svg',
                            'imageinfo' => [[
                                'mime' => 'image/svg+xml',
                                'width' => 1200,
                                'height' => 300,
                                'url' => 'https://upload.wikimedia.org/ipad-air-2-logo.svg',
                            ]],
                        ],
                        [
                            'index' => 3,
                            'title' => 'File:Apple fruit.jpg',
                            'imageinfo' => [[
                                'mime' => 'image/jpeg',
                                'width' => 2000,
                                'height' => 1500,
                                'url' => 'https://upload.wikimedia.org/apple-fruit.jpg',
                            ]],
                        ],
                    ],
                ],
            ]),
        ]);
        $draft = new ProductDraft([
            'title' => 'Apple iPad Air 2',
            'brand' => 'Apple',
            'model' => 'iPad Air 2',
        ]);

        $images = app(WikimediaImageSearch::class)->find($draft);

        $this->assertSame(['https://upload.wikimedia.org/ipad-air-2.jpg'], $images);
    }

    public function test_rejecting_current_draft_gallery_persists_sources_urls_and_visual_hashes(): void
    {
        Storage::fake('public');
        [, , $draft] = $this->records();
        $bytes = $this->jpeg(4);
        Storage::disk('public')->put('drafts/old.jpg', $bytes);
        $draft->update(['primary_source_url' => 'https://old-store.example/product']);
        $draft->media()->create([
            'disk' => 'public',
            'path' => 'drafts/old.jpg',
            'source_url' => 'https://cdn.old-store.example/gallery/front.jpg',
            'role' => 'primary',
            'mime_type' => 'image/jpeg',
            'width' => 640,
            'height' => 640,
            'file_size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        app(ProductImageStorage::class)->excludeCurrentDraftGallery($draft);
        $draft->refresh();

        $this->assertContains('https://old-store.example/product', $draft->excluded_gallery_source_urls);
        $this->assertContains('https://cdn.old-store.example/gallery/front.jpg', $draft->excluded_gallery_source_urls);
        $this->assertContains('https://cdn.old-store.example/gallery/front.jpg', $draft->excluded_gallery_image_urls);
        $this->assertCount(1, $draft->excluded_gallery_hashes);
    }

    public function test_a_source_blocked_by_a_retryable_http_error_ranks_above_an_equally_uninformative_one(): void
    {
        // Regression: browser_probe_required (set on a 401/403/429 - the
        // cheap fetch was refused, but Playwright often gets through where
        // it can't) was computed by preflightSource() and then never read by
        // anything downstream. A bot-blocked official source sank to the
        // bottom of the ranking exactly like a genuinely irrelevant one -
        // indistinguishable once both had "zero evidence" - so a search
        // could burn its whole budget on a worse, merely-not-erroring
        // source and never reach the one flagged as worth retrying.
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        config()->set('product-images.source_preflight', true);
        Http::fake([
            'https://93.184.216.40/empty-retailer.html' => Http::response('<html><body></body></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
            'https://93.184.216.41/blocked-official.html' => Http::response('Forbidden', 403),
        ]);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->andReturn([]);
        $browser->shouldReceive('isConfirmedGalleryImage')->andReturn(false);
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        [, , $draft] = $this->records();
        $draft->update([
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'Empty retailer listing (fetches fine, nothing useful)',
                    'url' => 'https://93.184.216.40/empty-retailer.html',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
                [
                    'title' => 'Official page (blocked by bot protection)',
                    'url' => 'https://93.184.216.41/blocked-official.html',
                    'type' => 'manufacturer',
                    'image_urls' => [],
                ],
            ],
        ]);
        $progressMessages = [];

        app(ProductImageStorage::class)->stage(
            $draft->fresh(),
            function (string $message) use (&$progressMessages): void {
                $progressMessages[] = $message;
            },
        );

        $sourceOrder = collect($progressMessages)
            ->filter(fn (string $message): bool => str_starts_with($message, 'Проверяю источник'))
            ->values();
        $this->assertStringContainsString('blocked-official.html', $sourceOrder->first());
    }

    public function test_playwright_first_can_train_a_new_recipe_on_any_known_source_not_only_the_first(): void
    {
        Storage::fake('public');
        config()->set('product-images.source_preflight', false);
        config()->set('product-images.fallback_discovery', false);
        AppSetting::put('ai.fallback_sources_enabled', '0');
        ProductImageVisionAgent::fake()->preventStrayPrompts();

        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')
            ->twice()
            ->withArgs(function (
                array $sources,
                int $limit,
                ?callable $debug,
                ?int $telegramUpdateId,
                bool $forceInteractive,
                bool $staticOnly,
                bool $activeRecipeOnly,
            ): bool {
                $this->assertFalse($activeRecipeOnly);

                return true;
            })
            ->andReturn([]);
        $resolver->shouldNotReceive('download');

        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'First exact card without a gallery',
                    'url' => 'https://first.example/products/lenovo-test',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
                [
                    'title' => 'Second exact card that needs training',
                    'url' => 'https://second.example/products/lenovo-test',
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
            ],
        ]);

        $this->assertSame(0, app(ProductImageStorage::class)->stage($draft->fresh()));
    }

    public function test_preflight_static_url_volume_cannot_displace_the_selected_primary_card(): void
    {
        Storage::fake('public');
        config()->set('product-images.source_preflight', true);
        config()->set('product-images.fallback_discovery', false);
        AppSetting::put('ai.fallback_sources_enabled', '0');
        ProductImageVisionAgent::fake()->preventStrayPrompts();

        $primaryUrl = 'https://primary.example/products/lenovo-test';
        $secondaryUrl = 'https://secondary.example/products/lenovo-test';
        $opened = [];
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('preflightSource')->twice()->andReturnUsing(
            function (array $source) use ($primaryUrl): array {
                $staticUrls = $source['url'] === $primaryUrl
                    ? []
                    : collect(range(1, 17))
                        ->map(fn (int $index): string => 'https://secondary.example/images/'.$index.'.jpg')
                        ->all();

                return [
                    'static_image_urls' => $staticUrls,
                    'blocked' => false,
                    'unavailable' => false,
                    'active_recipe' => false,
                    'browser_probe_required' => false,
                    'final_url' => $source['url'],
                    'identity_evidence' => 'Lenovo Test',
                ];
            },
        );
        $resolver->shouldReceive('resolve')->twice()->andReturnUsing(
            function (array $sources) use (&$opened): array {
                $opened[] = $sources[0]['url'];

                return [];
            },
        );
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('download')->andReturn(null)->byDefault();

        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'primary_source_url' => $primaryUrl,
            'image_urls' => [],
            'sources' => [
                [
                    'title' => 'Selected primary card',
                    'url' => $primaryUrl,
                    'type' => 'retailer',
                    'image_urls' => [],
                ],
                [
                    'title' => 'Secondary page with many unverified static URLs',
                    'url' => $secondaryUrl,
                    'type' => 'manufacturer',
                    'image_urls' => [],
                ],
            ],
        ]);

        app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame($primaryUrl, $opened[0] ?? null);
    }

    public function test_staging_never_opens_a_blacklisted_source_and_uses_a_new_one(): void
    {
        Storage::fake('public');
        config()->set('product-images.max_images_by_type.laptop', 3);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 98,
                'reason' => 'Exact professional catalog image.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://93.184.216.35/new-card') {
                return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response($this->jpeg(5), 200, ['Content-Type' => 'image/jpeg']);
        });
        [, , $draft] = $this->records();
        $draft->update([
            'product_type' => 'laptop',
            'brand' => 'Lenovo',
            'model' => 'Test',
            'color' => 'Black',
            'primary_source_url' => 'https://93.184.216.34/old-card',
            'image_urls' => [],
            'excluded_gallery_source_urls' => ['https://93.184.216.34/old-card'],
            'sources' => [
                [
                    'title' => 'Rejected store',
                    'url' => 'https://93.184.216.34/old-card',
                    'type' => 'retailer',
                    'image_urls' => ['https://93.184.216.34/old-photo.jpg'],
                ],
                [
                    'title' => 'New store',
                    'url' => 'https://93.184.216.35/new-card',
                    'type' => 'manufacturer',
                    'image_urls' => ['https://93.184.216.35/new-photo.jpg'],
                ],
            ],
        ]);

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(1, $stored);
        $this->assertSame('https://93.184.216.35/new-card', $draft->fresh()->primary_source_url);
        $this->assertSame('https://93.184.216.35/new-photo.jpg', $draft->media()->firstOrFail()->source_url);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '93.184.216.34'));
    }

    public function test_scene7_named_size_modifier_variants_normalize_to_the_same_url(): void
    {
        $normalize = fn (string $url): string => $this->invokeNormalizeCandidateUrl($url);

        // Samsung's CDN serves the exact same photo through this Scene7
        // "named modifier" query form, seen both percent-encoded and raw
        // depending on which page/template extracted it - without collapsing
        // these to one URL, both get downloaded and stored as if they were
        // two different photos (perceptual hashing alone measured a real
        // distance of 10 between two such renditions, above the configured
        // near-duplicate threshold of 6).
        $encoded = $normalize('https://images.samsung.com/is/image/samsung/ca-870-qvo-sata-3-2-5-ssd-mz-77q4t0b-am-Black-262825305?%241164_776_PNG%24=');
        $raw = $normalize('https://images.samsung.com/is/image/samsung/ca-870-qvo-sata-3-2-5-ssd-mz-77q4t0b-am-Black-262825305?$1164_776_PNG$');

        $this->assertSame($encoded, $raw);
        $this->assertSame('https://images.samsung.com/is/image/samsung/ca-870-qvo-sata-3-2-5-ssd-mz-77q4t0b-am-Black-262825305', $encoded);
    }

    public function test_scene7_wid_hei_params_still_upscale_and_drop_scl(): void
    {
        $normalized = $this->invokeNormalizeCandidateUrl(
            'https://www.hp.com/is/image/HP/product?wid=90&hei=90&scl=3',
        );

        $this->assertSame('https://www.hp.com/is/image/HP/product?wid=1500&hei=1500', $normalized);
    }

    public function test_shopify_width_and_height_variants_normalize_to_the_same_url(): void
    {
        // Real case (draft #27, vishalperipherals.com): the exact same photo
        // was staged twice as "two photos" because Shopify's CDN resizes via
        // a &width=N query param on an otherwise identical URL - perceptual
        // hashing alone measured a real distance of 7 between the two
        // renditions, one bit above the configured near-duplicate threshold
        // of 6, so URL-level normalization is the only reliable defense.
        $original = $this->invokeNormalizeCandidateUrl(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a.png?v=1753955410',
        );
        $resized = $this->invokeNormalizeCandidateUrl(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a.png?v=1753955410&width=1445',
        );

        $this->assertSame($original, $resized);
        $this->assertSame(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a.png?v=1753955410',
            $original,
        );
    }

    public function test_shopify_cdn_domain_variants_also_normalize(): void
    {
        $original = $this->invokeNormalizeCandidateUrl(
            'https://cdn.shopify.com/s/files/1/0001/0002/products/laptop.jpg?v=1',
        );
        $resized = $this->invokeNormalizeCandidateUrl(
            'https://cdn.shopify.com/s/files/1/0001/0002/products/laptop.jpg?v=1&width=800&height=800',
        );

        $this->assertSame($original, $resized);
    }

    public function test_shopify_filename_suffix_variants_normalize_to_the_master_url(): void
    {
        // Same real case as the query-param test above, but for Shopify's
        // OTHER resize mechanism - a size suffix baked into the filename
        // itself ("_180x", "_grande", "_1920x"), which the query-param strip
        // never touches. Mirrors scripts/product-gallery-utils.mjs's
        // imageAssetKey()/SHOPIFY_SIZE_SUFFIX - keep both in sync.
        $master = $this->invokeNormalizeCandidateUrl(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a.png?v=1753955410',
        );

        $this->assertSame($master, $this->invokeNormalizeCandidateUrl(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a_180x.png?v=1753955410',
        ));
        $this->assertSame($master, $this->invokeNormalizeCandidateUrl(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a_grande.png?v=1753955410',
        ));
        $this->assertSame($master, $this->invokeNormalizeCandidateUrl(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_285f809a_1920x.png?v=1753955410',
        ));
        $this->assertNotSame($master, $this->invokeNormalizeCandidateUrl(
            'https://vishalperipherals.com/cdn/shop/files/x1605va_9999999b_180x.png?v=1753955410',
        ));
    }

    public function test_named_rendition_directories_share_one_asset_key(): void
    {
        $large = 'https://cdn.example/Images/Product/Default/large/108438004_8959892653.jpg';
        $xlarge = 'https://cdn.example/Images/Product/Default/xlarge/108438004_8959892653.jpg';
        $different = 'https://cdn.example/Images/Product/Default/xlarge/another-frame.jpg';

        $this->assertSame(
            ProductImageStorage::imageAssetKey($large),
            ProductImageStorage::imageAssetKey($xlarge),
        );
        $this->assertNotSame(
            ProductImageStorage::imageAssetKey($xlarge),
            ProductImageStorage::imageAssetKey($different),
        );
    }

    public function test_uuid_filename_renditions_share_one_asset_key(): void
    {
        $frame = '01988206-a137-7bfd-912b-69d69304d643';
        $thumb = 'https://static01.example/productimages/'.$frame.'_720.jpeg';
        $expanded = 'https://static01.example/productimages/'.$frame.'_sea.jpeg';
        $alternateFormat = 'https://static01.example/productimages/'.$frame.'_1000.avif';
        $different = 'https://static01.example/productimages/01988206-a1a1-73fc-a9cf-83cc4b7d9198_720.jpeg';

        $this->assertSame(
            ProductImageStorage::imageAssetKey($thumb),
            ProductImageStorage::imageAssetKey($expanded),
        );
        $this->assertSame(
            ProductImageStorage::imageAssetKey($expanded),
            ProductImageStorage::imageAssetKey($alternateFormat),
        );
        $this->assertNotSame(
            ProductImageStorage::imageAssetKey($expanded),
            ProductImageStorage::imageAssetKey($different),
        );

        $cleanUrls = new \ReflectionMethod(ProductImageStorage::class, 'cleanUrls');
        $cleanUrls->setAccessible(true);

        $this->assertSame(
            [$expanded],
            $cleanUrls->invoke(app(ProductImageStorage::class), [$thumb, $expanded]),
        );
    }

    public function test_ldlc_renditions_share_one_asset_key_and_largest_is_kept(): void
    {
        $small = 'https://media.ldlc.com/r705/ld/products/00/06/17/52/LD0006175263.jpg';
        $large = 'https://media.ldlc.com/r1600/ld/products/00/06/17/52/LD0006175263.jpg';

        $this->assertSame(
            ProductImageStorage::imageAssetKey($small),
            ProductImageStorage::imageAssetKey($large),
        );

        $cleanUrls = new \ReflectionMethod(ProductImageStorage::class, 'cleanUrls');
        $cleanUrls->setAccessible(true);

        $this->assertSame([$large], $cleanUrls->invoke(app(ProductImageStorage::class), [$small, $large]));
    }

    public function test_bigcommerce_stencil_size_segment_renditions_share_one_asset_key_and_largest_is_kept(): void
    {
        // Regression: a real fleetnetwork.ca (BigCommerce Stencil) search
        // staged the same hero photo four times (500x500, 640w, 840w,
        // 1280x1280) as if they were four different gallery frames, wasting
        // slots that should have gone to the product's other distinct
        // angles. The size lives in its own path segment between
        // "/stencil/" and "/products/", not adjacent to the filename like
        // the named /large//xlarge/ buckets, and not a query param either.
        $thumb = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/500x500/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
        $srcsetMid = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/640w/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
        $srcsetWide = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/840w/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
        $zoom = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1280x1280/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
        $otherAngle = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1280x1280/products/689686/3683750/1086710301__24458.1766340706.jpg?c=2';

        foreach ([$srcsetMid, $srcsetWide, $zoom] as $sameAsset) {
            $this->assertSame(ProductImageStorage::imageAssetKey($thumb), ProductImageStorage::imageAssetKey($sameAsset));
        }
        $this->assertNotSame(ProductImageStorage::imageAssetKey($zoom), ProductImageStorage::imageAssetKey($otherAngle));

        $cleanUrls = new \ReflectionMethod(ProductImageStorage::class, 'cleanUrls');
        $cleanUrls->setAccessible(true);

        // normalizeCandidateUrl()'s generic WxH bump (see that method) has
        // no way to know 1280x1280 is already this real CDN's maximum - it
        // proposes 1600x1600 for every candidate below that ceiling, same
        // as any other domain. Losing the photo if 1600 turns out not to
        // exist is prevented downstream in downloadCandidates() (see
        // test_download_candidates_falls_back_to_the_observed_rendition_
        // when_the_guessed_upgrade_404s), not here - cleanUrls() only
        // proposes candidates, it never fetches.
        $this->assertSame(
            [
                'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1600x1600/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2',
                'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1600x1600/products/689686/3683750/1086710301__24458.1766340706.jpg?c=2',
            ],
            $cleanUrls->invoke(app(ProductImageStorage::class), [$thumb, $srcsetMid, $srcsetWide, $zoom, $otherAngle]),
        );
    }

    public function test_download_candidates_falls_back_to_the_observed_rendition_when_the_guessed_upgrade_404s(): void
    {
        // Real case: normalizeCandidateUrl() guesses 1600x1600 for any WxH
        // segment below that, but fleetnetwork.ca (BigCommerce Stencil)
        // only ever serves this product up to 1280x1280 - the guess 404s.
        // The photo must still be downloaded at the real, observed size
        // instead of being lost entirely.
        $observed = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1280x1280/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
        $guessed = 'https://cdn11.bigcommerce.com/s-xwx16vh8tc/images/stencil/1600x1600/products/689686/3683749/1086710301__09195.1766340706.jpg?c=2';
        Http::fake([
            $guessed => Http::response('Not Found', 404),
            $observed => Http::response($this->jpeg(21), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        [, , $draft] = $this->records();

        $downloadCandidates = new \ReflectionMethod(ProductImageStorage::class, 'downloadCandidates');
        $downloadCandidates->setAccessible(true);
        $candidates = $downloadCandidates->invoke(app(ProductImageStorage::class), [$observed], $draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($observed, $candidates[0]['source_url']);
        imagedestroy($candidates[0]['image']);
    }

    public function test_discovery_preserves_the_observed_rendition_until_the_download_fallback_is_built(): void
    {
        // Production-route regression for B&H attempt 1131: Playwright had
        // already validated the real 750x750 URL and rejected the guessed
        // 1600x1600 rendition, but discoverCandidates() used to clean and
        // upgrade the URL before downloadCandidates() could remember the
        // observed fallback. The direct downloader test above did not cover
        // that destructive pre-cleaning step.
        $page = 'https://www.bhphotovideo.com/c/product/1932364-REG/example.html/accessories';
        $observed = 'https://static.bhphoto.com/images/images750x750/1767181363_1932364.jpg';
        $guessed = 'https://static.bhphoto.com/images/images1600x1600/1767181363_1932364.jpg';
        Http::fake([
            $guessed => Http::response('Not Found', 404),
            $observed => Http::response($this->jpeg(22), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('find')->once()->andReturn([$observed]);
        $discovery->shouldReceive('sourcePageForImage')->andReturn($page);
        $discovery->shouldReceive('sourceContextForImage')->andReturn([
            'url' => $page,
            'title' => 'B&H exact product page',
        ]);

        [, , $draft] = $this->records();
        $discoverCandidates = new \ReflectionMethod(ProductImageStorage::class, 'discoverCandidates');
        $discoverCandidates->setAccessible(true);
        [$candidates] = $discoverCandidates->invoke(
            app(ProductImageStorage::class),
            $draft,
            [],
            true,
        );

        $this->assertCount(1, $candidates);
        $this->assertSame($observed, $candidates[0]['source_url']);
        Http::assertSent(fn (Request $request): bool => $request->url() === $guessed);
        Http::assertSent(fn (Request $request): bool => $request->url() === $observed);
        imagedestroy($candidates[0]['image']);
    }

    public function test_fallback_rejects_a_redirected_foreign_model_before_returning_its_images(): void
    {
        $requestedPage = 'https://shop.example/products/hp-omen-max-16-ah0097nr';
        $redirectedPage = 'https://shop.example/products/hp-omen-17-db1095cl';
        $wrongImages = collect(range(1, 5))
            ->map(fn (int $index): string => 'https://cdn.example/db1095cl/'.$index.'.jpg')
            ->all();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn($wrongImages);
        $resolver->shouldReceive('sourceContextForImage')->andReturn([
            'url' => $redirectedPage,
            'title' => 'HP OMEN 17-db1095cl Gaming Laptop',
        ])->byDefault();

        [, , $draft] = $this->records();
        $draft->telegramUpdate()->update(['text' => 'HP OMEN MAX 16-ah0097nr ищи']);
        $draft->update([
            'title' => 'HP OMEN MAX 16-ah0097nr',
            'brand' => 'HP',
            'model' => 'OMEN MAX 16-ah0097nr',
            'specifications' => [[
                'key' => 'sku',
                'name' => 'SKU',
                'value' => '16-ah0097nr',
            ]],
        ]);

        $method = new \ReflectionMethod(ProductImageCandidateDiscovery::class, 'resolveSourcesIndividually');
        $method->setAccessible(true);
        $resolved = $method->invoke(
            app(ProductImageCandidateDiscovery::class),
            [['url' => $requestedPage]],
            $draft->fresh(),
        );

        $this->assertSame([], $resolved);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id,
            'product_url' => $redirectedPage,
            'action' => 'validate_discovery_runtime_identity',
            'decision' => 'reject_runtime_identifier_mismatch',
        ]);
    }

    public function test_fallback_preserves_exact_search_title_for_an_opaque_product_url(): void
    {
        $opaquePage = 'https://shop.example/product/1881718';
        $images = [
            'https://cdn.example/ah0097nr/front.jpg',
            'https://cdn.example/ah0097nr/side.jpg',
            'https://cdn.example/ah0097nr/back.jpg',
        ];
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn($images);
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();

        [, , $draft] = $this->records();
        $draft->telegramUpdate()->update(['text' => 'HP OMEN MAX 16-ah0097nr ищи']);
        $draft->update([
            'title' => 'HP OMEN MAX 16-ah0097nr',
            'brand' => 'HP',
            'model' => 'OMEN MAX 16-ah0097nr',
            'specifications' => [[
                'key' => 'sku',
                'name' => 'SKU',
                'value' => '16-ah0097nr',
            ]],
        ]);
        $sourceEvidence = [
            'url' => $opaquePage,
            'title' => 'HP OMEN MAX 16-ah0097nr Gaming Laptop',
        ];
        $matcher = app(\App\Services\Products\ProductIdentityMatcher::class);
        $this->assertTrue($matcher->supportsSource($draft->fresh(), $sourceEvidence));
        $this->assertFalse($matcher->conflictsSource($draft->fresh(), $sourceEvidence));

        $discovery = app(ProductImageCandidateDiscovery::class);
        $method = new \ReflectionMethod(ProductImageCandidateDiscovery::class, 'resolveSourcesIndividually');
        $method->setAccessible(true);
        $resolved = $method->invoke(
            $discovery,
            [[
                'url' => $opaquePage,
                'title' => 'HP OMEN MAX 16-ah0097nr Gaming Laptop',
            ]],
            $draft->fresh(),
        );

        $this->assertSame($images, $resolved);
        $this->assertSame(
            'HP OMEN MAX 16-ah0097nr Gaming Laptop',
            $discovery->sourceContextForImage($images[0])['title'] ?? null,
        );
    }

    public function test_wrong_discovery_page_cannot_consume_download_slots_before_a_later_exact_gallery(): void
    {
        config()->set('product-images.download_candidates', 10);
        $wrongPage = 'https://wrong.example/products/hp-omen-17-db1095cl';
        $exactPage = 'https://exact.example/products/hp-omen-max-16-ah0097nr';
        $wrongUrls = collect(range(1, 15))
            ->map(fn (int $index): string => 'https://wrong-cdn.example/db1095cl/'.$index.'.jpg')
            ->all();
        $exactUrls = collect(range(1, 5))
            ->map(fn (int $index): string => 'https://exact-cdn.example/ah0097nr/'.$index.'.jpg')
            ->all();

        $contextFor = fn (string $url): array => str_contains($url, 'exact-cdn')
            ? ['url' => $exactPage, 'title' => 'HP OMEN MAX 16-ah0097nr']
            : ['url' => $wrongPage, 'title' => 'HP OMEN 17-db1095cl'];
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('find')->once()->andReturn([...$wrongUrls, ...$exactUrls]);
        $discovery->shouldReceive('sourceContextForImage')->andReturnUsing($contextFor)->byDefault();
        $discovery->shouldReceive('sourcePageForImage')->andReturnUsing(
            fn (string $url): string => $contextFor($url)['url'],
        )->byDefault();

        $downloaded = [];
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturnUsing(
            fn (string $url): bool => str_contains($url, 'exact-cdn'),
        );
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false)->byDefault();
        $resolver->shouldReceive('download')->andReturnUsing(function (string $url) use (&$downloaded): array {
            $this->assertStringContainsString('exact-cdn.example/ah0097nr/', $url);
            $downloaded[] = $url;
            preg_match('~/(\d+)\.jpg$~', $url, $match);

            return [
                'bytes' => $this->jpeg(50 + (int) ($match[1] ?? 1)),
                'source_url' => $url,
                'mime_type' => 'image/jpeg',
                'width' => 720,
                'height' => 600,
                'confirmed_gallery' => true,
                'partial_gallery' => false,
            ];
        });

        [, , $draft] = $this->records();
        $draft->telegramUpdate()->update(['text' => 'HP OMEN MAX 16-ah0097nr ищи']);
        $draft->update([
            'title' => 'HP OMEN MAX 16-ah0097nr',
            'brand' => 'HP',
            'model' => 'OMEN MAX 16-ah0097nr',
            'specifications' => [[
                'key' => 'sku',
                'name' => 'SKU',
                'value' => '16-ah0097nr',
            ]],
            'image_urls' => [],
            'sources' => [],
        ]);

        $method = new \ReflectionMethod(ProductImageStorage::class, 'discoverCandidates');
        $method->setAccessible(true);
        [$candidates] = $method->invoke(app(ProductImageStorage::class), $draft->fresh(), [], true);

        $this->assertCount(5, $candidates);
        $this->assertCount(5, $downloaded);
        $this->assertTrue(collect($downloaded)->every(
            fn (string $url): bool => str_contains($url, 'exact-cdn.example/ah0097nr/'),
        ));
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id,
            'product_url' => $wrongPage,
            'decision' => 'reject_runtime_identifier_mismatch',
        ]);

        foreach ($candidates as $candidate) {
            imagedestroy($candidate['image']);
        }
    }

    public function test_continuation_binds_nested_discovery_attempts_to_the_original_draft(): void
    {
        [, , $draft] = $this->records();
        $continueUpdate = TelegramUpdate::query()->create([
            'update_id' => random_int(100_000, 999_999),
            'telegram_user_id' => '1',
            'chat_id' => '100',
            'payload' => [],
            'status' => 'completed',
        ]);
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('find')
            ->once()
            ->andReturnUsing(function () use ($continueUpdate): array {
                ProductSourceAttempt::query()->create([
                    'telegram_update_id' => $continueUpdate->id,
                    'product_draft_id' => null,
                    'domain' => 'example.com',
                    'product_url' => 'https://example.com/product',
                    'actor' => 'playwright',
                    'phase' => 'gallery_training',
                    'action' => 'inspect_slider',
                    'status' => 'interrupted',
                ]);

                return [];
            });

        $discoverCandidates = new \ReflectionMethod(ProductImageStorage::class, 'discoverCandidates');
        $discoverCandidates->setAccessible(true);
        $discoverCandidates->invoke(
            app(ProductImageStorage::class),
            $draft,
            [],
            true,
            null,
            $continueUpdate->id,
        );

        $this->assertDatabaseHas('product_source_attempts', [
            'telegram_update_id' => $continueUpdate->id,
            'product_draft_id' => $draft->id,
            'actor' => 'playwright',
            'action' => 'inspect_slider',
        ]);
    }

    private function invokeNormalizeCandidateUrl(string $url): string
    {
        $method = new \ReflectionMethod(ProductImageStorage::class, 'normalizeCandidateUrl');
        $method->setAccessible(true);

        return $method->invoke(app(ProductImageStorage::class), $url);
    }

    /** @return array{Product, ProductVariant, ProductDraft} */
    private function records(): array
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'lenovo'], ['name' => 'Lenovo', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => 'lenovo-test',
            'product_type' => 'laptop',
            'status' => 'published',
            'slug' => 'lenovo-test',
            'title' => 'Lenovo Test',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'fingerprint' => 'test-variant',
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);
        $update = TelegramUpdate::query()->create([
            'update_id' => 99001,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'completed',
            'prompt' => 'test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1',
            'title' => 'Lenovo Test',
            'specifications' => [],
            'sources' => [],
            'image_urls' => [
                'https://93.184.216.34/image-1.jpg',
                'https://93.184.216.34/image-2.jpg',
                'https://93.184.216.34/image-3.jpg',
                'https://93.184.216.34/image-4.jpg',
                'https://93.184.216.34/image-5.jpg',
            ],
            'confidence' => 1,
        ]);

        return [$product, $variant, $draft];
    }

    /** Below the production minimum_side (500) - deliberately, to exercise the too_small rejection path. */
    private function tinyJpeg(): string
    {
        $image = imagecreatetruecolor(50, 50);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }

    private function jpeg(int $seed): string
    {
        $small = imagecreatetruecolor(90, 70);
        $white = imagecolorallocate($small, 255, 255, 255);
        imagefill($small, 0, 0, $white);

        for ($index = 0; $index < 18; $index++) {
            $color = imagecolorallocate(
                $small,
                ($index * 47 + $seed * 31) % 255,
                ($index * 83 + $seed * 17) % 255,
                ($index * 29 + $seed * 61) % 255,
            );
            imagefilledrectangle(
                $small,
                ($index * 13 + $seed * 7) % 80,
                ($index * 17 + $seed * 11) % 60,
                min(89, (($index * 13 + $seed * 7) % 80) + 10),
                min(69, (($index * 17 + $seed * 11) % 60) + 10),
                $color,
            );
        }

        // Keep fixtures above the production minimum without retaining dozens of
        // multi-megabyte GD buffers across the complete Windows test process.
        $large = imagescale($small, 720, 600);
        ob_start();
        imagejpeg($large, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($small);
        imagedestroy($large);

        return $jpeg;
    }

    /** Same fixture with a one-pixel change: different bytes, same perceptual hash. */
    private function jpegCopy(int $seed): string
    {
        $image = imagecreatefromstring($this->jpeg($seed));
        $pixel = imagecolorallocate($image, 254, 254, 254);
        imagesetpixel($image, 0, 0, $pixel);

        ob_start();
        imagejpeg($image, null, 91);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }
}
