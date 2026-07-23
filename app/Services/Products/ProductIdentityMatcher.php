<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use Illuminate\Support\Str;

/**
 * Single source of truth for "does this URL identify the exact requested
 * product?" - used both to fast-track trusted-source images past Vision
 * entirely and to relax Vision's acceptance bar when a source is confirmed
 * exact.
 *
 * ProductImageStorage and ProductImageVisionVerifier used to each carry
 * their own slightly different version of this check (different token
 * pools, different exclusion lists, different thresholds), so the same
 * draft+URL pair could get a different answer depending on which class
 * asked.
 */
class ProductIdentityMatcher
{
    /**
     * Union of both former exclusion lists - brand names and generic
     * category/spec words that appear in almost every URL for a brand and
     * so carry no distinguishing signal on their own.
     */
    private const GENERIC_WORDS = [
        'asus', 'acer', 'apple', 'dell', 'hp', 'intel', 'amd', 'lenovo',
        'laptop', 'notebook', 'tablet', 'product', 'processor', 'graphics',
        'memory', 'storage', 'quiet', 'blue', 'card', 'edition',
    ];

    public function supports(ProductDraft $draft, string $sourceUrl): bool
    {
        if ($sourceUrl === '') {
            return false;
        }

        $source = Str::lower(Str::ascii(urldecode($sourceUrl)));
        $tokens = $this->tokens($draft);

        if ($tokens === []) {
            return false;
        }

        // Strong signal: a real model/SKU code (letters+digits mixed, or a
        // distinctive standalone number) is specific enough to trust alone.
        if (collect($tokens)->contains(
            fn (string $token): bool => $this->isStrongToken($token) && str_contains($source, $token)
        )) {
            return true;
        }

        // Weak signal: plain words only count when several independently
        // corroborate each other. Two was too easy to satisfy by accident -
        // an Apple color-swatch banner URL matched "imac" + "pink" alone and
        // got waved past Vision entirely without anyone ever looking at it.
        return collect($tokens)
            ->filter(fn (string $token): bool => str_contains($source, $token))
            ->count() >= 3;
    }

    private function isStrongToken(string $token): bool
    {
        if (strlen($token) < 4) {
            return false;
        }

        $hasLetter = preg_match('/[a-z]/', $token) === 1;
        $hasDigit = preg_match('/\d/', $token) === 1;

        return ($hasLetter && $hasDigit) || ctype_digit($token);
    }

    /** @return array<int, string> */
    private function tokens(ProductDraft $draft): array
    {
        return collect([
            $draft->model,
            $draft->title,
            $draft->color,
            ...collect($draft->specifications ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['key'] ?? null, ['model', 'mpn', 'color'], true))
                ->map(fn (array $item): string => (string) ($item['value'] ?? ''))
                ->all(),
        ])
            ->flatMap(fn (mixed $value): array => preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii((string) $value))) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, self::GENERIC_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }
}
