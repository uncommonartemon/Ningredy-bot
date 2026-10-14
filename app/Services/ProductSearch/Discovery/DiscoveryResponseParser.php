<?php

namespace App\Services\ProductSearch\Discovery;

use App\Services\Photos\ImageUrls;
use Illuminate\Support\Facades\Validator;

/** Validates the response envelope before harmless URL cleanup supplies defaults. */
final class DiscoveryResponseParser
{
    public function parse(array $normalizedResponse): array
    {
        $fields = ['sources', 'image_urls', 'page_urls'];
        if (array_intersect_key($normalizedResponse, array_flip($fields)) === []) {
            throw new InvalidDiscoveryResponse('missing_result_fields',
                'No discovery result was returned. This is not evidence that no sources exist. Return the final JSON with sources, image_urls and page_urls; use explicit empty arrays only after an actual search.');
        }
        foreach ($fields as $field) {
            if (array_key_exists($field, $normalizedResponse) && ! is_array($normalizedResponse[$field])) {
                throw new InvalidDiscoveryResponse('invalid_result_field:'.$field,
                    "The {$field} result must be an array, not prose or null. Repair the final JSON.");
            }
        }

        foreach (['image_urls', 'page_urls'] as $urlField) {
            $candidates = collect($normalizedResponse[$urlField] ?? [])
                ->filter(fn (mixed $url): bool => is_string($url))
                ->map(fn (string $url): string => trim($url))
                ->filter(fn (string $url): bool => mb_strlen($url) <= 2048
                    && filter_var($url, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true));

            // Only image_urls can be two renditions of one photo (Scene7
            // wid/hei, $PRESET$, ...) - page_urls are product page links,
            // where that CDN-size normalization doesn't apply.
            if ($urlField === 'image_urls') {
                $candidates = $candidates->map(fn (string $url): string => ImageUrls::normalizeCandidateUrl($url));
            }

            $normalizedResponse[$urlField] = $candidates->unique()->take(12)->values()->all();
        }

        $normalizedResponse['sources'] = collect($normalizedResponse['sources'] ?? [])
            ->filter(fn (mixed $source): bool => is_array($source))
            ->map(function (array $source): array {
                $pageUrl = is_string($source['page_url'] ?? null) ? trim($source['page_url']) : '';
                $imageUrls = collect($source['image_urls'] ?? [])
                    ->filter(fn (mixed $url): bool => is_string($url))
                    ->map(fn (string $url): string => trim($url))
                    ->filter(fn (string $url): bool => mb_strlen($url) <= 2048
                        && filter_var($url, FILTER_VALIDATE_URL) !== false
                        && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
                    ->map(fn (string $url): string => ImageUrls::normalizeCandidateUrl($url))
                    ->unique()
                    ->take(12)
                    ->values()
                    ->all();

                return ['page_url' => $pageUrl, 'image_urls' => $imageUrls];
            })
            ->filter(fn (array $source): bool => filter_var($source['page_url'], FILTER_VALIDATE_URL) !== false
                && in_array(parse_url($source['page_url'], PHP_URL_SCHEME), ['http', 'https'], true))
            ->unique('page_url')
            ->take(8)
            ->values()
            ->all();

        return Validator::make($normalizedResponse, [
            'sources' => ['sometimes', 'array', 'max:8'],
            'sources.*.page_url' => ['required', 'url:http,https', 'max:2048'],
            'sources.*.image_urls' => ['present', 'array', 'max:12'],
            'sources.*.image_urls.*' => ['url:http,https', 'max:2048'],
            'image_urls' => ['present', 'array', 'max:12'],
            'image_urls.*' => ['url:http,https', 'max:2048'],
            'page_urls' => ['present', 'array', 'max:12'],
            'page_urls.*' => ['url:http,https', 'max:2048'],
            'queries_tried' => ['sometimes', 'array', 'max:40'],
            'queries_tried.*' => ['string', 'max:500'],
        ])->validate();
    }
}
