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

    public function test_a_product_pushed_to_one_side_of_an_empty_white_frame_is_centred(): void
    {
        // Live #403: the drive on the left of a wide render, more than half of the frame empty.
        $frame = $this->frame(1440, 640, [140, 270, 660, 410]);

        $result = (new ProductImageEncoder)->toWebp($frame);

        // Left margin 140, right 779: the excess goes, and the height and the scale stay.
        $this->assertSame(801, $result['width']);
        $this->assertSame(640, $result['height']);
        $decoded = imagecreatefromstring($result['bytes']);
        $this->assertLessThan(60, imagecolorat($decoded, 400, 340) & 0xFF);
        $this->assertGreaterThan(240, imagecolorat($decoded, 70, 340) & 0xFF);
        $this->assertGreaterThan(240, imagecolorat($decoded, 730, 340) & 0xFF);
    }

    public function test_a_frame_that_is_not_lopsided_on_white_keeps_its_shape(): void
    {
        $encoder = new ProductImageEncoder;
        $keeps = function (array $frame) use ($encoder): void {
            [$width, $height] = [imagesx($frame[0]), imagesy($frame[0])];
            $result = $encoder->toWebp($frame[0]);
            $this->assertSame([$width, $height], [$result['width'], $result['height']], $frame[1]);
        };

        $keeps([$this->frame(1000, 1000, [300, 300, 699, 699]), 'centred']);
        $keeps([$this->frame(1000, 1000, [300, 300, 800, 700]), 'nearly centred']);
        $keeps([$this->frame(300, 200, [10, 10, 60, 60]), 'too small to spare pixels']);
        $keeps([$this->frame(1000, 800), 'nothing in the frame']);
        $keeps([$this->frame(1000, 800, [50, 50, 200, 200], background: [120, 120, 120]), 'a real background']);
    }

    /** @param  array{int, int, int, int}|null  $product */
    private function frame(int $width, int $height, ?array $product = null, array $background = [255, 255, 255]): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$background));
        if ($product !== null) {
            imagefilledrectangle($image, ...[...$product, imagecolorallocate($image, 20, 20, 20)]);
        }

        return $image;
    }
}
