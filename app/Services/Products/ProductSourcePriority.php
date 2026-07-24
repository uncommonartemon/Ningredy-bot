<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

class ProductSourcePriority
{
    /** @param array<int, mixed> $sources @return array<int, array<string, mixed>> */
    public function sortSources(array $sources, ?string $brand): array
    {
        return collect($sources)
            ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['url'] ?? null))
            ->values()
            ->map(fn (array $source, int $index): array => [
                'source' => $source,
                'index' => $index,
                'score' => $this->score($source['url'], $brand, $sources, $source['type'] ?? null),
            ])
            ->sort(fn (array $left, array $right): int =>
                ($right['score'] <=> $left['score']) ?: ($left['index'] <=> $right['index']))
            ->pluck('source')
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $urls @param array<int, mixed> $sources @return array<int, string> */
    public function sortUrls(array $urls, ?string $brand, array $sources = []): array
    {
        return collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->map(fn (string $url, int $index): array => [
                'url' => $url,
                'index' => $index,
                'score' => $this->score($url, $brand, $sources),
            ])
            ->sort(fn (array $left, array $right): int =>
                ($right['score'] <=> $left['score']) ?: ($left['index'] <=> $right['index']))
            ->pluck('url')
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $sources */
    public function classify(string $url, ?string $brand, array $sources = []): string
    {
        if ($this->isOfficial($url, $brand, $sources)) {
            return $this->isEnglishOrUs($url) ? 'official_english' : 'official_localized';
        }

        if ($this->isAmazon($url)) {
            return 'amazon';
        }

        if ($this->isTrustedRetailer($url)) {
            return 'trusted_retailer';
        }

        return 'standard';
    }

    /** @param array<int, mixed> $sources */
    private function score(string $url, ?string $brand, array $sources, mixed $explicitType = null): int
    {
        $classification = $this->classify($url, $brand, $sources);

        if ($classification === 'official_english') {
            return 500;
        }

        if ($classification === 'official_localized') {
            return 450;
        }

        if ($classification === 'amazon') {
            return 400;
        }

        if ($classification === 'trusted_retailer') {
            return 380;
        }

        $type = is_string($explicitType) ? $explicitType : $this->matchingSourceType($url, $sources);

        return match ($type) {
            'retailer' => 320,
            'marketplace' => 300,
            'database' => 260,
            'review' => 220,
            'web' => 180,
            default => str_contains(Str::lower($url), 'wikimedia.org') ? 140 : 100,
        };
    }

    /** @param array<int, mixed> $sources */
    private function isOfficial(string $url, ?string $brand, array $sources): bool
    {
        $host = $this->host($url);

        if ($host === '') {
            return false;
        }

        $brandKey = $this->brandKey($brand);
        $labels = explode('.', $host);

        if ($brandKey !== '' && in_array($brandKey, $labels, true)) {
            return true;
        }

        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['url'] ?? null)) {
                continue;
            }

            $sourceHost = $this->host($source['url']);
            $sourceLabels = explode('.', $sourceHost);
            $manufacturer = ($source['type'] ?? null) === 'manufacturer'
                || ($brandKey !== '' && in_array($brandKey, $sourceLabels, true));

            if ($manufacturer && $this->hostsMatch($host, $sourceHost)) {
                return true;
            }
        }

        return false;
    }

    private function isEnglishOrUs(string $url): bool
    {
        $segments = array_values(array_filter(explode('/', Str::lower((string) parse_url($url, PHP_URL_PATH)))));

        foreach (array_slice($segments, 0, 2) as $segment) {
            $segment = str_replace('_', '-', $segment);

            if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $segment) !== 1) {
                continue;
            }

            return str_starts_with($segment, 'en-')
                || in_array($segment, ['en', 'us', 'uk', 'gb', 'ca', 'au'], true);
        }

        // Manufacturer root/global paths are normally their canonical English/US version.
        return true;
    }

    private function isAmazon(string $url): bool
    {
        $host = $this->host($url);

        return preg_match('/(^|\.)amazon\.[a-z.]+$/', $host) === 1
            || str_contains($host, 'media-amazon.')
            || str_contains($host, 'images-amazon.')
            || str_contains($host, 'ssl-images-amazon.');
    }

    /**
     * Major US and Czech/EU electronics retailers/marketplaces that are as
     * reliable as Amazon for exact-product photos and listings, ranked just
     * below it. Deliberately no Ukrainian retailers here (e.g. Rozetka) -
     * their product photos frequently carry Ukrainian text/watermarks, so
     * they stay a source_verified fallback only, never an equal trust tier.
     */
    private function isTrustedRetailer(string $url): bool
    {
        $host = $this->host($url);

        return collect([
            // US
            'ebay.', 'newegg.', 'bestbuy.', 'walmart.', 'target.',
            'bhphotovideo.', 'adorama.', 'costco.', 'samsclub.', 'microcenter.',
            // Czech / EU
            'alza.', 'czc.cz', 'datart.cz', 'mediamarkt.', 'saturn.de',
        ])->contains(fn (string $needle): bool => str_contains($host, $needle));
    }

    /** @param array<int, mixed> $sources */
    private function matchingSourceType(string $url, array $sources): ?string
    {
        $host = $this->host($url);

        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['url'] ?? null)) {
                continue;
            }

            if ($this->hostsMatch($host, $this->host($source['url']))) {
                return is_string($source['type'] ?? null) ? $source['type'] : null;
            }
        }

        return null;
    }

    private function hostsMatch(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && (
            $left === $right
            || str_ends_with($left, '.'.$right)
            || str_ends_with($right, '.'.$left)
        );
    }

    private function host(string $url): string
    {
        return Str::lower((string) parse_url($url, PHP_URL_HOST));
    }

    private function brandKey(?string $brand): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $brand))) ?: '';
    }
}
