<?php

namespace Tests\Unit;

use App\Services\Products\GalleryDownloadCache;
use App\Services\Products\ProductImageResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GalleryDownloadCacheTest extends TestCase
{
    public function test_bytes_are_scoped_to_exact_url_and_source_and_do_not_carry_trust(): void
    {
        $cache = new GalleryDownloadCache;
        $cache->remember('https://cdn.example/image?id=A', 'https://shop.example/A', [
            'bytes' => 'abc', 'source_url' => 'https://cdn.example/image?id=A',
            'confirmed_gallery' => true, 'partial_gallery' => true,
        ]);
        $hit = $cache->get('https://cdn.example/image?id=A', 'https://shop.example/A', 3);
        $this->assertSame('abc', $hit['bytes']);
        $this->assertArrayNotHasKey('confirmed_gallery', $hit);
        $this->assertNull($cache->get('https://cdn.example/image?id=B', 'https://shop.example/A', 3));
        $this->assertNull($cache->get('https://cdn.example/image?id=A', 'https://shop.example/B', 3));
        $this->assertNull($cache->get('https://cdn.example/image?id=A', 'https://shop.example/A', 2));
    }

    public function test_final_download_reuses_training_bytes_without_a_second_http_request(): void
    {
        Http::preventStrayRequests();
        $url = 'https://93.184.216.34/image.jpg';
        $page = 'https://shop.example/product';
        $resolver = app(ProductImageResolver::class);
        $resolver->rememberTrainingDownload($url, $page, [
            'bytes' => 'already-checked', 'source_url' => $url,
            'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 800,
        ]);
        $this->assertSame('already-checked', $resolver->download($url, refererUrl: $page)['bytes']);
        Http::assertNothingSent();
    }
}
