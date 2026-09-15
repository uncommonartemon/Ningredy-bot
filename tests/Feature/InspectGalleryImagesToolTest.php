<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryVisionAgent;
use App\Ai\Tools\InspectGalleryImages;
use App\Models\AiRun;
use App\Services\Products\ProductImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Mockery\MockInterface;
use Tests\TestCase;

class InspectGalleryImagesToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_url_that_was_not_present_in_browser_evidence(): void
    {
        ProductGalleryVisionAgent::fake()->preventStrayPrompts();
        $this->mock(ProductImageResolver::class, fn (MockInterface $mock) => $mock->shouldNotReceive('download'));

        $result = json_decode((string) (new InspectGalleryImages([
            'https://cdn.example/observed.jpg',
        ]))->handle(new ToolRequest([
            'image_urls' => ['https://cdn.example/invented.jpg'],
            'product_context' => 'Example laptop SKU-1',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('current sanitized page', $result['error']);
    }

    public function test_it_returns_observations_without_publish_or_reject_indices(): void
    {
        $url = 'https://cdn.example/side-view.jpg';
        $this->mock(ProductImageResolver::class, function (MockInterface $mock) use ($url): void {
            $mock->shouldReceive('download')->once()->withArgs(fn (string $value): bool => $value === $url)
                ->andReturn([
                    'bytes' => $this->jpeg(),
                    'source_url' => $url,
                    'mime_type' => 'image/jpeg',
                    'width' => 720,
                    'height' => 64,
                    'confirmed_gallery' => false,
                    'partial_gallery' => false,
                ]);
        });
        ProductGalleryVisionAgent::fake(function (string $prompt, $attachments): array {
            $this->assertCount(1, $attachments);
            $this->assertStringContainsString('exact SKU must be proven from page evidence', $prompt);

            return [
                'coherent_single_product_gallery' => true,
                'needs_more_evidence' => false,
                'images' => [[
                    'index' => 1,
                    'product_visible' => true,
                    'visually_consistent' => true,
                    'view' => 'side',
                    'prominent_text_language' => 'none',
                    'visible_conflict' => false,
                    'confidence' => 0.93,
                    'observation' => 'A narrow side profile with ports remains visibly product-bearing.',
                ]],
                'summary' => 'The supplied frame is a useful side detail.',
            ];
        })->preventStrayPrompts();

        $result = json_decode((string) (new InspectGalleryImages([$url]))->handle(new ToolRequest([
            'image_urls' => [$url],
            'product_context' => 'Example laptop SKU-1',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['observation']['coherent_single_product_gallery']);
        $this->assertSame('side', $result['observation']['images'][0]['view']);
        $this->assertArrayNotHasKey('publishable', $result['observation']['images'][0]);
        $this->assertArrayNotHasKey('accepted_indices', $result['observation']);
        $this->assertSame('completed', AiRun::query()->latest('id')->firstOrFail()->status);
    }

    public function test_an_avif_file_served_under_a_png_url_is_converted_before_vision_sees_it(): void
    {
        // Real production case (2026-09-10): a Lenovo gallery URL ended in
        // .png but the server actually returned AVIF bytes. The tool used to
        // hand those bytes to the vision provider unconverted; the provider
        // rejected every one of six identical rounds as "not a valid image",
        // and the agent was told only that Vision was unavailable.
        $url = 'https://cdn.example/gallery/frame-1.png';
        $this->mock(ProductImageResolver::class, function (MockInterface $mock) use ($url): void {
            $mock->shouldReceive('download')->once()->withArgs(fn (string $value): bool => $value === $url)
                ->andReturn([
                    'bytes' => $this->avif(),
                    'source_url' => $url,
                    'mime_type' => 'image/avif',
                    'width' => 32,
                    'height' => 16,
                    'confirmed_gallery' => false,
                    'partial_gallery' => false,
                ]);
        });
        ProductGalleryVisionAgent::fake(function (string $prompt, $attachments): array {
            $this->assertCount(1, $attachments);
            // The provider only ever sees the re-encoded bytes; whatever
            // format the source actually served is the tool's problem, not
            // the caller's - and it is never one Vision would refuse.
            $this->assertSame('image/webp', $attachments[0]->mimeType());

            return [
                'coherent_single_product_gallery' => true,
                'needs_more_evidence' => false,
                'images' => [[
                    'index' => 1,
                    'product_visible' => true,
                    'visually_consistent' => true,
                    'view' => 'front',
                    'prominent_text_language' => 'none',
                    'visible_conflict' => false,
                    'confidence' => 0.9,
                    'observation' => 'A clear front view of the product.',
                ]],
                'summary' => 'The converted frame is usable.',
            ];
        })->preventStrayPrompts();

        $result = json_decode((string) (new InspectGalleryImages([$url]))->handle(new ToolRequest([
            'image_urls' => [$url],
            'product_context' => 'Example laptop SKU-1',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $this->assertSame([$url], $result['inspected_urls']);
        $this->assertSame([], $result['download_errors']);
    }

    public function test_a_format_vision_cannot_accept_and_gd_cannot_decode_is_reported_as_not_retryable(): void
    {
        $url = 'https://cdn.example/gallery/corrupt.png';
        $this->mock(ProductImageResolver::class, function (MockInterface $mock) use ($url): void {
            $mock->shouldReceive('download')->once()->andReturn([
                'bytes' => 'not actually an image, no known signature',
                'source_url' => $url,
                'mime_type' => 'application/octet-stream',
                'width' => 0,
                'height' => 0,
                'confirmed_gallery' => false,
                'partial_gallery' => false,
            ]);
        });
        ProductGalleryVisionAgent::fake()->preventStrayPrompts();

        $result = json_decode((string) (new InspectGalleryImages([$url]))->handle(new ToolRequest([
            'image_urls' => [$url],
            'product_context' => 'Example laptop SKU-1',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['downloads']);
        $this->assertSame($url, $result['downloads'][0]['url']);
        $this->assertSame('unsupported_image_format', $result['downloads'][0]['reason']);
        $this->assertFalse($result['downloads'][0]['retryable']);
        $this->assertStringContainsString('do not resubmit', $result['downloads'][0]['note']);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(32, 16);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }

    private function avif(): string
    {
        $image = imagecreatetruecolor(32, 16);
        $blue = imagecolorallocate($image, 40, 90, 140);
        imagefill($image, 0, 0, $blue);
        ob_start();
        imageavif($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
