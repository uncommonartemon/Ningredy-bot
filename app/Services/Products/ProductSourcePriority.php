<?php

namespace App\Services\Products;

use App\Models\ImageSourcePriority;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductSourcePriority
{
    private ?Collection $configuredRules = null;

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
            ->sort(fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['index'] <=> $right['index']))
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
            ->sort(fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['index'] <=> $right['index']))
            ->pluck('url')
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $sources */
    public function classify(string $url, ?string $brand, array $sources = []): string
    {
        $rule = $this->matchingConfiguredRule($url);

        if ($rule) {
            if ($rule->source_type === 'manufacturer') {
                return $this->isEnglishOrUs($url) ? 'official_english' : 'official_localized';
            }

            if (Str::contains(Str::lower($rule->name.' '.$rule->domain), 'amazon')) {
                return 'amazon';
            }

            if (in_array($rule->source_type, ['retailer', 'marketplace'], true)) {
                return 'trusted_retailer';
            }
        }

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

    public function preferredSourceInstructions(): string
    {
        $lines = $this->rules()
            ->take(15)
            ->map(fn (ImageSourcePriority $source): string => "- {$source->name} ({$source->domain}), priority {$source->priority}, type {$source->source_type}")
            ->implode("\n");

        return $lines !== '' ? $lines : '- Amazon, Best Buy, B&H Photo, Newegg, Walmart, Target';
    }

    /** @param array<int, mixed> $sources */
    private function score(string $url, ?string $brand, array $sources, mixed $explicitType = null): int
    {
        if ($rule = $this->matchingConfiguredRule($url)) {
            return 1_000_000 + (int) $rule->priority;
        }

        $classification = $this->classify($url, $brand, $sources);
        $hasConfiguredRules = $this->rules()->isNotEmpty();

        if ($classification === 'official_english') {
            return $hasConfiguredRules ? 900_000 : 820;
        }

        if ($classification === 'official_localized') {
            return $hasConfiguredRules ? 899_000 : 780;
        }

        if ($classification === 'amazon') {
            return 1000;
        }

        if ($classification === 'trusted_retailer') {
            return 760;
        }

        $type = is_string($explicitType) ? $explicitType : $this->matchingSourceType($url, $sources);

        return match ($type) {
            'retailer' => 700,
            'marketplace' => 680,
            'manufacturer' => 650,
            'database' => 500,
            'review' => 400,
            'web' => 300,
            default => str_contains(Str::lower($url), 'wikimedia.org') ? 140 : 100,
        };
    }

    private function matchingConfiguredRule(string $url): ?ImageSourcePriority
    {
        $host = $this->host($url);

        if ($host === '') {
            return null;
        }

        return $this->rules()->first(function (ImageSourcePriority $source) use ($host): bool {
            $domains = [$source->domain, ...($source->aliases ?? [])];

            return collect($domains)->contains(fn (mixed $domain): bool => is_string($domain) && $this->hostsMatch($host, Str::lower($domain)));
        });
    }

    private function rules(): Collection
    {
        if ($this->configuredRules !== null) {
            return $this->configuredRules;
        }

        try {
            if (! Schema::hasTable('image_source_priorities')) {
                return $this->configuredRules = collect();
            }

            return $this->configuredRules = ImageSourcePriority::query()
                ->where('is_enabled', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return $this->configuredRules = collect();
        }
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

    private function isTrustedRetailer(string $url): bool
    {
        $host = $this->host($url);

        return collect([
            'ebay.', 'newegg.', 'bestbuy.', 'walmart.', 'target.',
            'bhphotovideo.', 'adorama.', 'costco.', 'samsclub.', 'microcenter.',
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
        $left = Str::after(Str::lower($left), 'www.');
        $right = Str::after(Str::lower($right), 'www.');

        return $left !== '' && $right !== '' && (
            $left === $right
            || str_ends_with($left, '.'.$right)
            || str_ends_with($right, '.'.$left)
        );
    }

    private function host(string $url): string
    {
        return Str::after(Str::lower((string) parse_url($url, PHP_URL_HOST)), 'www.');
    }

    private function brandKey(?string $brand): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $brand))) ?: '';
    }
}
