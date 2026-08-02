<?php

namespace App\Services\Products;

use App\Models\ProductSourceStat;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProductSourceMetrics
{
    public function recordExtraction(string $url, int $imageCount, ?string $failureKind = null): void
    {
        $domain = ProductSourcePriority::host($url);

        if ($domain === '') {
            return;
        }

        $this->mutate($domain, function (ProductSourceStat $stat) use ($imageCount, $failureKind): void {
            $stat->attempt_count++;
            $stat->last_attempt_at = now();

            if ($imageCount > 0) {
                $stat->extraction_success_count++;
                $stat->last_success_at = now();
                $stat->last_failure_kind = null;
            } else {
                $stat->failure_count++;
                $stat->last_failure_kind = $failureKind ?: 'empty_gallery';
            }
        });
    }

    public function recordAcceptedGallery(string $url, int $imageCount): void
    {
        if ($imageCount <= 0) {
            return;
        }

        $domain = ProductSourcePriority::host($url);

        if ($domain === '') {
            return;
        }

        $this->mutate($domain, function (ProductSourceStat $stat) use ($imageCount): void {
            $stat->accepted_gallery_count++;
            $stat->accepted_image_count += $imageCount;
            $stat->last_success_at = now();
        });
    }

    public function score(string $url): int
    {
        $domain = ProductSourcePriority::host($url);

        if ($domain === '') {
            return 0;
        }

        try {
            $stat = ProductSourceStat::query()->where('domain', $domain)->first();
        } catch (Throwable) {
            return 0;
        }

        if (! $stat || $stat->attempt_count <= 0) {
            return 0;
        }

        $attempts = max(1, (int) $stat->attempt_count);
        $accepted = max(0, (int) $stat->accepted_gallery_count);
        $extracted = max(0, (int) $stat->extraction_success_count);

        if ($accepted > 0) {
            $acceptedRate = ($accepted + 1) / ($attempts + 2);

            return 100_000
                + (int) round($acceptedRate * 10_000)
                + min(1_000, (int) $stat->accepted_image_count);
        }

        if ($extracted > 0) {
            $extractionRate = ($extracted + 1) / ($attempts + 2);

            return 50_000
                + (int) round($extractionRate * 5_000)
                + min(500, $extracted);
        }

        return -1 * min(10_000, max(1, (int) $stat->failure_count));
    }

    /** @param callable(ProductSourceStat): void $callback */
    private function mutate(string $domain, callable $callback): void
    {
        try {
            DB::transaction(function () use ($domain, $callback): void {
                ProductSourceStat::query()->firstOrCreate(['domain' => $domain]);
                $stat = ProductSourceStat::query()->where('domain', $domain)->lockForUpdate()->firstOrFail();
                $callback($stat);
                $stat->save();
            }, 3);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
