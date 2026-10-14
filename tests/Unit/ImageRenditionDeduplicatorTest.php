<?php

namespace Tests\Unit;

use App\Services\Photos\ImageRenditionDeduplicator;
use GdImage;
use PHPUnit\Framework\TestCase;

class ImageRenditionDeduplicatorTest extends TestCase
{
    private array $images = [];

    protected function tearDown(): void
    {
        $this->images = [];
        parent::tearDown();
    }

    public function test_resized_copy_is_removed_and_larger_frame_keeps_the_original_position(): void
    {
        $large = $this->candidate('s2-1.webp', 1000);
        $small = $this->candidate('s2-1-768x768.webp', 768, $large['image']);
        $other = $this->candidate('s3.webp', 1000);
        $this->assertSame([$large, $other], (new ImageRenditionDeduplicator)->unique([$small, $other, $large]));
        $this->assertSame(1000, imagesx($large['image']), 'The deduplicator does not destroy caller-owned images.');
    }

    public function test_similar_angles_with_different_urls_are_kept(): void
    {
        $a = $this->candidate('front.webp', 1000);
        $b = $this->candidate('back.webp', 1000, $a['image']);
        $this->assertCount(2, (new ImageRenditionDeduplicator)->unique([$a, $b]));
    }

    public function test_a_size_looking_name_is_not_proof_when_pixels_are_different(): void
    {
        $a = $this->candidate('front.webp', 1000);
        $b = $this->candidate('front-768x768.webp', 768);
        imagefilledrectangle($b['image'], 50, 50, 650, 650, imagecolorallocate($b['image'], 0, 0, 255));
        $this->assertFalse((new ImageRenditionDeduplicator)->sameFrame($a, $b));
    }

    public function test_frame_selecting_query_parameters_and_page_context_are_preserved(): void
    {
        $a = $this->candidate('front.webp?id=front', 1000);
        $b = $this->candidate('front-768x768.webp?id=back', 768, $a['image']);
        $this->assertFalse((new ImageRenditionDeduplicator)->sameFrame($a, $b));
        $b['source_url'] = str_replace('id=back', 'id=front', $b['source_url']);
        $b['page_source_url'] = 'https://other.example/item';
        $this->assertFalse((new ImageRenditionDeduplicator)->sameFrame($a, $b));
    }

    public function test_url_dimensions_must_match_actual_downloaded_dimensions(): void
    {
        $a = $this->candidate('front.webp', 1000);
        $b = $this->candidate('front-768x768.webp', 500, $a['image']);
        $this->assertFalse((new ImageRenditionDeduplicator)->sameFrame($a, $b));
    }

    private function candidate(string $name, int $size, ?GdImage $original = null): array
    {
        $image = $original ? imagescale($original, $size, $size, IMG_BILINEAR_FIXED) : imagecreatetruecolor($size, $size);
        if (! $original) {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagefilledrectangle($image, (int) ($size / 4), (int) ($size / 4),
                (int) ($size * 3 / 4), (int) ($size * 3 / 4), imagecolorallocate($image, 180, 30, 30));
        }
        $this->images[] = $image;

        return ['image' => $image, 'width' => $size, 'height' => $size,
            'source_url' => 'https://cdn.example/'.$name, 'confirmed_gallery' => true];
    }
}
