<?php

namespace App\Services\Photos;

use GdImage;

/**
 * Removes proven resized copies, not merely similar gallery angles.
 * Does not own/destroy caller images and never changes global URL identity.
 */
final class ImageRenditionDeduplicator
{
    private const SAMPLE_EDGE = 64;

    public function unique(array $candidates): array
    {
        $kept = [];
        foreach ($candidates as $candidate) {
            foreach ($kept as $index => $previous) {
                if ($this->sameFrame($previous, $candidate)) {
                    if ($this->rank($candidate) > $this->rank($previous)) {
                        $kept[$index] = $candidate;
                    }

                    continue 2;
                }
            }
            $kept[] = $candidate;
        }

        return $kept;
    }

    public function sameFrame(array $first, array $second): bool
    {
        if (! ($first['image'] ?? null) instanceof GdImage || ! ($second['image'] ?? null) instanceof GdImage) {
            return false;
        }
        if (($first['page_source_url'] ?? null) !== ($second['page_source_url'] ?? null)) {
            return false;
        }
        [$firstKey, $firstResized] = $this->renditionKey($first);
        [$secondKey, $secondResized] = $this->renditionKey($second);
        if ($firstKey === '' || $firstKey !== $secondKey || ! ($firstResized || $secondResized)) {
            return false;
        }

        $left = $first['image'];
        $right = $second['image'];
        // Different crops are useful separate views, not resizes.
        if (abs(imagesx($left) / imagesy($left) - imagesx($right) / imagesy($right)) > 0.001) {
            return false;
        }
        $left = $this->sample($left);
        $right = $this->sample($right);
        if (! $left instanceof GdImage || ! $right instanceof GdImage) {
            return false;
        }

        try {
            $sum = $largeDifferences = 0;
            $channels = self::SAMPLE_EDGE * self::SAMPLE_EDGE * 3;
            for ($y = 0; $y < self::SAMPLE_EDGE; $y++) {
                for ($x = 0; $x < self::SAMPLE_EDGE; $x++) {
                    $a = imagecolorat($left, $x, $y);
                    $b = imagecolorat($right, $x, $y);
                    foreach ([0, 8, 16] as $shift) {
                        $difference = abs((($a >> $shift) & 255) - (($b >> $shift) & 255));
                        $sum += $difference;
                        $largeDifferences += $difference > 16 ? 1 : 0;
                    }
                }
            }

            // Small codec/resampling noise is tolerated only AFTER matching
            // rendition URLs and actual dimensions. dHash alone cannot prove
            // this: even solid red and solid blue have the same dHash.
            return $sum / $channels <= 3 && $largeDifferences / $channels <= 0.01;
        } finally {
            unset($left, $right);
        }
    }

    private function sample(GdImage $image): GdImage|false
    {
        $sample = imagecreatetruecolor(self::SAMPLE_EDGE, self::SAMPLE_EDGE);
        if (! $sample instanceof GdImage) {
            return false;
        }
        imagefill($sample, 0, 0, imagecolorallocate($sample, 255, 255, 255));
        // Area resampling avoids false differences at sharp edges caused by
        // taking a few differently aligned bilinear point samples.
        imagecopyresampled($sample, $image, 0, 0, 0, 0, self::SAMPLE_EDGE, self::SAMPLE_EDGE,
            imagesx($image), imagesy($image));

        return $sample;
    }

    /** @return array{string, bool} */
    private function renditionKey(array $candidate): array
    {
        $url = (string) ($candidate['source_url'] ?? '');
        $key = ImageUrls::imageAssetKey($url);
        $pattern = '#-(\d{2,5})x(\d{2,5})(?=\.(?:jpe?g|png|webp|avif)(?:\?|$))#i';
        if (preg_match($pattern, $url, $match) !== 1
            || (int) $match[1] !== (int) ($candidate['width'] ?? imagesx($candidate['image']))
            || (int) $match[2] !== (int) ($candidate['height'] ?? imagesy($candidate['image']))) {
            return [$key, false];
        }

        return [ImageUrls::imageAssetKey(preg_replace($pattern, '', $url)), true];
    }

    private function rank(array $candidate): array
    {
        return [(int) ($candidate['confirmed_gallery'] ?? false), (int) ($candidate['partial_gallery'] ?? false),
            imagesx($candidate['image']) * imagesy($candidate['image'])];
    }
}
