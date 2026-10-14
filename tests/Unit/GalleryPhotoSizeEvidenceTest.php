<?php

namespace Tests\Unit;

use App\Services\Products\GalleryPhotoSizeEvidence;
use Tests\TestCase;

/**
 * Requests #89 and #91: store.acer.com served eleven of twelve photographs at
 * 500px even at the page's own full-size URL, and two training sessions paid
 * $1.25 looking for renditions that do not exist. The sizes are gathered for
 * the trainer agent; deciding what they mean stays with it.
 */
class GalleryPhotoSizeEvidenceTest extends TestCase
{
    private const string CDN = 'https://cdn.shop.example/media/p/';

    public function test_size_renditions_of_one_photograph_are_gathered_together(): void
    {
        $files = GalleryPhotoSizeEvidence::fromAttempts([
            $this->round(rejected: [$this->stage('02', 500), $this->original('02', 500), $this->thumbnail('02')]),
        ], 700)['files'];

        $this->assertCount(1, $files);
        $photo = array_values($files)[0];
        $this->assertTrue($photo['same_size_under_several_urls']);
        $this->assertFalse($photo['publishable']);
        $this->assertSame(500, $photo['best_width']);
        $this->assertCount(3, $photo['renditions']);
    }

    public function test_a_parameter_that_picks_the_frame_keeps_photographs_apart(): void
    {
        // A reviewer's reproduction: image?id=front and image?id=back were one.
        $files = GalleryPhotoSizeEvidence::fromAttempts([$this->round(rejected: [
            $this->measured('https://cdn.shop.example/image?id=front', 500, 500),
            $this->measured('https://cdn.shop.example/image?id=back', 500, 500),
        ])], 700)['files'];

        $this->assertCount(2, $files);
        $this->assertFalse(collect($files)->contains('same_size_under_several_urls', true));
    }

    public function test_failures_are_not_sizes(): void
    {
        $files = GalleryPhotoSizeEvidence::fromAttempts([[
            'attempt' => 1,
            'diagnostics' => ['rejected_candidates' => [
                ['url' => self::CDN.'photo-04.jpg?height=500', 'reason' => 'decode_failed', 'width' => null, 'height' => null],
            ]],
            'download_probe' => ['frames' => [
                ['url' => self::CDN.'photo-05.jpg', 'status' => 'unavailable', 'reason' => 'download_failed'],
                ['url' => self::CDN.'photo-06.jpg', 'status' => 'too_small', 'width' => 500, 'height' => 500],
            ]],
        ]], 700)['files'];

        $this->assertCount(1, $files, 'only the measured photograph');
    }

    public function test_publishable_urls_are_each_photographs_largest_rendition_in_page_order(): void
    {
        $urls = GalleryPhotoSizeEvidence::publishableUrls([
            $this->round(validated: [$this->zoom('03', 900), $this->original('01', 1000)], rejected: [$this->stage('01', 500)]),
            $this->round(validated: [$this->zoom('03', 1200)]),
        ], 700);

        $this->assertSame([$this->zoom('03', 0)['url'], $this->original('01', 0)['url']], $urls);
    }

    public function test_the_agent_sees_each_photograph_with_every_size_it_measured_and_only_a_hint(): void
    {
        $evidence = GalleryPhotoSizeEvidence::forAgent([
            $this->round(validated: [$this->original('01', 1000)], rejected: [$this->original('02', 500), $this->stage('02', 500)]),
        ], 700);

        $this->assertSame(1, $evidence['publishable_photographs']);
        $this->assertSame(1, $evidence['below_minimum_photographs']);
        $second = collect($evidence['photographs'])->firstWhere('best', '500x500');
        $this->assertTrue($second['same_size_under_several_urls']);
        $this->assertSame(['photo-02.jpg?height=&width= => 500x500', 'photo-02.jpg?height=500&width=500 => 500x500'], $second['measured_as']);
        $this->assertStringContainsString('not proof', $evidence['instruction']);
        $this->assertNull(GalleryPhotoSizeEvidence::forAgent([['attempt' => 1]], 700));
    }

    /** @param array<int, array<string, mixed>> $validated @param array<int, array<string, mixed>> $rejected */
    private function round(array $validated = [], array $rejected = []): array
    {
        return ['attempt' => 1, 'diagnostics' => [
            'validated_image_evidence' => $validated,
            'rejected_candidates' => $rejected,
        ]];
    }

    private function thumbnail(string $frame): array
    {
        return $this->measured(self::CDN."photo-{$frame}.jpg?height=110&width=88", 88, 110);
    }

    private function stage(string $frame, int $width): array
    {
        return $this->measured(self::CDN."photo-{$frame}.jpg?height=500&width=500", $width, $width);
    }

    private function original(string $frame, int $width): array
    {
        return $this->measured(self::CDN."photo-{$frame}.jpg?height=&width=", $width, $width);
    }

    private function zoom(string $frame, int $width): array
    {
        return $this->measured(self::CDN."photo-{$frame}.jpg?zoom=1", $width, $width);
    }

    private function measured(string $url, int $width, int $height): array
    {
        return ['url' => $url, 'reason' => 'dimensions_below_minimum', 'width' => $width, 'height' => $height, 'source' => 'recipe_dom'];
    }
}
