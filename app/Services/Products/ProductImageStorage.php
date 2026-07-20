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

        $selected = $this->verify($draft, $candidates, $remaining);

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
            } catch (Throwable $exception) {
                $this->destroy($candidates);

                throw $exception;
            }

            $additionalCandidates = $this->removeDuplicateCandidates($candidates, $additionalCandidates);

            try {
                $additionalSelected = $this->verify(
                    $draft,
                    $additionalCandidates,
                    $remaining - count($selected),
                );
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
                $converted = $this->toWebp($candidate['image']);
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
                    'verification_status' => 'verified',
                    'verification_score' => $candidate['vision_score'] / 100,
                    'verification_model' => $candidate['vision_model'],
                    'verification_notes' => $candidate['vision_reason'],
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
            ->filter(fn (mixed $url): bool => is_string($url) && ! $this->looksLikeJunk($url))
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

    private function looksLikeJunk(string $url): bool
    {
        return Str::contains(Str::lower($url), [
            'logo', 'favicon', 'sprite', 'placeholder', 'avatar', 'icon-', '/icon/', '/icons/',
            '/flags/', 'locale-flag', '/blogs/', '/category/icons/', '.svg',
            'banner', 'tracking', 'pixel.',
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

    /** @return array{bytes: string, width: int, height: int} */
    private function toWebp(GdImage $image): array
    {
        $output = $image;

        if (imagesx($image) > 1600 || imagesy($image) > 1600) {
            $ratio = min(1600 / imagesx($image), 1600 / imagesy($image));
            $output = imagescale($image, (int) round(imagesx($image) * $ratio), (int) round(imagesy($image) * $ratio));
        }

        ob_start();
        imagewebp($output, null, 84);
        $encoded = ob_get_clean();

        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('GD could not encode the product image as WebP.');
        }

        $result = [
            'bytes' => $encoded,
            'width' => imagesx($output),
            'height' => imagesy($output),
        ];

        if ($output !== $image) {
            imagedestroy($output);
        }

        return $result;
    }
}
