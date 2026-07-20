<?php

namespace Tests\Unit;

use App\Services\Products\ImagePerceptualHash;
use Tests\TestCase;

class ImagePerceptualHashTest extends TestCase
{
    public function test_identical_images_have_zero_distance(): void
    {
        $hash = app(ImagePerceptualHash::class);
        $first = $this->gradient();
        $second = $this->gradient();

        $this->assertSame(0, $hash->distance($hash->hash($first), $hash->hash($second)));

        imagedestroy($first);
        imagedestroy($second);
    }

    public function test_different_images_exceed_the_duplicate_threshold(): void
    {
        $hash = app(ImagePerceptualHash::class);
        $gradient = $this->gradient();
        $reversed = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $color = imagecolorallocate($reversed, (63 - $x) * 4, (63 - $x) * 4, (63 - $x) * 4);
            imagefilledrectangle($reversed, $x, 0, $x, 63, $color);
        }

        $distance = $hash->distance($hash->hash($gradient), $hash->hash($reversed));

        $this->assertGreaterThan(6, $distance);

        imagedestroy($gradient);
        imagedestroy($reversed);
    }

    private function gradient(): \GdImage
    {
        $image = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $color = imagecolorallocate($image, $x * 4, $x * 4, $x * 4);
            imagefilledrectangle($image, $x, 0, $x, 63, $color);
        }

        return $image;
    }
}
