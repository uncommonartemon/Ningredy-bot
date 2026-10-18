<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

class ProductSearchIntentDetector
{
    /** A caption must ask for search explicitly; a model/specification list is upload material. */
    public function hasExplicitSearchRequest(?string $message): bool
    {
        $text = mb_strtolower(trim((string) $message));
        if ($text === '' || preg_match('/^\/add(?:@\w+)?(?:\s|$)/u', $text)
            || preg_match('/\b(?:не\s+(?:(?:надо|нужно|треба)\s+)?(?:ищи\w*|искать|шукай\w*|шукати)|без\s+(?:поиск\w*|пошук\w*|интернет\w*|інтернет\w*)|(?:do\s+not|don[\x{2019}\x{0027}]?t|without|no)\s+(?:search\w*|research\w*|internet|web|look\s+up))\b/u', $text)) {
            return false;
        }

        if (preg_match('/^\/(?:find|search)(?:@\w+)?(?:\s|$)/u', $text)
            || preg_match('/^(?:поиск|пошук)\b/u', $text)) {
            return true;
        }

        // "Find" is also part of phone model names (OPPO Find X7). Require an
        // imperative clause for English instead of matching arbitrary names.
        if (preg_match('/(?:^|[.!?;,\n]\s*|\b(?:please|can you|could you|would you|and|then)\s+)(?:search|find|research|look\s+up)\b/u', $text)
            || preg_match('/\b(?:найди(?:те)?|найти|поищи(?:те)?|ищи(?:те)?|искать|знайди|знайдіть|знайти|пошукай(?:те)?|шукай(?:те)?|шукати)\b/u', $text)
            || preg_match('/\b(?:запусти(?:те)?|начни(?:те)?|через|веб)\s*[- ]?\s*(?:поиск|пошук)\b/u', $text)) {
            return true;
        }

        return preg_match('/\b(?:дополни(?:ть|те)?|доповни(?:ти|ть)?|заполни(?:ть|те)?|enrich|complete)\b/u', $text) === 1
            && preg_match('/\b(?:интернет\w*|інтернет\w*|web|online)\b/u', $text) === 1;
    }

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
