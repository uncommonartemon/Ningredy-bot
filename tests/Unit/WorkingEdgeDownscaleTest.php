<?php

namespace Tests\Unit;

use App\Services\Products\ProductImageStorage;
use GdImage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Live on dell.com, 2026-09-06: a trained recipe collected thirteen frames, the
 * downloader kept six, and twenty-eight candidates were never even tried. The
 * reason was neither the photographs nor the recipe - each frame was decoded at
 * its original size, a few of those emptied the sixty-million-pixel budget, and
 * the loop broke. Every one of them was going to be scaled to 1600px on the way
 * to disk anyway.
 */
class WorkingEdgeDownscaleTest extends TestCase
{
    public function test_a_large_frame_is_held_at_the_edge_it_will_be_stored_at(): void
    {
        $image = imagecreatetruecolor(5000, 4000);

        $held = $this->downscale($image);

        $this->assertLessThanOrEqual(1600, imagesx($held));
        $this->assertLessThanOrEqual(1600, imagesy($held));
        // 20 million pixels down to under three: the budget now holds a whole
        // gallery instead of three photographs.
        $this->assertLessThan(3_000_000, imagesx($held) * imagesy($held));
    }

    public function test_the_shape_of_the_photograph_is_kept(): void
    {
        $held = $this->downscale(imagecreatetruecolor(4000, 2000));

        $this->assertSame(1600, imagesx($held));
        $this->assertSame(800, imagesy($held), 'A 2:1 photograph must not come back square.');
    }

    public function test_a_frame_already_small_enough_is_left_alone(): void
    {
        $image = imagecreatetruecolor(1200, 900);

        $held = $this->downscale($image);

        $this->assertSame(1200, imagesx($held));
        $this->assertSame(900, imagesy($held));
    }

    private function downscale(GdImage $image): GdImage
    {
        $method = new ReflectionMethod(ProductImageStorage::class, 'downscaleForWork');

        return $method->invoke(app(ProductImageStorage::class), $image);
    }
}
