<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

class ProductSearchIntentDetector
{
    public function isStandaloneProductQuery(?string $message): bool
    {
        $text = Str::lower(Str::ascii(trim((string) $message)));

        if ($text === '' || preg_match('/\b(chto takoe|kak|pochemu|zachem|what is|how|why)\b/', $text)) {
            return false;
        }

        if (preg_match('/\b(foto|photo|draft|chernovik|udal|delete|replace|zameni|perestav|upscale|uluchshi)\w*/', $text)) {
            return false;
        }

        if (preg_match('/\b(najdi|naydi|ischi|ishi|search|find)\b/', $text)) {
            return true;
        }

        $signals = 0;
        $signals += preg_match('/\b(acer|adata|amd|apple|asus|dell|hp|intel|lenovo|msi|nvidia|samsung|corsair|kingston|patriot)\b/', $text) ? 1 : 0;
        $signals += preg_match('/\b(laptop|notebook|nitro|vivobook|vector|loq|rog|pro\s+max|premium|core|ryzen|rtx|radeon|ddr[345]?|so-?dimm|rdimm|ecc|ssd|qvo|lga|ram)\b/', $text) ? 1 : 0;
        $signals += preg_match('/\b\d+(?:[.,]\d+)?\s*(?:gb|tb|mhz|ghz|hz|inch|inches|w|c|t)\b/', $text) ? 1 : 0;
        $signals += preg_match('/\b(?=[a-z0-9_-]{4,}\b)(?=[a-z0-9_-]*[a-z])(?=[a-z0-9_-]*\d)[a-z0-9_-]+\b/', $text) ? 1 : 0;

        return $signals >= 2;
    }
}
