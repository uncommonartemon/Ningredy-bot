<?php

namespace App\Services\ProductSearch;

use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Photos\ImageUrls;
use App\Services\Products\ProductImageCandidateDiscovery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** What a running search already did, in the compact form agents read. */
final class SearchJournal
{
    /** Rows the watcher and the guardian write about a search, never steps of it. */
    public const OWN_PHASES = ['search_supervision', 'guardian', 'delivery_guard'];

    /** Figures a step records that earn a place on its timeline line. */
    private const FIGURES = ['static_count', 'browser_count', 'returned_count', 'downloaded_images', 'unique_images',
        'accepted_images', 'expected_image_count', 'saved_file_count', 'removed', 'kept', 'photos'];

    /** The newest step the search itself recorded: a look at the same mark would read the same picture. */
    public static function mark(int $runId): int
    {
        return (int) ProductSourceAttempt::query()->where('telegram_update_id', $runId)
            ->whereNotIn('phase', self::OWN_PHASES)->max('id');
    }

    /**
     * The search's story in order, one short line per step. The watcher reads
     * what happened instead of counters that start again on every page: live
     * #58's watcher read "the same frames two rounds" on a second page of a
     * site whose first page had shown those five frames for seven rounds.
     * A frame set carries a short tag; the same tag is the same pictures.
     *
     * @return list<string>
     */
    public static function timeline(int $runId, int $limit = 40): array
    {
        $start = TelegramUpdate::query()->find($runId)?->created_at;
        $lines = self::story($runId)
            // The watcher's "continue" is its silence; its other answers belong to the story.
            ->reject(fn (object $row): bool => $row->phase === 'search_supervision' && $row->decision === 'continue')
            ->map(fn (object $row): string => self::line($row, $start))->values();
        $hidden = max(0, $lines->count() - $limit);

        return [...($hidden > 0 ? ["({$hidden} earlier steps not shown)"] : []), ...$lines->slice($hidden)->values()->all()];
    }

    /**
     * Outcomes the search keeps getting again: how many times, on how many
     * pages, over which minutes, and how many photos that site had accepted.
     * Evidence for the watcher, never a verdict of its own.
     *
     * @return list<array{site: string, step: string, outcome: string, times: int, pages: int, minutes: string, photos_accepted_on_site: int}>
     */
    public static function repeats(int $runId, int $limit = 6): array
    {
        $start = TelegramUpdate::query()->find($runId)?->created_at;
        $steps = self::story($runId)->reject(fn (object $row): bool => in_array($row->phase, self::OWN_PHASES, true));
        $accepted = $steps->where('phase', 'image_verification')
            ->groupBy(fn (object $row): string => self::host($row->product_url))
            ->map(fn (Collection $rows): int => (int) $rows->sum(fn (object $row): int => (int) $row->accepted_images));

        return $steps->groupBy(fn (object $row): string => self::host($row->product_url).'|'.$row->phase.'.'.$row->action.'|'.self::outcome($row))
            ->filter(fn (Collection $rows): bool => $rows->count() >= 3)
            ->sortByDesc(fn (Collection $rows): int => $rows->count())->take($limit)
            ->map(function (Collection $rows) use ($start, $accepted): array {
                $first = $rows->first();
                $site = self::host($first->product_url);

                return ['site' => $site, 'step' => $first->phase.'.'.$first->action, 'outcome' => self::outcome($first),
                    'times' => $rows->count(), 'pages' => $rows->pluck('product_url')->filter()->unique()->count(),
                    'minutes' => self::minute($first->created_at, $start).'-'.self::minute($rows->last()->created_at, $start),
                    'photos_accepted_on_site' => (int) ($accepted[$site] ?? 0)];
            })->values()->all();
    }

    /**
     * AI calls of this search still open, and for how long: the step a silent
     * search is inside. The outer assistant and the watcher itself always are.
     *
     * @return list<array{stage: string, minutes_open: float}>
     */
    public static function openCalls(int $runId): array
    {
        return AiRun::query()->toBase()->where('telegram_update_id', $runId)->where('status', 'running')
            ->orderBy('id')->get(['activity->stage as stage', 'created_at'])
            ->map(fn (object $run): array => ['stage' => (string) ($run->stage ?? 'unknown'),
                'minutes_open' => round(abs(Carbon::parse($run->created_at)->diffInSeconds(now())) / 60, 1)])
            ->reject(fn (array $call): bool => in_array($call['stage'], ['assistant', 'search_progress_supervisor'], true))
            ->values()->all();
    }

    /**
     * The steps worth telling, with only the figures a line needs: a training
     * round's own record runs to a megabyte of page diagnostics.
     *
     * @return Collection<int, object>
     */
    private static function story(int $runId): Collection
    {
        return ProductSourceAttempt::query()->toBase()->where('telegram_update_id', $runId)
            // Candidate lists, queue ranking and code lookups have summaries of their own.
            ->whereNotIn('phase', ['source_queue', 'code_lookup'])->orderBy('id')
            ->get(['id', 'product_url', 'actor', 'phase', 'action', 'status', 'decision', 'message', 'created_at',
                'output->images as images', 'output->failure_kind as failure_kind',
                ...array_map(fn (string $figure): string => "output->{$figure} as {$figure}", self::FIGURES)])
            ->reject(fn (object $row): bool => str_ends_with((string) $row->action, '_started')
                || ($row->phase === 'gallery_training' && $row->actor === 'playwright')
                || ($row->phase === 'product_research' && $row->action === 'candidate_source'))
            ->values();
    }

    private static function line(object $row, mixed $start): string
    {
        $facts = array_values(array_filter([
            self::frames($row) === null ? null : self::outcome($row),
            ...array_map(fn (string $figure): ?string => is_numeric($row->{$figure} ?? null) ? $figure.' '.(int) $row->{$figure} : null, self::FIGURES),
            self::frames($row) === null && filled($row->failure_kind ?? null) ? 'failure '.$row->failure_kind : null,
            in_array($row->phase, self::OWN_PHASES, true) && filled($row->message) ? '«'.Str::limit((string) $row->message, 110).'»' : null,
        ]));

        return self::minute($row->created_at, $start).' '.(self::host($row->product_url) ?: '-').' '.$row->phase.'.'.$row->action
            .': '.($row->decision ?: $row->status).($facts === [] ? '' : ' · '.implode(' · ', $facts));
    }

    /** @return ?list<string> what a training round collected, as image identities; null for any other step */
    private static function frames(object $row): ?array
    {
        if ($row->action !== 'propose_recipe') {
            return null;
        }
        $images = json_decode((string) ($row->images ?? '[]'), true);

        return collect(is_array($images) ? $images : [])->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->map(fn (string $url): string => ImageUrls::imageAssetKey($url))->unique()->sort()->values()->all();
    }

    /** Same outcome, same text: a training round by the pictures it collected, any other step by its result. */
    private static function outcome(object $row): string
    {
        $frames = self::frames($row);
        $failure = filled($row->failure_kind ?? null) ? ' · failure '.$row->failure_kind : '';

        return match (true) {
            $frames === null => ($row->decision ?: $row->status).$failure,
            $frames === [] => 'no frames'.$failure,
            // The tag is the training round's own frame-set hash, shortened.
            default => count($frames).' frames ['.substr(hash('sha256', json_encode($frames)), 0, 4).']',
        };
    }

    private static function host(mixed $url): string
    {
        return strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    }

    private static function minute(mixed $at, mixed $start): string
    {
        return $at === null || $start === null ? '?'
            : number_format(abs(Carbon::parse($start)->diffInSeconds(Carbon::parse($at))) / 60, 1);
    }

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

        // What each page gave, not only that it was visited: with nothing but
        // rounds and saved photos, live #1's watcher read five refused or
        // thin pages as "no pages checked yet" and missed a verified photo
        // that was held, not yet saved.
        $verdicts = ['assess_photo_applicability', 'validate_scout_product_identity'];

        return ProductSourceAttempt::query()->where('telegram_update_id', $runId)
            ->whereIn('phase', ['source_selection', 'gallery_preflight', 'gallery_training', 'source_resolution', 'image_download', 'fallback_image_download', 'image_verification'])
            ->orderBy('id')->get(['product_url', 'phase', 'action', 'decision', 'output'])
            ->groupBy(fn (ProductSourceAttempt $attempt): string => (string) parse_url((string) $attempt->product_url, PHP_URL_HOST))
            ->filter(fn ($rows, string $host): bool => $host !== '' && $rows->contains(fn (ProductSourceAttempt $row): bool => $row->phase !== 'source_selection'))
            ->map(function ($rows, string $host) use ($photosByHost, $endedByAgent, $verdicts): array {
                $steps = $rows->where('phase', '!=', 'source_selection');

                return ['host' => $host, 'site_name' => ProductImageCandidateDiscovery::siteName('https://'.$host),
                    'training_rounds' => $rows->where('action', 'propose_recipe')->count(),
                    'images_downloaded' => (int) $rows->sum(fn (ProductSourceAttempt $row): int => (int) data_get($row->output, 'downloaded_images', 0)),
                    'photos_accepted' => (int) $rows->where('phase', 'image_verification')
                        ->sum(fn (ProductSourceAttempt $row): int => (int) data_get($row->output, 'accepted_images', 0)),
                    'photos_saved' => (int) ($photosByHost[$host] ?? 0),
                    // Why the site's downloads were dropped: a site whose frames are all
                    // too_small only has thumbnails (live #87: 38 such frames on two shops,
                    // #161: 34 on five; the watcher read "downloaded, nothing accepted" as work).
                    'rejections' => $rows->whereIn('phase', ['image_download', 'fallback_image_download'])
                        ->flatMap(fn (ProductSourceAttempt $row): array => (array) data_get($row->output, 'rejection_summary', []))
                        ->groupBy(fn (int $count, string $reason): string => Str::before($reason, ' ('))
                        ->map(fn ($counts): int => (int) $counts->sum())->sortDesc()->take(4)->all(),
                    'last_step' => $steps->last()->action.': '.($steps->last()->decision ?? '-'),
                    'ended_by_agent' => $endedByAgent[$host] ?? null,
                    // Live #7: an exact-SKU page with a seven-frame gallery whose
                    // collection had just begun looked like any other empty page.
                    'photo_match' => $rows->whereIn('action', $verdicts)->last()?->decision,
                    'expected_frames' => $rows->where('action', 'classify_gallery')
                        ->map(fn (ProductSourceAttempt $row): ?int => data_get($row->output, 'expected_image_count'))->filter()->max()];
            })
            ->values()->slice(-$limit)->values()->all();
    }

    /**
     * Where the request is: still looking for photos, or already assembling the
     * card from what it found. A verdict about the photo search means nothing
     * once assembly has begun (live #168, #173: "deliver_now" during assembly).
     */
    public static function phase(int $runId): string
    {
        return AiRun::query()->where('telegram_update_id', $runId)->where('status', 'running')
            ->whereIn('activity->stage', ['card_assembly', 'card_repair', 'text_evidence'])->exists()
            ? 'card_assembly' : 'photo_search';
    }

    /** How long the search has been busy without Vision accepting a photo; null before the first. */
    public static function minutesSinceLastAcceptedPhoto(int $runId): ?float
    {
        $accepted = ProductSourceAttempt::query()->where('telegram_update_id', $runId)
            ->where('phase', 'image_verification')->where('output->accepted_images', '>', 0)->max('created_at');

        return $accepted === null ? null : round(Carbon::parse($accepted)->diffInSeconds(now()) / 60, 1);
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
