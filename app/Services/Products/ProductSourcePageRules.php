<?php

namespace App\Services\Products;

use App\Models\ProductSourcePageRule;

class ProductSourcePageRules
{
    public const float MINIMUM_CONFIDENCE = 0.85;

    /** @var array<int, string> */
    private const TERMINAL_PAGE_KINDS = [
        'product_family_landing',
        'editorial_marketing',
        'listing_or_comparison',
        'non_product_page',
    ];

    public function activeRuleFor(string $url): ?ProductSourcePageRule
    {
        $identity = $this->identity($url);

        if ($identity === null) {
            return null;
        }

        return ProductSourcePageRule::query()
            ->where('domain', $identity['domain'])
            ->where('path_hash', $identity['path_hash'])
            ->where('active', true)
            ->first();
    }

    public function rememberUnsuitable(
        string $url,
        string $pageKind,
        string $reason,
        array $evidence,
        float $confidence,
        ?string $layoutFingerprint = null,
    ): ?ProductSourcePageRule {
        $identity = $this->identity($url);
        $evidence = collect($evidence)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => mb_substr(trim($item), 0, 500))
            ->unique()
            ->values()
            ->all();

        if (
            $identity === null
            || ! in_array($pageKind, self::TERMINAL_PAGE_KINDS, true)
            || $confidence < self::MINIMUM_CONFIDENCE
            || count($evidence) < 2
        ) {
            return null;
        }

        $rule = ProductSourcePageRule::query()->updateOrCreate(
            [
                'domain' => $identity['domain'],
                'path_hash' => $identity['path_hash'],
            ],
            [
                'path' => $identity['path'],
                'sample_url' => mb_substr($url, 0, 4000),
                'layout_fingerprint' => $layoutFingerprint,
                'page_kind' => $pageKind,
                'reason' => mb_substr(trim($reason), 0, 4000),
                'evidence' => $evidence,
                'confidence' => min(1, max(0, $confidence)),
                'active' => true,
                'last_hit_at' => now(),
            ],
        );

        if (! $rule->wasRecentlyCreated) {
            $rule->increment('hit_count');
        }

        return $rule->fresh();
    }

    public function recordHit(ProductSourcePageRule $rule): void
    {
        $rule->forceFill(['last_hit_at' => now()])->save();
        $rule->increment('hit_count');
    }

    /** @return array{domain: string, path: string, path_hash: string}|null */
    private function identity(string $url): ?array
    {
        $domain = ProductSourcePriority::host($url);

        if ($domain === '') {
            return null;
        }

        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $path = '/'.ltrim(preg_replace('#/+#', '/', $path) ?: '/', '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return [
            'domain' => $domain,
            'path' => $path,
            'path_hash' => hash('sha256', mb_strtolower($path)),
        ];
    }
}
