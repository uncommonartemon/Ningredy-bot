<?php

namespace Tests\Unit;

use App\Services\Products\GalleryOutcome;
use App\Services\Products\GallerySourceResult;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide which of two sets a search carries forward.
 *
 * They used to live in four places as `count($new) > count($old)`, which is the
 * one comparison that gets this wrong: a large set nothing has verified would
 * evict a small one that is ready to publish.
 */
class GallerySourceResultTest extends TestCase
{
    public function test_a_bigger_unverified_set_does_not_evict_a_smaller_verified_one(): void
    {
        $verified = GallerySourceResult::fromVerifiedGallery(
            $this->frames(1),
            $this->frames(1),
            3,
            10,
            ['url' => 'https://shop.example/a'],
            'playwright',
            'notes',
        );
        $unverified = GallerySourceResult::interrupted(
            $this->frames(5),
            10,
            ['url' => 'https://shop.example/b'],
            'playwright',
        );

        $this->assertSame(GalleryOutcome::Partial, $verified->outcome);
        $this->assertSame(GalleryOutcome::Interrupted, $unverified->outcome);
        $this->assertFalse($unverified->isBetterReserveThan($verified));
        $this->assertTrue($verified->isBetterReserveThan($unverified));
    }

    public function test_a_verified_partial_beats_an_interrupted_set_of_the_same_size(): void
    {
        $interrupted = GallerySourceResult::interrupted($this->frames(2), 10, null, 'playwright');
        $partial = GallerySourceResult::fromVerifiedGallery(
            $this->frames(2),
            $this->frames(2),
            5,
            10,
            null,
            'playwright',
            'notes',
        );

        $this->assertTrue($partial->isBetterReserveThan($interrupted));
        $this->assertFalse($interrupted->isBetterReserveThan($partial));
    }

    public function test_a_complete_set_outranks_any_partial_however_large(): void
    {
        $complete = GallerySourceResult::fromVerifiedFrames($this->frames(3), 3, 10, null, 'static');
        $partial = GallerySourceResult::fromVerifiedFrames($this->frames(9), 10, 10, null, 'static');

        $this->assertTrue($complete->isComplete());
        $this->assertSame(GalleryOutcome::Partial, $partial->outcome);
        $this->assertTrue($complete->isBetterReserveThan($partial));
        $this->assertFalse($partial->isBetterReserveThan($complete));
    }

    public function test_size_decides_only_between_sets_that_are_known_equally_well(): void
    {
        $small = GallerySourceResult::fromVerifiedFrames($this->frames(2), 5, 10, null, 'static');
        $larger = GallerySourceResult::fromVerifiedFrames($this->frames(4), 5, 10, null, 'static');

        $this->assertTrue($larger->isBetterReserveThan($small));
        $this->assertFalse($small->isBetterReserveThan($larger));
    }

    public function test_an_empty_result_never_replaces_anything(): void
    {
        $reserve = GallerySourceResult::fromVerifiedFrames($this->frames(1), 5, 10, null, 'static');

        $this->assertFalse(GallerySourceResult::rejected()->isBetterReserveThan($reserve));
        $this->assertTrue($reserve->isBetterReserveThan(null));
        $this->assertFalse(GallerySourceResult::rejected()->isBetterReserveThan(null));
    }

    public function test_a_check_that_could_not_run_cannot_produce_a_verified_set(): void
    {
        // The whole point of the type: null means the check did not happen, and
        // no argument combination turns that into an approval.
        $result = GallerySourceResult::fromVerifiedGallery(
            null,
            $this->frames(8),
            1,
            10,
            null,
            'playwright',
            'notes',
        );

        $this->assertSame(GalleryOutcome::Interrupted, $result->outcome);
        $this->assertSame('language_check_unavailable', $result->reason);
        $this->assertTrue(collect($result->candidates)->every(
            fn (array $frame): bool => $frame['verification_status'] === 'pending',
        ));
    }

    public function test_a_traversal_that_never_finished_is_partial_even_with_enough_frames(): void
    {
        $frames = array_map(fn (array $frame): array => [...$frame, 'partial_gallery' => true], $this->frames(6));

        $result = GallerySourceResult::fromVerifiedGallery($frames, $frames, 3, 10, null, 'playwright', 'notes');

        $this->assertSame(GalleryOutcome::Partial, $result->outcome);
        $this->assertSame('verified_partial_traversal', $result->reason);
    }

    /** @return array<int, array<string, mixed>> */
    private function frames(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index): array => ['source_url' => 'https://cdn.example/'.$index.'.webp'])
            ->all();
    }
}
