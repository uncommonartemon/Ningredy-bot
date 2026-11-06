<?php

namespace App\Services\Products;

use GdImage;
use RuntimeException;

/**
 * Encodes downloaded GD images into the catalog's storage format and guards
 * against decoding images too large for PHP's memory_limit to hold safely.
 */
class ProductImageEncoder
{
    /** Whiter than this on every channel is empty background. */
    private const int BLANK_FROM = 245;

    /** How much emptier one side must be than the other, as a share of the frame, before it is cut. */
    private const float LOPSIDED_SHARE = 0.25;

    /** A frame smaller than this has no pixels to spare. */
    private const int MIN_EDGE_TO_CENTRE = 400;

    /**
     * GD decodes to an uncompressed RGBA bitmap (width * height * 4 bytes),
     * which can dwarf the source file size and blow past PHP's memory_limit
     * (a 6000x4000 marketing photo alone needs ~96MB just to decode). Reject
     * before imagecreatefromstring() ever allocates that buffer - the final
     * catalog output is capped at 1600px anyway, so nothing this large is
     * ever needed.
     */
    /**
     * Whether a frame can be decoded without putting the process at risk.
     *
     * Raised from twenty million once frames stopped being held at their
     * original size: they are shrunk to the storage edge immediately after
     * decoding, so the full bitmap exists for a moment rather than for the
     * length of a gallery. Forty million covers the 5000x5000 renders real
     * shops serve - Dell turned one away as unsafe while the rest of its
     * gallery was fine - and still costs about 160 MB at the peak, inside the
     * 512 MB the worker runs with.
     */
    public function isSafeToDecode(int $width, int $height): bool
    {
        return $width * $height <= (int) config('product-images.decode_pixel_ceiling', 40_000_000);
    }

    /** @return array{bytes: string, width: int, height: int} */
    public function toWebp(GdImage $image): array
    {
        // imagewebp() refuses a palette (indexed-color) image outright -
        // common for simple diagrams/illustrations, not just photos. Real
        // case: an Apple support diagram was accepted by Vision but silently
        // vanished between "accepted" and "stored" because this threw and got
        // caught upstream. A no-op on an already-truecolor image, so safe to
        // call unconditionally.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $centred = $this->centredOnWhite($image);
        $output = $centred;

        if (imagesx($centred) > 1600 || imagesy($centred) > 1600) {
            $ratio = min(1600 / imagesx($centred), 1600 / imagesy($centred));
            $output = imagescale($centred, (int) round(imagesx($centred) * $ratio), (int) round(imagesy($centred) * $ratio));
        }

        ob_start();
        imagewebp($output, null, 84);
        $encoded = ob_get_clean();

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('GD could not encode the product image as WebP.');
        }

        $result = [
            'bytes' => $encoded,
            'width' => imagesx($output),
            'height' => imagesy($output),
        ];

        if ($output !== $image) {
            imagedestroy($output);
        }

        return $result;
    }

    /**
     * A product pushed to one side of an empty white frame (live #403: a Samsung render with
     * the drive on the left and more than half of the frame blank) is centred by cutting the
     * excess from the emptier side. Nothing else changes: the scale stays, so frames of one
     * gallery still look alike, and a centred frame is left alone. GD's imagecropauto()
     * would crop tight and give no offsets, which is why the margins are measured here.
     */
    private function centredOnWhite(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) < self::MIN_EDGE_TO_CENTRE
            || ! $this->isBlank($image, 0, 0) || ! $this->isBlank($image, $width - 1, 0)
            || ! $this->isBlank($image, 0, $height - 1) || ! $this->isBlank($image, $width - 1, $height - 1)) {
            return $image;
        }
        $margins = $this->blankMargins($image, $width, $height);
        if ($margins === null) {
            return $image;
        }
        [$left, $right, $top, $bottom] = $margins;
        $cutAcross = abs($left - $right) >= $width * self::LOPSIDED_SHARE ? abs($left - $right) : 0;
        $cutDown = abs($top - $bottom) >= $height * self::LOPSIDED_SHARE ? abs($top - $bottom) : 0;
        if ($cutAcross === 0 && $cutDown === 0) {
            return $image;
        }
        $cropped = imagecrop($image, ['x' => $left > $right ? $cutAcross : 0, 'y' => $top > $bottom ? $cutDown : 0,
            'width' => $width - $cutAcross, 'height' => $height - $cutDown]);

        return $cropped === false ? $image : $cropped;
    }

    /**
     * The empty margins of a frame, left, right, top, bottom; null when it is all empty.
     *
     * @return array{int, int, int, int}|null
     */
    private function blankMargins(GdImage $image, int $width, int $height): ?array
    {
        $rowIsBlank = function (int $y) use ($image, $width): bool {
            for ($x = 0; $x < $width; $x += 2) {
                if (! $this->isBlank($image, $x, $y)) {
                    return false;
                }
            }

            return true;
        };
        $columnIsBlank = function (int $x) use ($image, $height): bool {
            for ($y = 0; $y < $height; $y += 2) {
                if (! $this->isBlank($image, $x, $y)) {
                    return false;
                }
            }

            return true;
        };
        $top = 0;
        while ($top < $height && $rowIsBlank($top)) {
            $top++;
        }
        if ($top === $height) {
            return null;
        }
        $bottom = 0;
        while ($bottom < $height && $rowIsBlank($height - 1 - $bottom)) {
            $bottom++;
        }
        $left = 0;
        while ($left < $width && $columnIsBlank($left)) {
            $left++;
        }
        $right = 0;
        while ($right < $width && $columnIsBlank($width - 1 - $right)) {
            $right++;
        }

        return $left === $width || $right === $width ? null : [$left, $right, $top, $bottom];
    }

    private function isBlank(GdImage $image, int $x, int $y): bool
    {
        $color = imagecolorat($image, $x, $y);

        return (($color >> 16) & 0xFF) >= self::BLANK_FROM && (($color >> 8) & 0xFF) >= self::BLANK_FROM && ($color & 0xFF) >= self::BLANK_FROM;
    }
}
