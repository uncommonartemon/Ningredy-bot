<?php

namespace App\Services\ProductSearch;

use App\Models\AiOperation;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Services\Products\ProductImageCandidateDiscovery;

/** What a running search already did, in the compact form agents read. */
final class SearchJournal
{
    /**
     * Every page this search opened and how it ended: gallery training rounds,
     * photos saved and the gallery agent's own reason for ending the page.
     *
     * @return list<array{host: string, site_name: string, training_rounds: int, photos_saved: int, ended_by_agent: ?string}>
     */
    public static function pagesOpened(int $runId, ?ProductDraft $draft, int $limit = 12): array
    {
        $endedByAgent = AiOperation::query()->where('telegram_update_id', $runId)
            ->where('action', 'abandon_training_attempt')->get(['payload'])
            ->mapWithKeys(fn (AiOperation $operation): array => [
                (string) parse_url((string) data_get($operation->payload, 'url'), PHP_URL_HOST) => mb_substr((string) data_get($operation->payload, 'reason'), 0, 300),
            ]);
        $photosByHost = $draft === null ? collect() : $draft->media()->get(['photo_source_context'])
            ->countBy(fn ($media): string => (string) parse_url((string) data_get($media->photo_source_context, 'url'), PHP_URL_HOST));

        return ProductSourceAttempt::query()->where('telegram_update_id', $runId)
            ->whereIn('phase', ['gallery_preflight', 'gallery_training', 'source_resolution', 'image_download', 'image_verification'])
            ->orderBy('id')->get(['product_url', 'action'])
            ->groupBy(fn (ProductSourceAttempt $attempt): string => (string) parse_url((string) $attempt->product_url, PHP_URL_HOST))
            ->filter(fn ($rows, string $host): bool => $host !== '')
            ->map(fn ($rows, string $host): array => ['host' => $host, 'site_name' => ProductImageCandidateDiscovery::siteName('https://'.$host),
                'training_rounds' => $rows->where('action', 'propose_recipe')->count(),
                'photos_saved' => (int) ($photosByHost[$host] ?? 0),
                'ended_by_agent' => $endedByAgent[$host] ?? null])
            ->values()->slice(-$limit)->values()->all();
    }

    /**
     * Sites that refused every page fetch in recent searches and never gave a
     * usable image (live #456: twelve of twenty discovered shops answered 403).
     * A hint for agents choosing where to look, not a block list.
     *
     * @return list<array{host: string, refusals: int}>
     */
    public static function refusingHosts(int $days = 14, int $limit = 30): array
    {
        $since = now()->subDays($days);
        $refusals = ['http_401', 'http_403', 'http_429', 'http_444', 'http_error', 'access_gate', 'unconfirmed_access_gate', 'blocked'];
        $worked = ProductSourceAttempt::query()->where('created_at', '>=', $since)
            ->where(fn ($query) => $query->where('decision', 'candidate_ranked')
                ->orWhere(fn ($query) => $query->where('action', 'extract_page_images')->where('status', 'completed')))
            ->distinct()->pluck('domain')->filter()->flip();

        return ProductSourceAttempt::query()->where('created_at', '>=', $since)
            ->where('action', 'check_source_access')->whereIn('decision', $refusals)
            ->get(['domain'])->countBy('domain')
            ->filter(fn (int $count, $host): bool => $count >= 2 && filled($host) && ! isset($worked[$host]))
            ->sortDesc()->take($limit)
            ->map(fn (int $count, string $host): array => ['host' => $host, 'refusals' => $count])
            ->values()->all();
    }

    /** Structured database lookups this search made, with their outcomes. @return list<array<string, mixed>> */
    public static function codeLookups(int $runId): array
    {
        return ProductSourceAttempt::query()->where('telegram_update_id', $runId)->where('phase', 'code_lookup')
            ->orderBy('id')->get(['action', 'decision', 'input', 'output'])
            ->map(fn (ProductSourceAttempt $row): array => ['action' => $row->action, 'decision' => $row->decision,
                'code' => data_get($row->input, 'code'), 'title' => data_get($row->output, 'title')])
            ->take(-10)->values()->all();
    }
}
