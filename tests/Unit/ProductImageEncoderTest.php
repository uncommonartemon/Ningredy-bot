<?php

namespace Tests\Unit;

use App\Services\Products\ProductImageEncoder;
use Tests\TestCase;

class ProductImageEncoderTest extends TestCase
{
    public function test_it_encodes_a_palette_image_that_gd_cannot_webp_encode_directly(): void
    {
        // Real production bug (2026-08-26): an Apple support-page diagram
        // (a small indexed-color/"palette" PNG, common for simple
        // illustrations, not just photos) was accepted by Vision but never
        // reached the draft - imagewebp() throws "Palette image not
        // supported by webp" on a palette image, and that exception was
        // silently swallowed by the caller, so the image just vanished
        // between "accepted" and "stored" with no visible error to the
        // operator.
        $palette = imagecreate(100, 60);
        $background = imagecolorallocate($palette, 255, 255, 255);
        $foreground = imagecolorallocate($palette, 10, 20, 30);
        imagefilledrectangle($palette, 10, 10, 50, 40, $foreground);
        $this->assertFalse(imageistruecolor($palette));

        $encoder = new ProductImageEncoder;
        $result = $encoder->toWebp($palette);

        $this->assertNotSame('', $result['bytes']);
        $this->assertSame(100, $result['width']);
        $this->assertSame(60, $result['height']);

        imagedestroy($palette);
    }

    public function test_it_encodes_an_already_truecolor_image_unchanged(): void
    {
        $truecolor = imagecreatetruecolor(100, 60);
        $color = imagecolorallocate($truecolor, 10, 20, 30);
        imagefilledrectangle($truecolor, 0, 0, 99, 59, $color);

        $encoder = new ProductImageEncoder;
        $result = $encoder->toWebp($truecolor);

        $this->assertNotSame('', $result['bytes']);
        $this->assertSame(100, $result['width']);
        $this->assertSame(60, $result['height']);

        imagedestroy($truecolor);
    }
}
