<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductIdentityMatcher;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductSourceIdentityJudge;
use App\Services\Products\WikimediaImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncrementalGalleryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_recovery_passes_the_original_request_and_failure_to_discovery(): void
    {
        $update = TelegramUpdate::create(['update_id' => 98121, 'telegram_user_id' => '1',
            'chat_id' => '1', 'payload' => [], 'status' => 'completed', 'text' => 'Orbit Pro 14 16GB 512GB']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake',
            'model' => 'fake', 'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'title' => 'Orbit Pro', 'image_urls' => [],
            'sources' => [], 'specifications' => [], 'gallery_search_stop_reason' => 'specifications_unreconciled']);
        ProductSourceAttempt::create(['product_draft_id' => $draft->id, 'telegram_update_id' => $update->id,
            'product_url' => 'https://first.example/product', 'domain' => 'first.example', 'actor' => 'server', 'phase' => 'card_assembly',
            'action' => 'recover_with_another_source', 'status' => 'interrupted',
            'output' => ['failure_context' => 'Storage capacity not supported by source text', 'saved_file_count' => 3]]);
        $discovery = app(ProductImageCandidateDiscovery::class);
        $prompt = (new \ReflectionMethod($discovery, 'prompt'))->invoke($discovery, $draft, [], ['https://first.example/product'], 1);

        $this->assertStringContainsString('Orbit Pro 14 16GB 512GB', $prompt);
        $this->assertStringContainsString('Storage capacity not supported by source text', $prompt);
        $this->assertStringContainsString('"failed_source_url":"https://first.example/product"', $prompt);
        $this->assertStringContainsString('"saved_photo_count":3', $prompt);
    }

    public function test_an_incompatible_photo_source_is_rejected_before_recipe_execution_or_training(): void
    {
        $url = 'https://shop.example/product/123';
        $draft = new ProductDraft(['model' => 'Stealth 16 AI A2HWGG-009IT', 'specifications' => [
            ['key' => 'mpn', 'value' => '9S7-15F515-009'],
        ]]);
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('preflightSource')->once()->andReturn([
            'final_url' => $url, 'identity_evidence' => 'Stealth 16 AI A2HWGG-008US',
        ]);
        $resolver->shouldNotReceive('resolve');
        $this->mock(ProductPhotoApplicability::class)->shouldReceive('assess')->once()
            ->withArgs(fn ($actualDraft, $source) => $actualDraft === $draft
                && $source['_preflight_identity_evidence'] === 'Stealth 16 AI A2HWGG-008US')
            ->andReturn(['verdict' => 'conflicting', 'may_inspect_gallery' => false, 'reason' => 'Different chassis.']);

        $discovery = app(ProductImageCandidateDiscovery::class);
        $method = new \ReflectionMethod($discovery, 'resolveSourcesIndividually');
        $this->assertSame([], $method->invoke($discovery, [['url' => $url]], $draft));
        $this->assertDatabaseHas('product_source_attempts', [
            'decision' => 'reject_incompatible_photos_before_browser',
        ]);
    }

    public function test_an_unknown_identity_is_still_allowed_to_get_browser_evidence(): void
    {
        $url = 'https://shop.example/product/123';
        $draft = new ProductDraft(['model' => 'Laptop A9', 'specifications' => [['key' => 'sku', 'value' => 'A9-123']]]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'Laptop A9']));
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('preflightSource')->once()->andReturn(['identity_evidence' => 'Laptop A9']);
        $resolver->shouldReceive('resolve')->once()->andReturn([]);
        $this->mock(ProductPhotoApplicability::class)->shouldReceive('assess')->once()
            ->andReturn(['verdict' => 'uncertain', 'may_inspect_gallery' => true, 'reason' => 'Inspect visible chassis.']);
        $discovery = app(ProductImageCandidateDiscovery::class);
        $this->assertSame([], (new \ReflectionMethod($discovery, 'resolveSourcesIndividually'))
            ->invoke($discovery, [['url' => $url, 'title' => 'Laptop A9']], $draft));
    }

    public function test_complete_gallery_yields_before_next_store_and_pending_store_resumes_without_search(): void
    {
        $first = 'https://one.example/product/laptop';
        $second = 'https://two.example/product/laptop';
        $opened = [];
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('preflightSource')->andReturn([]);
        $resolver->shouldReceive('resolve')->twice()->andReturnUsing(function (array $sources) use (&$opened): array {
            $opened[] = $sources[0]['url'];

            return array_map(fn (int $n): string => $sources[0]['url'].'/frame-'.$n.'.jpg', range(1, 3));
        });
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null);
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('find');
        $matcher = $this->mock(ProductIdentityMatcher::class);
        $resolver->shouldReceive('sourceContextForPage')->andReturn(null)->byDefault();
        $matcher->shouldReceive('conflictsSource')->andReturn(false);
        $matcher->shouldReceive('conflictsMemoryConfiguration')->andReturn(false);
        $matcher->shouldReceive('confirmsExactIdentifier')->andReturn(false);
        $matcher->shouldReceive('requiresExactIdentifier')->andReturn(false);
        $this->mock(ProductPhotoApplicability::class)->shouldReceive('assess')->andReturn([
            'verdict' => 'exact', 'may_inspect_gallery' => true, 'reason' => 'Fixture confirmed product.',
        ]);
        $draft = new ProductDraft(['brand' => 'Example', 'model' => 'Laptop',
            'sources' => [['url' => $first], ['url' => $second]]]);
        $discovery = app(ProductImageCandidateDiscovery::class);

        $images = $discovery->find($draft);
        $this->assertCount(3, $images);
        $this->assertSame([$first], $opened);
        // Simulate the caller rejecting the first set after download/Vision.
        $next = $discovery->find($draft, skipKnownSources: true, additionalExcludedSourceUrls: [$first]);
        $this->assertCount(3, $next);
        $this->assertSame([$first, $second], $opened);
        $this->assertSame($second, $discovery->sourcePageForImage($next[0]));
    }

    public function test_confirmed_frames_are_not_cut_to_publication_limit_before_language_filter(): void
    {
        config()->set('product-images.ai_result_limit', 10);
        config()->set('product-images.max_images', 10);
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
        $discovery = app(ProductImageCandidateDiscovery::class);
        $urls = array_map(fn (int $n): string => 'https://cdn.example/frame-'.$n.'.jpg', range(1, 13));
        $property = new \ReflectionProperty($discovery, 'sourcePagesByImageUrl');
        $property->setValue($discovery, array_fill_keys($urls, 'https://shop.example/product/laptop'));
        $method = new \ReflectionMethod($discovery, 'limitCandidateUrlsBySource');
        $this->assertSame($urls, $method->invoke($discovery, $urls));
    }

    public function test_compatible_regional_gallery_survives_both_discovery_checks_with_its_differences(): void
    {
        $url = 'https://shop.example/product/other-region';
        $image = 'https://shop.example/gallery.jpg';
        $draft = new ProductDraft(['model' => 'Laptop A9-IT', 'specifications' => []]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'Laptop A9-IT']));
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('preflightSource')->once()->andReturn(['identity_evidence' => 'Laptop A9-US']);
        $resolver->shouldReceive('resolve')->once()->withArgs(fn ($sources) => $sources[0]['_observed_identity']['purpose'] === 'photos'
            && $sources[0]['_photo_applicability']['verdict'] === 'compatible')->andReturn([$image]);
        $resolver->shouldReceive('sourceContextForPage')->andReturn(['url' => $url, 'title' => 'Laptop A9-US']);
        $resolver->shouldReceive('sourceContextForImage')->andReturnNull();
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judge');
        $this->mock(ProductPhotoApplicability::class)->shouldReceive('assess')->twice()->andReturn([
            'verdict' => 'compatible', 'may_inspect_gallery' => true, 'reason' => 'Same documented chassis.',
            'differences' => ['US keyboard on photos, IT target'], 'evidence' => ['Same chassis and ports'],
        ]);
        $discovery = app(ProductImageCandidateDiscovery::class);
        $images = (new \ReflectionMethod($discovery, 'resolveSourcesIndividually'))->invoke($discovery, [['url' => $url]], $draft);

        $this->assertSame([$image], $images);
        $context = $discovery->sourceContextForImage($image);
        $this->assertSame('photos', $context['_observed_identity']['purpose']);
        $this->assertSame('compatible', $context['_photo_applicability']['verdict']);
        $this->assertSame(['US keyboard on photos, IT target'], $context['_photo_applicability']['differences']);
    }
}
