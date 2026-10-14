<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductIdentityMatcher;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductSourceIdentityJudge;
use App\Services\Products\WikimediaImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiscoveryRecipeReuseQueueTest extends TestCase
{
    use RefreshDatabase;

    private array $opened = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        ProductImageDiscoveryAgent::fake()->preventStrayPrompts();
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('find');
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judge');
        $matcher = $this->mock(ProductIdentityMatcher::class);
        $matcher->shouldReceive('conflictsSource')->andReturn(false);
        $matcher->shouldReceive('confirmsExactIdentifier')->andReturn(true);
    }

    public function test_fallback_tries_the_next_learned_store_before_training_a_failed_replay(): void
    {
        $first = 'https://first.example/products/laptop';
        $second = 'https://second.example/products/laptop';
        $this->recipe('first.example');
        $this->recipe('second.example');
        $this->resolver(fn (string $url): array => $url === $second ? $this->gallery($url) : []);
        $discovery = app(ProductImageCandidateDiscovery::class);

        $images = $discovery->find($this->draft([$first, $second]));

        $this->assertCount(3, $images);
        $this->assertSame([[$first, true], [$second, true]], $this->opened);
        ProductImageDiscoveryAgent::assertNeverPrompted();
    }

    public function test_failed_reuse_pass_returns_to_training_only_after_all_ready_stores(): void
    {
        $first = 'https://first.example/products/laptop';
        $second = 'https://second.example/products/laptop';
        $this->recipe('first.example');
        $this->recipe('second.example');
        $this->resolver(fn (string $url, bool $reuse): array => $url === $second && ! $reuse ? $this->gallery($url) : []);

        $images = app(ProductImageCandidateDiscovery::class)->find($this->draft([$first, $second]));

        $this->assertCount(3, $images);
        $this->assertSame([[$first, true], [$second, true], [$first, false], [$second, false]], $this->opened);
        ProductImageDiscoveryAgent::assertNeverPrompted();
    }

    public function test_rejected_gallery_keeps_an_unopened_page_on_the_same_store(): void
    {
        $first = 'https://shop.example/products/first-card';
        $second = 'https://shop.example/products/second-card';
        $this->resolver(fn (string $url): array => $this->gallery($url));
        $draft = $this->draft([$first, $second]);
        $discovery = app(ProductImageCandidateDiscovery::class);

        $this->assertCount(3, $discovery->find($draft));
        $next = $discovery->find($draft, skipKnownSources: true, additionalExcludedSourceUrls: [$first]);

        $this->assertCount(3, $next);
        $this->assertSame([[$first, false], [$second, false]], $this->opened);
        $this->assertSame($second, $discovery->sourcePageForImage($next[0]));
        ProductImageDiscoveryAgent::assertNeverPrompted();
    }

    public function test_pending_reuse_queue_keeps_its_phase_and_drops_the_rejected_page_retry(): void
    {
        $first = 'https://first.example/products/laptop';
        $second = 'https://second.example/products/laptop';
        $this->recipe('first.example');
        $this->recipe('second.example');
        $this->resolver(fn (string $url, bool $reuse): array => $url === $first || ! $reuse ? $this->gallery($url) : []);
        $draft = $this->draft([$first, $second]);
        $discovery = app(ProductImageCandidateDiscovery::class);

        $this->assertCount(3, $discovery->find($draft));
        $next = $discovery->find($draft, skipKnownSources: true, additionalExcludedSourceUrls: [$first.'/#gallery']);

        $this->assertCount(3, $next);
        $this->assertSame([[$first, true], [$second, true], [$second, false]], $this->opened);
        ProductImageDiscoveryAgent::assertNeverPrompted();
    }

    #[DataProvider('domainExclusions')]
    public function test_explicit_domain_exclusion_still_removes_pending_pages_of_that_store(string $excludedDomain): void
    {
        $first = 'https://shop.example/products/first-card';
        $second = 'https://shop.example/products/second-card';
        $third = 'https://other.example/products/laptop';
        $this->resolver(fn (string $url): array => $this->gallery($url));
        $draft = $this->draft([$first, $second, $third]);
        $discovery = app(ProductImageCandidateDiscovery::class);

        $this->assertCount(3, $discovery->find($draft));
        $next = $discovery->find($draft, skipKnownSources: true, additionalExcludedSourceUrls: [$excludedDomain]);

        $this->assertCount(3, $next);
        $this->assertSame([[$first, false], [$third, false]], $this->opened);
        ProductImageDiscoveryAgent::assertNeverPrompted();
    }

    public static function domainExclusions(): array
    {
        return [
            'domain URL' => ['https://shop.example'],
            'bare hostname' => ['shop.example'],
        ];
    }

    public function test_a_new_domain_ban_is_honored_when_resuming_pending_pages(): void
    {
        $first = 'https://shop.example/products/first-card';
        $second = 'https://shop.example/products/second-card';
        $third = 'https://other.example/products/laptop';
        $this->resolver(fn (string $url): array => $this->gallery($url));
        $draft = $this->draft([$first, $second, $third]);
        $discovery = app(ProductImageCandidateDiscovery::class);

        $this->assertCount(3, $discovery->find($draft));
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example', 'path_pattern' => '*', 'status' => 'disabled', 'source_blocked' => true,
        ]);
        $next = $discovery->find($draft, skipKnownSources: true, additionalExcludedSourceUrls: [$first]);

        $this->assertCount(3, $next);
        $this->assertSame([[$first, false], [$third, false]], $this->opened);
        ProductImageDiscoveryAgent::assertNeverPrompted();
    }

    public function test_vision_first_still_visits_every_source_once_without_training(): void
    {
        Category::query()->create(['name' => 'Components', 'slug' => 'reuse-components',
            'gallery_search_strategy' => Category::GALLERY_SEARCH_VISION_FIRST]);
        $first = 'https://first.example/products/laptop';
        $second = 'https://second.example/products/laptop';
        $this->recipe('first.example');
        $this->recipe('second.example');
        $this->resolver(fn (): array => []);
        $draft = $this->draft([$first, $second]);
        $draft->category = 'reuse-components';
        $discovery = app(ProductImageCandidateDiscovery::class);

        $this->assertSame([], (new \ReflectionMethod($discovery, 'resolveSourcesIndividually'))
            ->invoke($discovery, $draft->sources, $draft));
        $this->assertSame([[$first, true], [$second, true]], $this->opened);
        ProductImageDiscoveryAgent::assertNeverPrompted();
    }

    private function resolver(callable $result): void
    {
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('preflightSource')->andReturn([]);
        $resolver->shouldReceive('resolve')->andReturnUsing(function (...$arguments) use ($result): array {
            $url = $arguments[0][0]['url'];
            $reuse = $arguments['activeRecipeOnly'] ?? $arguments[6] ?? false;
            $this->opened[] = [$url, $reuse];

            return $result($url, $reuse);
        });
        $resolver->shouldReceive('sourceContextForPage')->andReturnUsing(fn (string $url): array => ['url' => $url]);
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null);
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
    }

    private function draft(array $urls): ProductDraft
    {
        return new ProductDraft(['brand' => 'Example', 'model' => 'Laptop',
            'sources' => array_map(fn (string $url): array => ['url' => $url, 'type' => 'retailer'], $urls)]);
    }

    private function recipe(string $domain): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => $domain, 'path_pattern' => '/products/*', 'status' => 'active', 'success_count' => 3,
            'recipe' => ['collect_selectors' => ['.gallery img']],
        ]);
    }

    private function gallery(string $url): array
    {
        return array_map(fn (int $number): string => $url.'/frame-'.$number.'.jpg', range(1, 3));
    }
}
