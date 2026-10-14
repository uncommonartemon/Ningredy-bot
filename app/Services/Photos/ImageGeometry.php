<?php

namespace App\Services\Photos;

use GdImage;

/** Pixel-level rules. Product identity, source choice and Vision are separate concerns. */
class ImageGeometry
{
    /**
     * Shrink a decoded frame to the size it will be published at.
     *
     * Everything past this is a thumbnail for Vision, a perceptual hash, or a
     * WebP the encoder caps at the same edge - none of them need the original
     * pixels, and holding them is what emptied the memory budget three frames
     * into a gallery.
     */
    public function downscaleForWork(GdImage $image): GdImage
    {
        $edge = (int) config('product-images.working_edge', 1600);
        $width = imagesx($image);
        $height = imagesy($image);

        if ($edge < 1 || ($width <= $edge && $height <= $edge)) {
            return $image;
        }

        $ratio = min($edge / $width, $edge / $height);
        $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));

        if (! $scaled instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $scaled;
    }

    public function hasUsefulDimensions(
        int $width,
        int $height,
        int $requiredWidth,
        int $requiredHeight,
    ): bool {
        return $this->dimensionRejectionReason($width, $height, $requiredWidth, $requiredHeight) === null;
    }

    /**
     * Two independent bounds share this gate - a minimum width/height and an
     * aspect-ratio range - but they used to collapse into a single "too_small"
     * reason string even when width/height both passed and only the ratio
     * bound failed (e.g. a 720x64 edge/port close-up: 720 clears the width
     * floor, but ratio 11.25 blows past the 3.5 max). Naming the ratio
     * rejection separately keeps rejected_candidates honest about which
     * bound actually fired.
     */
    public function dimensionRejectionReason(
        int $width,
        int $height,
        int $requiredWidth,
        int $requiredHeight,
        bool $allowExtremeAspectRatio = false,
    ): ?string {
        if ($width < $requiredWidth || $height < $requiredHeight) {
            return $requiredHeight > 0
                ? "too_small ({$width}x{$height}; required {$requiredWidth}x{$requiredHeight})"
                : "too_small ({$width}x{$height}; required width>={$requiredWidth}, height=any)";
        }

        if ($allowExtremeAspectRatio) {
            return null;
        }

        $ratio = $width / max($height, 1);
        $minRatio = (float) config('product-images.minimum_ratio', 0.28);
        $maxRatio = (float) config('product-images.maximum_ratio', 3.5);

        if ($ratio < $minRatio || $ratio > $maxRatio) {
            return sprintf(
                'bad_aspect_ratio (%dx%d; ratio %.2f; required %.2f-%.2f)',
                $width,
                $height,
                $ratio,
                $minRatio,
                $maxRatio,
            );
        }

        return null;
    }

    /**
     * Trusted-source candidates skip Vision entirely, so unlike the loose
     * bounds in hasUsefulDimensions() (which Vision still reviews), this is
     * the last line of defense against banner/swatch-strip images from
     * manufacturer spec pages (e.g. a 738x270 color-swatch banner, ratio
     * 2.73 - well inside the general 0.28-3.5 bound but not a product shot).
     */
    public function looksLikeProductPhotoShape(int $width, int $height): bool
    {
        $ratio = $width / max($height, 1);

        return $ratio >= 0.5 && $ratio <= 2.2;
    }
}
