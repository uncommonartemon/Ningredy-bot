<?php

namespace App\Services\Products;

use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageReporter;
use App\Services\Ai\ProductSearchTimeBudget;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProductImageResolver
{
    public function __construct(
        private readonly BrowserProductGalleryExtractor $browser,
        private readonly AiSettings $settings,
        private readonly AiUsageReporter $usageReporter,
        private readonly ProductSourceMetrics $metrics,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @param  null|callable(string, string): void  $debug
     * @return array<int, string>
     */
    public function resolve(array $sources, int $limit = 5, ?callable $debug = null, ?int $telegramUpdateId = null): array
    {
        $images = [];

        foreach (array_slice($sources, 0, (int) config('product-images.max_sources_per_resolve', 10)) as $source) {
            if (! $this->timeBudget->canStart($telegramUpdateId, 20)) {
                $debug?->__invoke('warning', 'Резерв времени достигнут; новые страницы не открываю, чтобы успеть сохранить результат и ответить в Telegram.');
                break;
            }

            if ($telegramUpdateId !== null && $this->searchBudgetExceeded($telegramUpdateId)) {
                $debug?->__invoke('warning', sprintf(
                    'Бюджет поиска исчерпан (~$%.2f); прекращаю проверку источников.',
                    $this->settings->maxSearchCostUsd(),
                ));

                break;
            }

            $sourceUrl = is_array($source) ? ($source['url'] ?? null) : null;

            if (! is_string($sourceUrl) || ! $this->isPublicUrl($sourceUrl)) {
                continue;
            }

            if ($this->looksLikeNonHtmlDocumentUrl($sourceUrl)) {
                $debug?->__invoke('warning', 'Источник пропущен: ссылка ведёт на документ, а не на HTML-карточку товара.');
                $this->metrics->recordExtraction($sourceUrl, 0, 'non_html');

                continue;
            }

            $sourceImageStart = count($images);
            $browserUrl = $sourceUrl;
            $browserEligible = true;
            $accessGateDetected = false;
            $browserImages = [];
            $failureKind = null;

            try {
                [$response, $finalUrl] = $this->fetch($sourceUrl);
                $browserUrl = $finalUrl;

                if ($this->isHtmlResponse($response)) {
                    $html = $response->body();

                    if ($this->looksLikeAccessGate($html)) {
                        $accessGateDetected = true;
                        $failureKind = 'access_gate';
                    } else {
                        foreach ($this->extractPageImages($html, $finalUrl) as $imageUrl) {
                            if ($this->isPublicUrl($imageUrl)) {
                                $images[] = $imageUrl;
                            }
                        }
                    }
                } else {
                    $browserEligible = false;
                    $failureKind = 'non_html';
                    $contentType = strtolower(trim((string) $response->header('Content-Type')));
                    $debug?->__invoke(
                        'warning',
                        'Источник пропущен: ожидалась HTML-карточка товара, получен '.($contentType !== '' ? $contentType : 'неизвестный тип содержимого').'.',
                    );
                }
            } catch (Throwable $exception) {
                $failureKind = 'http_error';
                $debug?->__invoke('warning', 'HTML страницы недоступен: '.$exception->getMessage());
                Log::notice('Product image metadata source was unavailable.', [
                    'host' => parse_url($sourceUrl, PHP_URL_HOST),
                    'error' => $exception->getMessage(),
                ]);
            }

            $staticSourceImages = array_slice($images, $sourceImageStart);
            $browserMode = $this->settings->galleryBrowserMode();
            $shouldUseBrowser = $browserEligible
                && $browserMode !== AiSettings::GALLERY_BROWSER_OFF;

            if ($shouldUseBrowser) {
                $browserResult = $debug
                    ? $this->browser->extract($browserUrl, $limit, $debug, $telegramUpdateId, [
                        'static_image_urls' => $staticSourceImages,
                    ])
                    : $this->browser->extract($browserUrl, $limit, telegramUpdateId: $telegramUpdateId, context: [
                        'static_image_urls' => $staticSourceImages,
                    ]);
                $browserImages = collect($browserResult)
                    ->filter(fn (mixed $imageUrl): bool => is_string($imageUrl) && $this->isPublicUrl($imageUrl))
                    ->values()
                    ->all();

                if ($browserImages !== []) {
                    $accessGateDetected = false;
                    $failureKind = null;
                    $previousSourceImages = array_slice($images, 0, $sourceImageStart);
                    $images = count($browserImages) >= 2
                        ? [...$previousSourceImages, ...$browserImages]
                        : [...$previousSourceImages, ...$browserImages, ...$staticSourceImages];
                }
            }

            if ($accessGateDetected && $browserImages === []) {
                $debug?->__invoke('blocked', 'HTTP-запрос получил служебную страницу, и Playwright не смог открыть полноценную карточку товара.');
            }

            $images = array_values(array_unique($images));
            $sourceImageCount = max(0, count($images) - $sourceImageStart);
            if ($browserImages !== []) {
                $this->metrics->recordExtraction($sourceUrl, count($browserImages));
            } elseif ($sourceImageCount === 0) {
                $this->metrics->recordExtraction($sourceUrl, 0, $failureKind ?: 'empty_gallery');
            }
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_url' => $sourceUrl,
                'actor' => 'html_resolver',
                'phase' => 'source_resolution',
                'action' => 'extract_page_images',
                'status' => $sourceImageCount > 0 ? 'completed' : 'failed',
                'decision' => $failureKind ?: ($browserImages !== [] ? 'playwright_images' : 'static_images'),
                'input' => ['final_url' => $browserUrl, 'browser_mode' => $browserMode],
                'output' => [
                    'static_count' => count($staticSourceImages),
                    'browser_count' => count($browserImages),
                    'returned_count' => $sourceImageCount,
                ],
            ]);

            if (count($images) >= $limit) {
                break;
            }
        }

        return array_slice($images, 0, $limit);
    }

    private function looksLikeNonHtmlDocumentUrl(string $url): bool
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip', 'rar', '7z', 'csv', 'xml', 'json',
        ], true);
    }

    private function isHtmlResponse(mixed $response): bool
    {
        $body = (string) $response->body();
        $prefix = strtolower(ltrim(substr($body, 0, 1024), "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20"));

        if (str_starts_with($prefix, '%pdf-')
            || str_starts_with($prefix, '{"')
            || str_starts_with($prefix, '[')
            || str_starts_with($prefix, 'pk'."\x03\x04")) {
            return false;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml')) {
            return true;
        }

        if ($contentType !== ''
            && ! str_contains($contentType, 'application/octet-stream')
            && ! str_contains($contentType, 'text/plain')) {
            return false;
        }

        return preg_match('/^\s*(?:<!doctype\s+html|<html\b|<head\b|<body\b)/i', $prefix) === 1;
    }

    /**
     * @return array{bytes: string, source_url: string, mime_type: string, width: int, height: int, confirmed_gallery: bool, partial_gallery: bool}|null
     */
    public function download(string $url, int $maxBytes = 8_388_608): ?array
    {
        if (! $this->isPublicUrl($url)) {
            return null;
        }

        try {
            [$response, $finalUrl] = $this->fetch($url);
            $bytes = $response->body();

            if ($bytes === '' || strlen($bytes) > $maxBytes) {
                return null;
            }

            $dimensions = @getimagesizefromstring($bytes);

            if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
                return null;
            }

            return [
                'bytes' => $bytes,
                'source_url' => $finalUrl,
                'mime_type' => $dimensions['mime'] ?? 'application/octet-stream',
                'width' => (int) $dimensions[0],
                'height' => (int) $dimensions[1],
                'confirmed_gallery' => $this->browser->isConfirmedGalleryImage($url)
                    || $this->browser->isConfirmedGalleryImage($finalUrl),
                'partial_gallery' => $this->browser->isPartialGalleryImage($url)
                    || $this->browser->isPartialGalleryImage($finalUrl),
            ];
        } catch (Throwable $exception) {
            Log::notice('Product image candidate was unavailable.', [
                'host' => parse_url($url, PHP_URL_HOST),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** @return array{Response, string} */
    private function fetch(string $url): array
    {
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            if (! $this->isPublicUrl($url)) {
                throw new \RuntimeException('Blocked non-public product source URL.');
            }

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,image/avif,image/webp,image/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => $this->origin($url).'/',
                'Cache-Control' => 'no-cache',
            ])->withoutRedirecting()
                ->connectTimeout((int) config('product-images.http.connect_timeout', 3))
                ->timeout((int) config('product-images.http.timeout', 7))
                ->retry(2, 250)
                ->get($url);

            if ($response->redirect()) {
                $location = $response->header('Location');

                if (! is_string($location) || $location === '') {
                    $response->throw();
                }

                $url = $this->absoluteUrl($location, $url);

                continue;
            }

            $response->throw();

            return [$response, $url];
        }

        throw new \RuntimeException('Too many redirects while resolving product image.');
    }

    private function origin(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    /** @return array<int, string> */
    private function extractPageImages(string $html, string $pageUrl): array
    {
        if ($html === '' || strlen($html) > 5_000_000) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $galleryScope = '//*[contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "gallery") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "slider") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "swiper") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "carousel") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "product-image") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "product-media")]';
        $directQueries = [
            $galleryScope.'//*[@data-old-hires]/@data-old-hires',
            $galleryScope.'//img/@data-zoom-image',
            $galleryScope.'//img/@data-large_image',
            $galleryScope.'//img/@data-full',
            $galleryScope.'//img/@data-full-src',
            $galleryScope.'//img/@data-hires',
            $galleryScope.'//img/@data-original',
            $galleryScope.'//img/@data-image',
            $galleryScope.'//img/@data-src',
            $galleryScope.'//source/@src',
            $galleryScope.'//img/@src',
            '//meta[@property="og:image"]/@content',
            '//meta[@property="og:image:url"]/@content',
            '//meta[@property="og:image:secure_url"]/@content',
            '//meta[@name="twitter:image"]/@content',
            '//meta[@name="twitter:image:src"]/@content',
            '//meta[@itemprop="image"]/@content',
            '//link[@rel="image_src"]/@href',
            '//*[@data-old-hires]/@data-old-hires',
            '//img/@data-full',
            '//img/@data-full-src',
            '//img/@data-hires',
            '//img/@data-image',
            '//img/@data-zoom-image',
            '//img/@data-large_image',
            '//img/@data-original',
            '//img/@data-lazy-src',
            '//img/@data-src',
            '//img/@src',
            '//source/@src',
        ];
        $srcsetQueries = [
            $galleryScope.'//img/@srcset',
            $galleryScope.'//img/@data-srcset',
            $galleryScope.'//source/@srcset',
            '//img/@srcset',
            '//img/@data-srcset',
            '//source/@srcset',
        ];
        $images = [];

        foreach ($directQueries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $candidate = trim(html_entity_decode($node->nodeValue, ENT_QUOTES | ENT_HTML5));

                if ($candidate !== '') {
                    $images[] = $candidate;
                }
            }
        }

        foreach ($srcsetQueries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $images = [...$images, ...$this->srcsetUrls($node->nodeValue)];
            }
        }

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $structuredData = json_decode(html_entity_decode($node->nodeValue, ENT_QUOTES | ENT_HTML5), true);

            if (is_array($structuredData)) {
                $this->collectStructuredImageUrls($structuredData, $images);
            }
        }

        foreach ($xpath->query('//script[not(@type) or @type="application/json" or @type="text/javascript" or @type="application/javascript"]') ?: [] as $node) {
            $images = [...$images, ...$this->scriptImageUrls($node->nodeValue)];
        }

        return collect($images)
            ->map(fn (string $candidate): ?string => $this->normalizeImageCandidate($candidate, $pageUrl))
            ->filter()
            ->unique()
            ->take((int) config('product-images.max_urls_per_page', 60))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function srcsetUrls(string $srcset): array
    {
        $urls = [];

        foreach (array_reverse(explode(',', $srcset)) as $candidate) {
            $url = preg_split('/\s+/', trim($candidate))[0] ?? null;

            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /** @param array<int|string, mixed> $value @param array<int, string> $images */
    private function collectStructuredImageUrls(array $value, array &$images, ?string $parentKey = null): void
    {
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : $parentKey;

            if (
                is_string($item)
                && (
                    in_array($normalizedKey, ['image', 'contenturl', 'thumbnailurl'], true)
                    || ($parentKey === 'image' && in_array($normalizedKey, ['url', 'src'], true))
                )
            ) {
                $images[] = $item;
            } elseif (is_array($item)) {
                $this->collectStructuredImageUrls($item, $images, $normalizedKey);
            }
        }
    }

    /** @return array<int, string> */
    private function scriptImageUrls(string $script): array
    {
        if ($script === '' || strlen($script) > 2_000_000) {
            return [];
        }

        $decoded = str_replace(['\\/', '\u002F'], '/', html_entity_decode($script, ENT_QUOTES | ENT_HTML5));
        preg_match_all(
            "#https?:\\\\?/\\\\?/[^\"'<>\s\\\\]+?\\.(?:jpe?g|png|webp|avif)(?:\\?[^\"'<>\s\\\\]*)?#i",
            $decoded,
            $matches,
        );

        return collect($matches[0] ?? [])
            ->map(fn (string $url): string => str_replace(['\/', '\\/'], '/', $url))
            ->filter(fn (string $url): bool => $this->looksLikeImageUrl($url))
            ->unique()
            ->values()
            ->all();
    }

    private function looksLikeImageUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1
            && preg_match('#\.(?:jpe?g|png|webp|avif)(?:\?|$)#i', $url) === 1
            && ! ImageUrlHeuristics::containsMarker($url, [
                ...ImageUrlHeuristics::COMMON_MARKERS,
                ...ImageUrlHeuristics::TRACKING_MARKERS,
            ]);
    }

    private function normalizeImageCandidate(string $candidate, string $pageUrl): ?string
    {
        $candidate = trim(str_replace('\\/', '/', html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5)));
        $lower = strtolower($candidate);

        if (
            $candidate === ''
            || str_starts_with($candidate, '#')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'blob:')
            || ImageUrlHeuristics::containsMarker($candidate, [
                ...ImageUrlHeuristics::COMMON_MARKERS,
                ...ImageUrlHeuristics::THUMBNAIL_MARKERS,
                ...ImageUrlHeuristics::TRACKING_MARKERS,
            ])
        ) {
            return null;
        }

        return $this->absoluteUrl($candidate, $pageUrl);
    }

    private function looksLikeAccessGate(string $html): bool
    {
        $text = Str::lower(strip_tags($html));

        return Str::contains($text, [
            'click the button below to continue shopping',
            'captcha page',
            'enter the characters you see below',
            'sorry, we just need to make sure you\'re not a robot',
            'robot check',
            'access denied',
        ]);
    }

    private function absoluteUrl(string $candidate, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($candidate, '//')) {
            return $scheme.':'.$candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return "{$scheme}://{$host}{$port}{$candidate}";
        }

        $path = $base['path'] ?? '/';
        $directory = str_ends_with($path, '/') ? $path : dirname($path).'/';

        return "{$scheme}://{$host}{$port}{$directory}{$candidate}";
    }

    /**
     * Stops opening more candidate sources once this search has already
     * spent the configured budget - replaces a fixed source-count cutoff
     * with one tied to what the search has actually cost so far.
     */
    private function searchBudgetExceeded(int $telegramUpdateId): bool
    {
        $maxCost = $this->settings->maxSearchCostUsd();

        if ($maxCost <= 0) {
            return false;
        }

        $spent = $this->usageReporter->forTelegramUpdate($telegramUpdateId)['estimated_cost_usd'] ?? null;

        return is_numeric($spent) && (float) $spent >= $maxCost;
    }

    private function isPublicUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);

        if (! is_array($addresses) || $addresses === []) {
            return false;
        }

        return collect($addresses)->every(fn (string $address): bool => filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false);
    }
}
