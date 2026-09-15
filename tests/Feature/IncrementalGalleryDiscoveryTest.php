<?php

namespace Tests\Feature;

use App\Models\ProductDraft;
use App\Services\Products\ProductIdentityMatcher;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\WikimediaImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncrementalGalleryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

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
        $matcher->shouldReceive('conflictsSource')->andReturn(false);
        $matcher->shouldReceive('requiresExactIdentifier')->andReturn(false);
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
}
