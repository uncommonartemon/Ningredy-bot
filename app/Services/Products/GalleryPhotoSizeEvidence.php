<?php

namespace App\Services\Products;

use App\Services\Photos\ImageUrls;

/**
 * What a page's photographs measured, per photograph, across the rounds of
 * one training session - gathered for the trainer agent, which decides what
 * it means. Scattered over rounds, the sizes did not tell it that
 * store.acer.com served eleven of twelve photographs at 500px even at the
 * page's own full-size URL (requests #89, #91: $1.25 spent looking for
 * larger ones while the second shop in the queue never got a turn).
 *
 * A photograph is identified the way the rest of the catalog identifies an
 * image asset (ImageUrls::imageAssetKey()): size parameters and
 * known rendition markers do not make it another photograph, while a
 * parameter that picks the frame (image?id=front, image?id=back) does.
 *
 * Nothing here concludes that a photograph is at its real size. Two URLs
 * measuring the same is a hint, not proof - a zoom or lightbox may still
 * serve a larger one. Only the agent, having looked, ends a page for it.
 */
final class GalleryPhotoSizeEvidence
{
    /** Keeps the prompt bounded on a page with a long thumbnail strip. */
    private const int MAX_FILES_FOR_AGENT = 24;

    private const int MAX_RENDITIONS_PER_FILE = 4;

    /**
     * @param  array<int, mixed>  $attempts  training rounds as the trainer records them
     * @return array{minimum_width: int, files: array<string, array{best_width: int, best_height: int, renditions: array<string, array{0: int, 1: int}>, same_size_under_several_urls: bool, publishable: bool}>}
     */
    public static function fromAttempts(array $attempts, int $minimumWidth): array
    {
        $files = [];

        foreach ($attempts as $attempt) {
            foreach (self::measurements(is_array($attempt) ? $attempt : []) as [$url, $width, $height]) {
                $key = self::fileKey($url);

                if ($key === null) {
                    continue;
                }

                [$knownWidth, $knownHeight] = $files[$key]['renditions'][$url] ?? [0, 0];
                $files[$key]['renditions'][$url] = $width > $knownWidth ? [$width, $height] : [$knownWidth, $knownHeight];
            }
        }

        foreach ($files as $key => $file) {
            $best = collect($file['renditions'])->sortByDesc(fn (array $size): int => $size[0])->first();
            $atBest = collect($file['renditions'])->filter(fn (array $size): bool => $size[0] === $best[0])->count();
            $files[$key] = [
                'best_width' => $best[0],
                'best_height' => $best[1],
                'renditions' => $file['renditions'],
                'same_size_under_several_urls' => $atBest >= 2,
                'publishable' => $best[0] >= $minimumWidth,
            ];
        }

        return ['minimum_width' => $minimumWidth, 'files' => $files];
    }

    /**
     * The largest measured URL of every photograph that reached the minimum,
     * in the order the page first showed them.
     *
     * @param  array<int, mixed>  $attempts
     * @return array<int, string>
     */
    public static function publishableUrls(array $attempts, int $minimumWidth): array
    {
        return collect(self::fromAttempts($attempts, $minimumWidth)['files'])
            ->filter(fn (array $file): bool => $file['publishable'])
            ->map(fn (array $file): string => (string) collect($file['renditions'])
                ->sortByDesc(fn (array $size): int => $size[0])
                ->keys()
                ->first())
            ->values()
            ->all();
    }

    /**
     * For the trainer's prompt: each photograph with the sizes it measured
     * under each URL it was fetched by.
     *
     * @param  array<int, mixed>  $attempts
     */
    public static function forAgent(array $attempts, int $minimumWidth): ?array
    {
        $evidence = self::fromAttempts($attempts, $minimumWidth);

        if ($evidence['files'] === []) {
            return null;
        }

        $files = collect($evidence['files']);

        return [
            'minimum_width' => $minimumWidth,
            'publishable_photographs' => $files->where('publishable', true)->count(),
            'below_minimum_photographs' => $files->where('publishable', false)->count(),
            'photographs' => $files
                ->sortByDesc(fn (array $file): int => $file['best_width'])
                ->take(self::MAX_FILES_FOR_AGENT)
                ->map(fn (array $file, string $key): array => [
                    'file' => $key,
                    'best' => $file['best_width'].'x'.$file['best_height'],
                    'same_size_under_several_urls' => $file['same_size_under_several_urls'],
                    'measured_as' => collect($file['renditions'])
                        ->sortByDesc(fn (array $size): int => $size[0])
                        ->take(self::MAX_RENDITIONS_PER_FILE)
                        ->map(fn (array $size, string $url): string => self::renditionLabel($url).' => '.$size[0].'x'.$size[1])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'instruction' => 'Each entry is one photograph; the URLs under measured_as are renditions of it with the '
                .'size each measured. same_size_under_several_urls is a hint that the site may not serve it larger - '
                .'not proof: a zoom, lightbox or full-size viewer can still hold a bigger file. When the page\'s own '
                .'large view has been tried and the photographs below the minimum still do not grow, end this page '
                .'with AbandonGalleryTrainingAttempt using failure_kind source_frames_too_small: the publishable ones '
                .'are kept and the next shop gets the budget. Until then, looking for the larger view is the work.',
        ];
    }

    /** @return array<int, array{0: string, 1: int, 2: int}> */
    private static function measurements(array $attempt): array
    {
        $measured = [];

        foreach ((array) data_get($attempt, 'download_probe.frames', []) as $frame) {
            if (is_array($frame) && in_array($frame['status'] ?? null, ['usable', 'too_small'], true)) {
                $measured[] = [(string) ($frame['url'] ?? ''), (int) ($frame['width'] ?? 0), (int) ($frame['height'] ?? 0)];
            }
        }

        foreach ((array) data_get($attempt, 'diagnostics.validated_image_evidence', []) as $frame) {
            if (is_array($frame)) {
                $measured[] = [(string) ($frame['url'] ?? ''), (int) ($frame['width'] ?? 0), (int) ($frame['height'] ?? 0)];
            }
        }

        // Only a size verdict is a measurement; a decode failure or a timeout
        // says nothing about how large the photograph is.
        foreach ((array) data_get($attempt, 'diagnostics.rejected_candidates', []) as $frame) {
            if (is_array($frame) && ($frame['reason'] ?? null) === 'dimensions_below_minimum') {
                $measured[] = [(string) ($frame['url'] ?? ''), (int) ($frame['width'] ?? 0), (int) ($frame['height'] ?? 0)];
            }
        }

        return array_values(array_filter($measured, fn (array $item): bool => $item[0] !== '' && $item[1] > 0));
    }

    /**
     * The size parameters the browser's own asset key drops on every host
     * (scripts/product-gallery-utils.mjs imageAssetKey()), then the catalog's
     * asset key. Any other parameter - id=front - still tells photographs apart.
     */
    private static function fileKey(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        foreach (['width', 'height', 'w', 'h', 'quality', 'q', 'fit'] as $sizeParameter) {
            unset($query[$sizeParameter]);
        }
        $withoutSizes = ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '/')
            .($query === [] ? '' : '?'.http_build_query($query));

        try {
            return ImageUrls::imageAssetKey($withoutSizes);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function renditionLabel(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return mb_substr(basename((string) parse_url($url, PHP_URL_PATH)).($query !== '' ? '?'.$query : ''), 0, 160);
    }
}
