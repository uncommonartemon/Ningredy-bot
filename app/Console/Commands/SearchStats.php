<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiUsageReporter;
use App\Services\Ai\ProductSearchCostBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * How product searches actually went: one row per operator request with its
 * time, money, photos and outcome, then the totals. Read-only.
 */
class SearchStats extends Command
{
    protected $signature = 'search:stats
        {--from= : First Telegram update id to include}
        {--days=7 : Look back this many days when --from is not given}
        {--json : Print rows and totals as JSON}';

    protected $description = 'Summarise recent product searches: duration, cost, photos, card outcome and where they stopped.';

    private const GALLERY_PHASES = ['gallery_preflight', 'gallery_training', 'source_resolution', 'image_download', 'image_verification'];

    public function handle(ProductSearchCostBudget $costBudget): int
    {
        $query = TelegramUpdate::query()->orderBy('id');
        $this->option('from')
            ? $query->where('id', '>=', (int) $this->option('from'))
            : $query->where('created_at', '>=', now()->subDays((int) $this->option('days')));

        $rows = $query->get()
            // Button presses continue an existing draft; the request row owns its search.
            ->reject(fn (TelegramUpdate $update): bool => isset($update->payload['callback_query']) || str_starts_with((string) $update->text, 'draft:'))
            ->map(fn (TelegramUpdate $update): ?array => $this->row($update, $costBudget))
            ->filter()->values();

        if ($this->option('json')) {
            $this->line((string) json_encode(['rows' => $rows, 'totals' => $this->totals($rows)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        if ($rows->isEmpty()) {
            $this->info('No product searches in this window.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Request', 'Type', 'Min', 'Research', 'Photos min', '$', 'Photos', 'Card', 'Stop', 'Sites', 'Icecat', 'Watch', 'Saver', 'AI fails', 'Status'],
            $rows->map(fn (array $row): array => [
                $row['update_id'], mb_strimwidth($row['request'], 0, 34, '…'), $row['type'], $row['minutes'], $row['research_min'], $row['photo_cycle_min'], $row['cost_usd'],
                $row['photos'], $row['card'], $row['stop_reason'], $row['sites'], $row['icecat'], $row['watch'], $row['saver'],
                $row['ai_failures'], $row['status'],
            ])->all(),
        );
        foreach ($this->totals($rows) as $label => $value) {
            $this->line(str_pad($label, 26).' '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value));
        }
        $this->newLine();
        $this->table(['Stage', 'Runs', 'Failed', 'Minutes', '$'], $this->stages($rows->pluck('update_id')->all()));

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function row(TelegramUpdate $update, ProductSearchCostBudget $costBudget): ?array
    {
        $runs = AiRun::query()->where('telegram_update_id', $update->id)->get(['status', 'started_at', 'completed_at', 'error']);
        $draft = ProductDraft::query()->where('telegram_update_id', $update->id)->latest('id')->first();
        $attempts = ProductSourceAttempt::query()->where('telegram_update_id', $update->id)
            ->get(['phase', 'action', 'decision', 'product_url', 'duration_ms']);
        if ($draft === null && ! $attempts->contains('phase', 'product_research')) {
            return null;
        }

        $start = $runs->min('started_at');
        $end = $runs->max('completed_at') ?? $update->processed_at;
        $lookups = $attempts->where('phase', 'code_lookup');

        return [
            'update_id' => $update->id,
            'request' => trim((string) preg_replace('/\s+/u', ' ', (string) $update->text)),
            'type' => $draft?->product_type ?? '-',
            'minutes' => $start && $end ? round($start->diffInSeconds($end) / 60, 1) : null,
            // Research ends when the draft exists; the photo cycle (with card assembly) times itself.
            'research_min' => $start && $draft ? round($start->diffInSeconds($draft->created_at) / 60, 1) : null,
            'photo_cycle_min' => ($cycle = $attempts->where('action', 'cycle_finished')->sum('duration_ms')) > 0 ? round($cycle / 60000, 1) : null,
            'cost_usd' => ($cost = $costBudget->spent($update->id)) !== null ? round($cost, 3) : null,
            'photos' => $draft ? $this->photoCount($draft) : 0,
            'card' => match (true) {
                $draft === null => 'none',
                filled($draft->specifications_reconciled_source_url) => 'assembled',
                default => 'unreconciled',
            },
            'stop_reason' => $draft?->gallery_search_stop_reason ?? '-',
            'sites' => $attempts->whereIn('phase', self::GALLERY_PHASES)
                ->map(fn (ProductSourceAttempt $attempt): string => (string) parse_url((string) $attempt->product_url, PHP_URL_HOST))
                ->filter()->unique()->count(),
            'icecat' => match (true) {
                $lookups->contains(fn ($a): bool => $a->action === 'offer_official_gallery' && $a->decision === 'queued_first') => 'gallery',
                $lookups->contains(fn ($a): bool => $a->action === 'lookup_icecat' && $a->decision === 'found') => 'found',
                $lookups->contains(fn ($a): bool => $a->action === 'lookup_icecat' && $a->decision === 'restricted') => 'closed',
                $lookups->contains('action', 'lookup_icecat') => 'miss',
                default => '-',
            },
            'watch' => $attempts->where('phase', 'search_supervision')->pluck('decision')->implode(',') ?: '-',
            'saver' => $attempts->where('phase', 'delivery_guard')->pluck('action')->implode(',') ?: '-',
            'ai_failures' => $runs->where('status', 'failed')->count(),
            'ai_timeouts' => $runs->filter(fn (AiRun $run): bool => str_contains((string) $run->error, 'cURL error 28'))->count(),
            'status' => $draft?->status ?? $update->status,
        ];
    }

    private function photoCount(ProductDraft $draft): int
    {
        // An approved draft hands its files to the product.
        return $draft->status === 'approved' && $draft->approved_product_id
            ? (int) $draft->product?->media()->count()
            : $draft->media()->count();
    }

    /**
     * Where the time and money of these requests went, by the stage label each AI run carries.
     *
     * @param  list<int>  $updateIds
     * @return list<array{0: string, 1: int, 2: int, 3: float, 4: ?float}>
     */
    private function stages(array $updateIds): array
    {
        $reporter = app(AiUsageReporter::class);

        return AiRun::query()->whereIn('telegram_update_id', $updateIds)
            ->get(['provider', 'model', 'usage', 'status', 'activity', 'started_at', 'completed_at'])
            ->groupBy(fn (AiRun $run): string => (string) (data_get($run->activity, 'stage') ?: 'unlabelled'))
            ->map(fn (Collection $runs, string $stage): array => [$stage, $runs->count(), $runs->where('status', 'failed')->count(),
                round($runs->sum(fn (AiRun $run): int => $run->started_at && $run->completed_at ? (int) $run->started_at->diffInSeconds($run->completed_at) : 0) / 60, 1),
                ($cost = $reporter->forRuns($runs)['estimated_cost_usd']) !== null ? round($cost, 3) : null])
            ->sortByDesc(fn (array $row): float => (float) $row[4])->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function totals(Collection $rows): array
    {
        $searched = $rows->where('card', '!=', 'none');
        $median = function (Collection $values): ?float {
            $sorted = $values->filter(fn ($value): bool => $value !== null)->sort()->values();

            return $sorted->isEmpty() ? null : (float) $sorted[intdiv($sorted->count(), 2)];
        };

        return [
            'requests' => $rows->count(),
            'drafts' => $searched->count(),
            'card assembled' => $searched->where('card', 'assembled')->count(),
            'with photos' => $searched->where('photos', '>', 0)->count(),
            'approved' => $rows->where('status', 'approved')->count(),
            'median minutes' => $median($searched->pluck('minutes')),
            'median research min' => $median($searched->pluck('research_min')),
            'median photo cycle min' => $median($searched->pluck('photo_cycle_min')),
            'median cost $' => $median($searched->pluck('cost_usd')),
            'total cost $' => round((float) $rows->sum('cost_usd'), 2),
            'median photos' => $median($searched->pluck('photos')),
            'stop reasons' => $searched->countBy('stop_reason')->all(),
            'by type' => $searched->countBy('type')->all(),
            'AI failures / timeouts' => $rows->sum('ai_failures').' / '.$rows->sum('ai_timeouts'),
        ];
    }
}
