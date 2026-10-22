<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DownloadedGalleryRenditionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_gallery_resizes_are_deduplicated_before_counting_or_vision(): void
    {
        Http::preventStrayRequests();
        AppSetting::put('ai.image_minimum_width', '500');
        AppSetting::put('ai.image_minimum_height', '0');
        $largeUrl = 'https://93.184.216.34/s2-1.webp';
        $smallUrl = 'https://93.184.216.34/s2-1-600x600.webp';
        $large = imagecreatetruecolor(800, 800);
        imagefill($large, 0, 0, imagecolorallocate($large, 255, 255, 255));
        imagefilledrectangle($large, 200, 200, 600, 600, imagecolorallocate($large, 180, 30, 30));
        $small = imagescale($large, 600, 600, IMG_BILINEAR_FIXED);
        $downloads = [];
        foreach ([$largeUrl => $large, $smallUrl => $small] as $url => $image) {
            ob_start();
            imagewebp($image, null, 90);
            $downloads[$url] = ['source_url' => $url, 'bytes' => ob_get_clean(), 'mime_type' => 'image/webp',
                'width' => imagesx($image), 'height' => imagesy($image), 'confirmed_gallery' => true];
        }
        $resolver = $this->mock(ProductImageResolver::class);
        $this->downloadManyThroughDownload($resolver);
        $resolver->shouldReceive('download')->twice()->andReturnUsing(fn (string $url) => $downloads[$url]);
        $resolver->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
        $resolver->shouldReceive('isPartialGalleryImage')->andReturn(false);
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null);
        $discovery = $this->mock(ProductImageCandidateDiscovery::class);
        $discovery->shouldReceive('sourceContextForImage', 'sourcePageForImage')->andReturn(null);
        $storage = app(ProductImageStorage::class);
        $result = (new \ReflectionMethod($storage, 'downloadCandidates'))->invoke(
            $storage, [$smallUrl, $largeUrl], new ProductDraft(['title' => 'Example', 'category' => 'components']),
        );
        $this->assertCount(1, $result);
        $this->assertSame($largeUrl, $result[0]['source_url']);
        $this->assertTrue($result[0]['confirmed_gallery']);
        Http::assertNothingSent();
    }
}
