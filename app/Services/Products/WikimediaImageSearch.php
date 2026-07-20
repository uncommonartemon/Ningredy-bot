<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WikimediaImageSearch
{
    /** @return array<int, string> */
    public function find(ProductDraft $draft, int $limit = 10): array
    {
        if ($limit <= 0) {
            return [];
        }

        $images = [];
        $identityTokens = $this->identityTokens($draft);

        foreach ($this->queries($draft) as $query) {
            try {
                $response = $this->client()->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'generator' => 'search',
                    'gsrsearch' => $query,
                    'gsrnamespace' => 6,
                    'gsrlimit' => min(20, max($limit, 10)),
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|mime|size',
                    'iiurlwidth' => 1600,
                    'format' => 'json',
                    'formatversion' => 2,
                ]);

                if (! $response->successful()) {
                    continue;
                }

                $pages = $response->json('query.pages', []);

                if (! is_array($pages)) {
                    continue;
                }

                usort($pages, function (array $left, array $right) use ($identityTokens): int {
                    $score = $this->relevanceScore($right, $identityTokens) <=> $this->relevanceScore($left, $identityTokens);

                    return $score !== 0
                        ? $score
                        : (($left['index'] ?? PHP_INT_MAX) <=> ($right['index'] ?? PHP_INT_MAX));
                });

                foreach ($pages as $page) {
                    $info = $page['imageinfo'][0] ?? null;

                    if (! is_array($info) || ! $this->isUseful($page, $info, $identityTokens)) {
                        continue;
                    }

                    $url = $info['thumburl'] ?? $info['url'] ?? null;

                    if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                        $images[] = $url;
                    }
                }
            } catch (Throwable $exception) {
                Log::debug('Wikimedia product image search was unavailable.', [
                    'query' => $query,
                    'error' => $exception->getMessage(),
                ]);
            }

            $images = array_values(array_unique($images));

            if (count($images) >= $limit) {
                break;
            }
        }

        return array_slice($images, 0, $limit);
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent('NingredyCatalog/1.0 (product image search)')
            ->connectTimeout(4)
            ->timeout(12)
            ->retry(2, 250, throw: false);
    }

    /** @return array<int, string> */
    private function queries(ProductDraft $draft): array
    {
        $identity = Str::squish(implode(' ', array_filter([$draft->brand, $draft->model ?: $draft->title])));
        $title = Str::squish((string) $draft->title);

        return collect([$identity, $title])
            ->filter()
            ->unique(fn (string $query): string => Str::lower($query))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function identityTokens(ProductDraft $draft): array
    {
        $identity = Str::lower(Str::ascii(Str::squish(
            implode(' ', array_filter([$draft->brand, $draft->model ?: $draft->title])),
        )));

        return collect(preg_split('/[^a-z0-9]+/', $identity) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 2)
            ->reject(fn (string $token): bool => in_array($token, [
                'product', 'processor', 'laptop', 'notebook', 'tablet', 'graphics', 'card', 'edition',
            ], true))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $page @param array<string, mixed> $info @param array<int, string> $identityTokens */
    private function isUseful(array $page, array $info, array $identityTokens): bool
    {
        $mime = Str::lower((string) ($info['mime'] ?? ''));

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }

        $width = (int) ($info['thumbwidth'] ?? $info['width'] ?? 0);
        $height = (int) ($info['thumbheight'] ?? $info['height'] ?? 0);

        if (min($width, $height) < 320) {
            return false;
        }

        $label = Str::lower(Str::ascii(urldecode(
            (string) ($page['title'] ?? '').' '.(string) ($info['url'] ?? ''),
        )));

        if (Str::contains($label, [
            ' logo', '_logo', '-logo', 'icon', 'wordmark', 'symbol', 'diagram', 'screenshot',
        ])) {
            return false;
        }

        $labelTokens = collect(preg_split('/[^a-z0-9]+/', $label) ?: [])->unique();
        $matched = collect($identityTokens)->filter(fn (string $token): bool => $labelTokens->contains($token));

        if ($matched->contains(fn (string $token): bool => strlen($token) >= 3
            && preg_match('/[a-z]/', $token) === 1
            && preg_match('/\d/', $token) === 1)) {
            return true;
        }

        if ($matched->contains(fn (string $token): bool => strlen($token) >= 4 && ctype_digit($token))) {
            return true;
        }

        return $matched->count() >= min(2, count($identityTokens));
    }

    /** @param array<string, mixed> $page @param array<int, string> $identityTokens */
    private function relevanceScore(array $page, array $identityTokens): int
    {
        $title = Str::lower(Str::ascii(urldecode((string) ($page['title'] ?? ''))));
        $titleTokens = collect(preg_split('/[^a-z0-9]+/', $title) ?: [])->unique();
        $matches = collect($identityTokens)->filter(fn (string $token): bool => $titleTokens->contains($token))->count();
        $penalty = Str::contains($title, [' vs ', ' versus ', ' comparison', ' compared ', ' with ']) ? 30 : 0;

        return ($matches * 10) - $penalty;
    }
}
