<?php

namespace Tests\Feature;

use App\Services\Photos\CandidateUrlOrdering;
use App\Services\Playwright\BrowserRunner;
use App\Services\Playwright\PlaywrightRunner;
use App\Services\Products\BrowserProductImageTransferStore;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\ProductSearch\SourceQueueBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

class PhotoModuleCompositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_and_photo_ordering_share_the_same_search_provenance(): void
    {
        $resolver = app(ProductImageResolver::class);
        foreach ([app(SourceQueueBuilder::class), app(CandidateUrlOrdering::class), app(ProductImageStorage::class)] as $consumer) {
            $this->assertSame($resolver, (new ReflectionProperty($consumer, 'resolver'))->getValue($consumer));
        }
    }

    public function test_browser_runners_share_bytes_only_inside_one_queue_job_scope(): void
    {
        $first = app(BrowserRunner::class);
        $second = app(BrowserRunner::class);
        $this->assertInstanceOf(PlaywrightRunner::class, $first);
        $this->assertNotSame($first, $second);
        $transfers = (new ReflectionProperty($first, 'transfers'))->getValue($first);
        $this->assertSame(app(BrowserProductImageTransferStore::class), $transfers);
        $this->assertSame($transfers, (new ReflectionProperty($second, 'transfers'))->getValue($second));

        $resolver = app(ProductImageResolver::class);
        $this->app->forgetScopedInstances();
        $next = app(BrowserRunner::class);
        $this->assertNotSame($transfers, (new ReflectionProperty($next, 'transfers'))->getValue($next));
        $this->assertNotSame($resolver, app(ProductImageResolver::class));
    }
}
