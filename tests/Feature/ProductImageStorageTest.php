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
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourceStat;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
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

    public function test_it_keeps_the_default_three_image_limit_for_components(): void
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

        $this->assertSame(3, $product->media()->count());
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

    public function test_it_trusts_the_rest_of_a_confirmed_gallery_after_vision_accepts_one_photo(): void
    {
        // Deliberate trust policy (2026-08-05, explicit user confirmation
        // given the risk that a same-page gallery can contain color-variant
        // thumbnails): once Vision accepts one photo from a Playwright-
        // confirmed gallery, the rest of that same gallery batch is trusted
        // without spending a separate Vision call on each one.
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
        $this->assertSame(1, $visionCalls);
        $this->assertStringContainsString('Trusted without a separate Vision check', (string) $selected[1]['vision_reason']);
        $this->assertStringContainsString('Trusted without a separate Vision check', (string) $selected[2]['vision_reason']);
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
        $this->assertTrue($draft->media->every(
            fn ($media): bool => str_contains($media->source_url, '93.184.216.34/primary-photo-'),
        ));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '93.184.216.35'));
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

    public function test_staging_does_not_open_a_second_source_when_fallback_sources_are_disabled(): void
    {
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', '0');
        AppSetting::put('ai.gallery_browser_mode', 'off');
        ProductImageVisionAgent::fake()->preventStrayPrompts();
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
                    'image_urls' => ['https://93.184.216.34/second-photo.jpg'],
                ],
            ],
        ]);

        $this->assertSame(0, app(ProductImageStorage::class)->stage($draft->fresh()));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/first-card');
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
        $this->mock(ProductImageResolver::class)
            ->shouldReceive('resolve')
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

    /** Below the production minimum_side (450) - deliberately, to exercise the too_small rejection path. */
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
