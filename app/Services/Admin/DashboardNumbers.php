<?php

namespace App\Services\Admin;

use App\Models\AiRun;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Services\Ai\AiUsageReporter;
use Illuminate\Support\Carbon;

/**
 * The dashboard's two weeks, day by day: what was published, what the bot searched and with
 * what result, and what the searches cost. The cards and the charts are drawn from the same
 * numbers, so they cannot disagree.
 */
class DashboardNumbers
{
    public const int DAYS = 14;

    public function __construct(private readonly AiUsageReporter $usage) {}

    /**
     * @return array{labels: list<string>, published: list<int>, searches: list<int>, with_photos: list<int>,
     *     without_photos: list<int>, spend: list<float>}
     */
    public function daily(): array
    {
        $from = now()->subDays(self::DAYS - 1)->startOfDay();
        $days = collect(range(0, self::DAYS - 1))->map(fn (int $day): string => $from->copy()->addDays($day)->toDateString());
        $perDay = fn ($rows, callable $date) => $rows->countBy(fn ($row): string => Carbon::parse($date($row))->toDateString());

        $published = $perDay(Product::query()->where('published_at', '>=', $from)->get(['published_at']), fn ($row) => $row->published_at);
        $drafts = ProductDraft::query()->where('created_at', '>=', $from)->withCount('media')
            ->get(['id', 'status', 'created_at']);
        // An approved card gave its photos to the product; it still counts as a search that found them.
        $hasPhotos = fn (ProductDraft $draft): bool => $draft->media_count > 0 || $draft->status === 'approved';
        $with = $perDay($drafts->filter($hasPhotos), fn ($row) => $row->created_at);
        $without = $perDay($drafts->reject($hasPhotos), fn ($row) => $row->created_at);
        $runs = AiRun::query()->where('created_at', '>=', $from)->whereNotNull('usage')
            ->get(['provider', 'model', 'usage', 'created_at'])->groupBy(fn (AiRun $run): string => $run->created_at->toDateString());

        return [
            'labels' => $days->map(fn (string $day): string => Carbon::parse($day)->format('d.m'))->all(),
            'published' => $days->map(fn (string $day): int => (int) ($published[$day] ?? 0))->all(),
            'searches' => $days->map(fn (string $day): int => (int) ($with[$day] ?? 0) + (int) ($without[$day] ?? 0))->all(),
            'with_photos' => $days->map(fn (string $day): int => (int) ($with[$day] ?? 0))->all(),
            'without_photos' => $days->map(fn (string $day): int => (int) ($without[$day] ?? 0))->all(),
            'spend' => $days->map(fn (string $day): float => round((float) ($runs->has($day)
                ? ($this->usage->forRuns($runs[$day])['estimated_cost_usd'] ?? 0) : 0), 2))->all(),
        ];
    }
}
