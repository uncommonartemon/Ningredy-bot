<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Models\AiRun;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\WikimediaImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_it_accepts_a_publishable_image_when_the_exact_model_is_supported_by_its_source(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => false,
                'publishable' => true,
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

    public function test_it_reviews_and_selects_an_official_manufacturer_image_first(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(function (string $prompt, $attachments): array {
            $this->assertStringContainsString(
                '#1 [OFFICIAL MANUFACTURER] source: https://www.lenovo.com/catalog/hero.jpg',
                $prompt,
            );

            return [
                'images' => $attachments->keys()->map(fn (int $index): array => [
                    'index' => $index + 1,
                    'exact_match' => false,
                    'publishable' => $index === 0,
                    'kind' => $index === 0 ? 'product' : 'unrelated',
                    'view' => $index === 0 ? 'front' : 'other',
                    'gallery_rank' => $index + 1,
                    'score' => $index === 0 ? 55 : 5,
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
        $this->assertSame('https://www.lenovo.com/catalog/hero.jpg', $media->source_url);
        $this->assertStringContainsString('Official manufacturer source.', (string) $media->verification_notes);
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

    public function test_it_trusts_the_models_gallery_rank_over_source_priority(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (): array => [
            'images' => [
                [
                    'index' => 1,
                    'exact_match' => true,
                    'publishable' => true,
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
                    'kind' => $index === 0 ? 'product' : 'detail',
                    'view' => $index === 0 ? 'front' : 'detail',
                    'gallery_rank' => $index + 1,
                    'score' => 96 - $index,
                    'reason' => 'Exact publishable product view.',
                ])->all(),
            ];
        })->preventStrayPrompts();
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
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

    public function test_it_uses_fallback_discovery_when_research_only_returns_logos(): void
    {
        Storage::fake('public');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => [[
                'index' => 1,
                'exact_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => 1,
                'score' => 96,
                'reason' => 'Exact physical product.',
            ]],
        ])->preventStrayPrompts();
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
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

    public function test_it_drops_near_duplicate_images_selected_by_vision(): void
    {
        Storage::fake('public');
        config()->set('product-images.discover_after_rejection', false);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => true,
                'publishable' => true,
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

    public function test_image_discovery_is_cached_for_queue_retries(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]]),
        ]);
        ProductImageDiscoveryAgent::fake([[
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
        $large = imagescale($small, 450, 350);
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
