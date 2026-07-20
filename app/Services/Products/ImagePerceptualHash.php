<?php

namespace App\Services\Products;

use GdImage;
use RuntimeException;

class ImagePerceptualHash
{
    private const WIDTH = 9;

    private const HEIGHT = 8;

    /** 64-bit dHash as 16 lowercase hex characters. */
    public function hash(GdImage $image): string
    {
        $small = imagescale($image, self::WIDTH, self::HEIGHT);

        if (! $small instanceof GdImage) {
            throw new RuntimeException('Could not scale the image for hashing.');
        }

        $hex = '';

        try {
            for ($y = 0; $y < self::HEIGHT; $y++) {
                $nibble = 0;

                for ($x = 0; $x < self::WIDTH - 1; $x++) {
                    $nibble = ($nibble << 1)
                        | ($this->luminance($small, $x, $y) > $this->luminance($small, $x + 1, $y) ? 1 : 0);

                    if (($x + 1) % 4 === 0) {
                        $hex .= dechex($nibble);
                        $nibble = 0;
                    }
                }
            }
        } finally {
            imagedestroy($small);
        }

        return $hex;
    }

    /** Hamming distance between two hashes produced by {@see hash()}. */
    public function distance(string $first, string $second): int
    {
        $distance = 0;

        for ($index = 0, $length = min(strlen($first), strlen($second)); $index < $length; $index++) {
            $distance += substr_count(decbin(hexdec($first[$index]) ^ hexdec($second[$index])), '1');
        }

        return $distance;
    }

    private function luminance(GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }
}
