<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductImageStorage
{
    public function __construct(
        private readonly ProductImageResolver $resolver,
        private readonly ProductImageCandidateDiscovery $candidateDiscovery,
        private readonly ProductImageVisionVerifier $visionVerifier,
        private readonly ProductSourcePriority $sourcePriority,
        private readonly ImagePerceptualHash $perceptualHash,
        private readonly ProductImageEncoder $encoder,
        private readonly ProductIdentityMatcher $identityMatcher,
    ) {}

    public function store(Product $product, ProductVariant $variant, ProductDraft $draft): int
    {
        $existingMedia = $product->media()
            ->where('type', 'image')
            ->get(['source_url', 'checksum']);
        $existing = $existingMedia->count();
        $target = $this->targetImageCount($product);
        $remaining = $target - $existing;

        if ($remaining <= 0) {
            return 0;
        }

        $knownUrls = $this->cleanUrls($draft->image_urls ?? []);
        $existingSourceUrls = $existingMedia->pluck('source_url')->filter()->values()->all();
        $initialUrls = array_values(array_diff($knownUrls, $existingSourceUrls));
        $candidates = $this->downloadCandidates($initialUrls, $draft);
        $usedDiscovery = false;

        Log::info('Product image pipeline started.', [
            'draft_id' => $draft->id,
            'product_id' => $product->id,
            'target_images' => $target,
            'existing_images' => $existing,
            'initial_urls' => count($initialUrls),
            'initial_downloads' => count($candidates),
        ]);

        if ($candidates === [] && config('product-images.fallback_discovery', true)) {
            [$candidates, $usedDiscovery] = $this->discoverCandidates(
                $draft,
                array_values(array_unique([...$knownUrls, ...$existingSourceUrls])),
            );
        }

        $selected = $this->selectFromCandidates($draft, $candidates, $remaining);

        if (
            count($selected) < $remaining
            && ! $usedDiscovery
            && config('product-images.fallback_discovery', true)
            && config('product-images.discover_after_rejection', true)
        ) {
            try {
                [$additionalCandidates, $usedDiscovery] = $this->discoverCandidates(
                    $draft,
                    array_values(array_unique([...$knownUrls, ...$existingSourceUrls])),
                );
                $additionalCandidates = $this->removeDuplicateCandidates($candidates, $additionalCandidates);
                $additionalSelected = $this->selectFromCandidates($draft, $additionalCandidates, $remaining - count($selected));
            } catch (Throwable $exception) {
                $this->destroy($candidates);

                throw $exception;
            }

            $candidates = [...$candidates, ...$additionalCandidates];
            $selected = [...$selected, ...$additionalSelected];
        }

        $selected = $this->removeNearDuplicates($selected);

        $this->destroyUnselected($candidates, $selected);

        Log::info('Product image candidates reviewed.', [
            'draft_id' => $draft->id,
            'product_id' => $product->id,
            'downloaded' => count($candidates),
            'selected' => count($selected),
            'used_discovery' => $usedDiscovery,
        ]);

        $roles = ['primary', 'secondary', 'detail'];
        $stored = 0;
        $storedChecksums = array_fill_keys(
            $existingMedia->pluck('checksum')->filter()->values()->all(),
            true,
        );

        foreach ($selected as $candidate) {
            $path = null;

            try {
                $converted = $this->encoder->toWebp($candidate['image']);
                $encoded = $converted['bytes'];
                $checksum = hash('sha256', $encoded);

                if (isset($storedChecksums[$checksum])) {
                    continue;
                }

                $role = $roles[$existing + $stored] ?? 'detail';
                $path = "products/{$product->id}/{$role}-".substr($checksum, 0, 12).'.webp';

                if (! Storage::disk('public')->put($path, $encoded)) {
                    throw new \RuntimeException("Could not write product image: {$path}");
                }

                $product->media()->create([
                    'product_variant_id' => $variant->id,
                    'type' => 'image',
                    'disk' => 'public',
                    'path' => $path,
                    'role' => $role,
                    'url' => '/storage/'.str_replace('\\', '/', $path),
                    'source_url' => $candidate['source_url'],
                    'alt' => $product->title,
                    'mime_type' => 'image/webp',
                    'width' => $converted['width'],
                    'height' => $converted['height'],
                    'file_size' => strlen($encoded),
                    'checksum' => $checksum,
                    'verification_status' => $candidate['verification_status'] ?? 'verified',
                    'verification_score' => isset($candidate['vision_score'])
                        ? $candidate['vision_score'] / 100
                        : ($candidate['verification_score'] ?? null),
                    'verification_model' => $candidate['vision_model'] ?? $candidate['verification_model'] ?? null,
                    'verification_notes' => $candidate['vision_reason'] ?? $candidate['verification_notes'] ?? null,
                    'verified_at' => now(),
                    'sort_order' => $existing + $stored,
                    'is_primary' => $existing === 0 && $stored === 0,
                ]);
                $storedChecksums[$checksum] = true;
                $stored++;
            } catch (Throwable $exception) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }

                report($exception);
            } finally {
                imagedestroy($candidate['image']);
            }
        }

        return $stored;
    }

    /** @param array<int, mixed> $urls @return array<int, string> */
    private function cleanUrls(array $urls): array
    {
        $limit = (int) config('product-images.download_limit', 8);

        return collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(fn (string $url): string => $this->normalizeCandidateUrl($url))
            ->filter(fn (string $url): bool => $url !== '' && ! $this->looksLikeJunk($url))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /** @param array<int, string> $urls @return array<int, array<string, mixed>> */
    private function downloadCandidates(array $urls, ProductDraft $draft): array
    {
        $candidates = [];
        $checksums = [];
        $limit = (int) config('product-images.download_candidates', 8);
        $urls = $this->sourcePriority->sortUrls($urls, $draft->brand, $draft->sources ?? []);

        foreach ($urls as $url) {
            if (count($candidates) >= $limit) {
                break;
            }

            $download = $this->resolver->download($url);

            if (! $download || ! $this->hasUsefulDimensions($download['width'], $download['height'])) {
                continue;
            }

            if (! $this->encoder->isSafeToDecode($download['width'], $download['height'])) {
                Log::warning('Product image candidate skipped: too large to safely decode.', [
                    'source_url' => $download['source_url'],
                    'width' => $download['width'],
                    'height' => $download['height'],
                ]);

                continue;
            }

            $checksum = hash('sha256', $download['bytes']);

            if (isset($checksums[$checksum])) {
                continue;
            }

            $image = @imagecreatefromstring($download['bytes']);

            if (! $image instanceof GdImage) {
                continue;
            }

            $classification = $this->sourcePriority->classify(
                $download['source_url'],
                $draft->brand,
                $draft->sources ?? [],
            );
            $candidates[] = [
                ...$download,
                'image' => $image,
                'source_priority' => match ($classification) {
                    'official_english', 'official_localized' => 'official',
                    'amazon' => 'amazon',
                    'trusted_retailer' => 'trusted_retailer',
                    default => 'standard',
                },
            ];
            $checksums[$checksum] = true;
        }

        return $candidates;
    }

    /** @param array<int, string> $existingUrls @return array{array<int, array<string, mixed>>, bool} */
    private function discoverCandidates(ProductDraft $draft, array $existingUrls): array
    {
        $discovered = $this->candidateDiscovery->find($draft);
        $newUrls = array_values(array_diff($this->cleanUrls($discovered), $existingUrls));

        if ($discovered !== []) {
            $draft->update(['image_urls' => array_values(array_unique([
                ...($draft->image_urls ?? []),
                ...$discovered,
            ]))]);
        }

        return [$this->downloadCandidates($newUrls, $draft), true];
    }

    /**
     * Prefer trusted-source images (skip Vision entirely) and only spend a
     * Vision review on the remaining slots. Used both for the initial batch
     * of candidates and again for whatever discovery finds afterward, so the
     * two callers never need to duplicate this sequence.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function selectFromCandidates(ProductDraft $draft, array $candidates, int $remaining): array
    {
        $candidates = $this->preferSingleSourceGallery($candidates);
        $selected = $this->sourceVerified($draft, $candidates, $remaining);
        $needsVision = $this->removeSelectedCandidates($candidates, $selected);

        if (count($selected) < $remaining) {
            $selected = [
                ...$selected,
                ...$this->verify($draft, $needsVision, $remaining - count($selected)),
            ];
        }

        return $selected;
    }

    /** @param array<int, array<string, mixed>> $candidates @return array<int, array<string, mixed>> */
    private function verify(ProductDraft $draft, array $candidates, int $remaining): array
    {
        if ($candidates === []) {
            return [];
        }

        try {
            $selected = [];
            $batchSize = max(1, (int) config('product-images.vision_candidates', 4));
            $maxBatches = max(1, (int) config('product-images.vision_max_batches', 2));

            foreach (array_slice(array_chunk($candidates, $batchSize), 0, $maxBatches) as $batch) {
                $needed = $remaining - count($selected);

                if ($needed <= 0) {
                    break;
                }

                $selected = [
                    ...$selected,
                    ...$this->visionVerifier->select($draft, $batch, $needed),
                ];
            }

            return $selected;
        } catch (Throwable $exception) {
            $this->destroy($candidates);

            throw $exception;
        }
    }

    /**
     * When one trusted source (official manufacturer, Amazon, a trusted
     * retailer) has its own multi-shot gallery, build the whole public
     * gallery from that single listing instead of mixing angles scraped
     * from several different sites - stops "the same shot, but a
     * smaller/worse copy from another site" from ever entering the pool.
     * A host only wins this way if it is actually trusted and has at least
     * two candidates; a single stray image never triggers the restriction,
     * so a lone official shot can still be topped up from elsewhere.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function preferSingleSourceGallery(array $candidates): array
    {
        if (count($candidates) < 2) {
            return $candidates;
        }

        $priorityRank = ['official' => 3, 'amazon' => 2, 'trusted_retailer' => 1, 'standard' => 0];

        $groups = collect($candidates)
            ->groupBy(fn (array $candidate): string => $this->host((string) ($candidate['source_url'] ?? '')))
            ->filter(fn ($group, string $host): bool => $host !== '');

        if ($groups->isEmpty()) {
            return $candidates;
        }

        $best = $groups->sortByDesc(function ($group) use ($priorityRank): array {
            $priority = $priorityRank[$group->first()['source_priority'] ?? 'standard'] ?? 0;

            return [$priority, $group->count()];
        })->first();

        $bestPriority = $priorityRank[$best->first()['source_priority'] ?? 'standard'] ?? 0;

        if ($bestPriority === 0 || $best->count() < 2) {
            return $candidates;
        }

        return $best->values()->all();
    }

    /** @param array<int, array<string, mixed>> $candidates @return array<int, array<string, mixed>> */
    private function sourceVerified(ProductDraft $draft, array $candidates, int $limit): array
    {
        if ($limit <= 0 || $candidates === []) {
            return [];
        }

        return collect($candidates)
            ->map(fn (array $candidate): array => [
                'candidate' => $candidate,
                'rank' => $this->sourceVerificationRank($draft, (string) ($candidate['source_url'] ?? '')),
            ])
            ->filter(fn (array $item): bool => $item['rank'] > 0
                && $this->looksLikeProductPhotoShape($item['candidate']['width'], $item['candidate']['height']))
            ->sortByDesc('rank')
            ->take($limit)
            ->map(function (array $item): array {
                return [
                    ...$item['candidate'],
                    'verification_status' => 'source_verified',
                    'verification_score' => min(0.99, 0.80 + ($item['rank'] * 0.03)),
                    'verification_model' => null,
                    'verification_notes' => 'Source-verified product image from a trusted exact product source.',
                ];
            })
            ->values()
            ->all();
    }


    /** @param array<int, array<string, mixed>> $candidates @param array<int, array<string, mixed>> $selected @return array<int, array<string, mixed>> */
    private function removeSelectedCandidates(array $candidates, array $selected): array
    {
        $selectedIds = array_map(fn (array $candidate): int => spl_object_id($candidate['image']), $selected);

        return array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => ! in_array(spl_object_id($candidate['image']), $selectedIds, true),
        ));
    }

    private function sourceVerificationRank(ProductDraft $draft, string $url): int
    {
        if ($url === '' || $this->looksLikeJunk($url) || ! $this->identityMatcher->supports($draft, $url)) {
            return 0;
        }

        $sourceType = $this->sourceTypeForUrl($url, $draft->sources ?? []);

        return match ($sourceType) {
            'retailer', 'marketplace' => 5,
            'manufacturer' => 4,
            'database' => 3,
            default => $this->looksLikeTrustedDirectImage($url) ? 3 : 0,
        };
    }

    /** @param array<int, mixed> $sources */
    private function sourceTypeForUrl(string $url, array $sources): ?string
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

    private function looksLikeTrustedDirectImage(string $url): bool
    {
        $host = $this->host($url);

        return $host !== ''
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('#\.(?:jpe?g|png|webp|avif)(?:\?|$)#i', (string) parse_url($url, PHP_URL_PATH)) === 1;
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
    /** @param array<int, array<string, mixed>> $candidates */
    private function destroy(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            if (($candidate['image'] ?? null) instanceof GdImage) {
                imagedestroy($candidate['image']);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $candidates @param array<int, array<string, mixed>> $selected */
    private function destroyUnselected(array $candidates, array $selected): void
    {
        $selectedIds = array_map(fn (array $candidate): int => spl_object_id($candidate['image']), $selected);

        foreach ($candidates as $candidate) {
            if (! in_array(spl_object_id($candidate['image']), $selectedIds, true)) {
                imagedestroy($candidate['image']);
            }
        }
    }

    /**
     * Vision-approved candidates are already sorted by source rank and score,
     * so the first occurrence of a near-duplicate set is the best one to keep.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function removeNearDuplicates(array $candidates): array
    {
        $threshold = (int) config('product-images.duplicate_hash_threshold', 6);
        $kept = [];
        $hashes = [];

        foreach ($candidates as $candidate) {
            $hash = $this->perceptualHash->hash($candidate['image']);

            foreach ($hashes as $existingHash) {
                if ($this->perceptualHash->distance($hash, $existingHash) <= $threshold) {
                    Log::info('Near-duplicate product image dropped.', [
                        'source_url' => $candidate['source_url'] ?? null,
                    ]);

                    continue 2;
                }
            }

            $hashes[] = $hash;
            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $additional
     * @return array<int, array<string, mixed>>
     */
    private function removeDuplicateCandidates(array $existing, array $additional): array
    {
        $checksums = [];

        foreach ($existing as $candidate) {
            $checksums[hash('sha256', $candidate['bytes'])] = true;
        }

        return array_values(array_filter($additional, function (array $candidate) use (&$checksums): bool {
            $checksum = hash('sha256', $candidate['bytes']);

            if (isset($checksums[$checksum])) {
                imagedestroy($candidate['image']);

                return false;
            }

            $checksums[$checksum] = true;

            return true;
        }));
    }

    private function normalizeCandidateUrl(string $url): string
    {
        $url = trim($url);
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if (str_contains($host, 'dlcdnwebimgs.asus.com')) {
            return preg_replace('#//w(?:48|64|96|184)(?:\?|$)#i', '//w800', $url) ?: $url;
        }

        return $url;
    }

    private function looksLikeJunk(string $url): bool
    {
        return Str::contains(Str::lower($url), [
            'logo', 'favicon', 'sprite', 'placeholder', 'thumb', 'thumbnail', '/small/', '_small',
            'avatar', 'icon-', '/icon/', '/icons/', '/flags/', 'locale-flag', '/blogs/',
            '/category/icons/', '.svg', 'banner', 'tracking', 'pixel.', '/header/', '/w48', '/w64', '/w96', '/w184',
        ]);
    }

    private function targetImageCount(Product $product): int
    {
        $default = max(1, (int) config('product-images.max_images', 3));
        $configured = config("product-images.max_images_by_type.{$product->product_type}");

        return max(1, (int) ($configured ?? $default));
    }

    private function hasUsefulDimensions(int $width, int $height): bool
    {
        $ratio = $width / max($height, 1);

        return min($width, $height) >= (int) config('product-images.minimum_side', 320)
            && $ratio >= (float) config('product-images.minimum_ratio', 0.28)
            && $ratio <= (float) config('product-images.maximum_ratio', 3.5);
    }

    /**
     * Trusted-source candidates skip Vision entirely, so unlike the loose
     * bounds in hasUsefulDimensions() (which Vision still reviews), this is
     * the last line of defense against banner/swatch-strip images from
     * manufacturer spec pages (e.g. a 738x270 color-swatch banner, ratio
     * 2.73 - well inside the general 0.28-3.5 bound but not a product shot).
     */
    private function looksLikeProductPhotoShape(int $width, int $height): bool
    {
        $ratio = $width / max($height, 1);

        return $ratio >= 0.5 && $ratio <= 2.2;
    }
}
