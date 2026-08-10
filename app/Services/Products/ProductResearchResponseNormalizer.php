<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

class ProductResearchResponseNormalizer
{
    private const SOURCE_TYPES = [
        'manufacturer',
        'retailer',
        'marketplace',
        'review',
        'database',
        'web',
    ];

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function normalize(array $data): array
    {
        foreach ([
            'title' => 255,
            'brand' => 255,
            'model' => 255,
            'color' => 255,
            'description' => 5000,
            'research_notes' => 5000,
        ] as $key => $limit) {
            if (is_string($data[$key] ?? null)) {
                $data[$key] = mb_substr(trim($data[$key]), 0, $limit);
            }
        }

        $data['image_urls'] = $this->httpUrls($data['image_urls'] ?? [], 10);
        if (is_string($data['primary_source_url'] ?? null)) {
            $data['primary_source_url'] = $this->canonicalProductPageUrl($data['primary_source_url']);
        }
        $data['sources'] = $this->sources(
            $data['sources'] ?? [],
            is_string($data['primary_source_url'] ?? null) ? $data['primary_source_url'] : null,
        );
        $data['specifications'] = $this->specifications($data['specifications'] ?? []);

        if (is_numeric($data['confidence'] ?? null)) {
            $data['confidence'] = max(0.0, min(1.0, (float) $data['confidence']));
        }

        return $data;
    }

    /** @return array<int, string> */
    private function httpUrls(mixed $values, int $limit): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(fn (string $url): string => trim($url))
            ->filter(fn (string $url): bool => mb_strlen($url) <= 2048 && $this->isHttpUrl($url))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<int, array{title: string, url: string, type: string, image_urls: array<int, string>}> */
    private function sources(mixed $values, ?string $primaryUrl): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $source): bool => is_array($source))
            ->map(function (array $source): ?array {
                $title = is_string($source['title'] ?? null)
                    ? mb_substr(trim($source['title']), 0, 500)
                    : '';
                $url = is_string($source['url'] ?? null)
                    ? $this->canonicalProductPageUrl(trim($source['url']))
                    : '';
                $type = is_string($source['type'] ?? null) ? $source['type'] : '';

                if (
                    $title === ''
                    || mb_strlen($url) > 2048
                    || ! $this->isHttpUrl($url)
                    || ! in_array($type, self::SOURCE_TYPES, true)
                ) {
                    return null;
                }

                return [
                    'title' => $title,
                    'url' => $url,
                    'type' => $type,
                    'image_urls' => $this->httpUrls($source['image_urls'] ?? [], 10),
                ];
            })
            ->filter()
            ->unique('url')
            ->sortBy(fn (array $source): int => $source['url'] === $primaryUrl ? 0 : 1)
            ->take(20)
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, name: string, value: string}> */
    private function specifications(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $specification): bool => is_array($specification))
            ->map(function (array $specification, int $index): ?array {
                $rawKey = is_string($specification['key'] ?? null) ? $specification['key'] : '';
                $key = Str::of($rawKey)->trim()->slug('_')->lower()->substr(0, 100)->toString();
                $name = is_string($specification['name'] ?? null)
                    ? mb_substr(trim($specification['name']), 0, 255)
                    : '';
                $value = is_string($specification['value'] ?? null)
                    ? mb_substr(trim($specification['value']), 0, 2000)
                    : '';

                if ($name === '' || $value === '') {
                    return null;
                }

                return [
                    'key' => $key !== '' ? $key : "spec_{$index}",
                    'name' => $name,
                    'value' => $value,
                ];
            })
            ->filter()
            ->unique('key')
            ->take(100)
            ->values()
            ->all();
    }

    private function canonicalProductPageUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('/(^|\.)amazon\.[a-z.]+$/', $host) === 1
            && preg_match('#/(?:[^/]+/)?dp/([A-Z0-9]{10})(?:/|$)#i', $path, $matches) === 1) {
            return 'https://www.amazon.com/dp/'.strtoupper($matches[1]);
        }

        return $url;
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
