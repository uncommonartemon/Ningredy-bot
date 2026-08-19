<?php

namespace Tests\Unit;

use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use ReflectionProperty;
use Tests\TestCase;

class ProductImageServiceScopeTest extends TestCase
{
    public function test_storage_and_fallback_discovery_share_one_resolver_inside_a_job_scope(): void
    {
        $storage = app(ProductImageStorage::class);
        $discovery = $this->property($storage, 'candidateDiscovery');

        $this->assertInstanceOf(ProductImageCandidateDiscovery::class, $discovery);
        $this->assertSame(
            $this->property($storage, 'resolver'),
            $this->property($discovery, 'resolver'),
            'The confirmed-gallery provenance must survive fallback discovery and reach the downloader.',
        );
        $this->assertSame(
            app(ProductImageResolver::class),
            app(ProductImageResolver::class),
        );
    }

    public function test_resolver_is_discarded_between_job_scopes(): void
    {
        $first = app(ProductImageResolver::class);

        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(ProductImageResolver::class));
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
