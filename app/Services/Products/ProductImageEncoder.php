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
        $output = $image;

        if (imagesx($image) > 1600 || imagesy($image) > 1600) {
            $ratio = min(1600 / imagesx($image), 1600 / imagesy($image));
            $output = imagescale($image, (int) round(imagesx($image) * $ratio), (int) round(imagesy($image) * $ratio));
        }

        // imagewebp() refuses a palette (indexed-color) image outright -
        // common for simple diagrams/illustrations, not just photos - and
        // imagescale() above only runs for oversized images, so a
        // small palette PNG reaches here unconverted. Real case: an Apple
        // support diagram was accepted by Vision but silently vanished
        // between "accepted" and "stored" because this threw and got caught
        // upstream. A no-op on an already-truecolor image, so safe to call
        // unconditionally.
        if (! imageistruecolor($output)) {
            imagepalettetotruecolor($output);
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
}
