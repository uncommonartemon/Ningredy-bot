<?php

namespace App\Services\ProductSearch\Catalogs;

use App\Models\ReferenceSite;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reference sites per kind of product, read the same way for every site: the
 * operator's table says which products a site is for, what to take from it and
 * whether the bot may open it. The research agent decides which rows fit the
 * product; the server keeps what a row forbids (operator, 2026-11-02).
 */
final class ReferenceSites
{
    /** A site that missed this often without a single hit is switched off with a note. */
    private const int AUTO_DISABLE_MISSES = 15;

    /** The research answer reports one line per listed site; its schema is bounded by this. */
    public const int MAX_HINTS = 20;

    public function __construct(private readonly ProductSourceAttemptRecorder $attempts) {}

    /**
     * What the agent is told about the sites the bot opens itself.
     *
     * @param  list<string>  $codes
     * @return list<array<string, mixed>>
     */
    public function hints(array $codes, ?string $model = null, ?string $brand = null): array
    {
        return $this->enabled()->where('access', ReferenceSite::ACCESS_BOT)->take(self::MAX_HINTS)
            ->map(fn (ReferenceSite $site): array => array_filter([
                'site' => $site->domain,
                'for' => $site->applies_to,
                'take' => self::take($site),
                'search' => CodeTemplate::fill($site->search_hint, $codes, $model, $brand, encode: false, keepUnfilled: true),
                'pages' => CodeTemplate::fill($site->link_template, $codes, $model, $brand, encode: true),
                'note' => $site->instructions,
            ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []))->values()->all();
    }

    /**
     * Sites only a person opens: their links filled for this product, for the
     * operator to open and send back a screenshot or the text.
     *
     * @param  list<string>  $codes
     * @return list<array{site: string, for: string, link: string}>
     */
    public function personLinks(array $codes, ?string $model = null, ?string $brand = null): array
    {
        return $this->enabled()->where('access', ReferenceSite::ACCESS_PERSON)
            ->map(fn (ReferenceSite $site): array => ['site' => $site->domain, 'for' => $site->applies_to,
                'link' => CodeTemplate::fill($site->link_template, $codes, $model, $brand, encode: true)[0] ?? 'https://'.$site->domain])
            ->values()->all();
    }

    /**
     * Sources as the rows allow them: a facts-only site gives no photos, and a
     * site only a person opens is not queued for the bot at all.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public function restrictRoles(array $sources): array
    {
        $sites = $this->enabled();
        if ($sites->isEmpty()) {
            return $sources;
        }
        $restricted = [];
        foreach ($sources as $source) {
            $host = ProductSourcePriority::host((string) ($source['url'] ?? ''));
            $rows = $sites->filter(fn (ReferenceSite $site): bool => self::covers($site->domain, $host));
            if ($rows->isEmpty()) {
                $restricted[] = $source;

                continue;
            }
            if ($rows->every(fn (ReferenceSite $site): bool => $site->access === ReferenceSite::ACCESS_PERSON)) {
                continue;
            }
            if ($rows->contains(fn (ReferenceSite $site): bool => $site->access === ReferenceSite::ACCESS_BOT && $site->use_for_photos)) {
                $restricted[] = $source;

                continue;
            }
            // A page kept only as a photo candidate has nothing left to give on a facts-only site.
            if (! in_array('facts', (array) ($source['roles'] ?? ['facts']), true)) {
                continue;
            }
            $restricted[] = [...$source, 'roles' => ['facts'], 'image_urls' => []];
        }

        return $restricted;
    }

    /** No row forbids taking a photo from this address. */
    public function allowsPhotos(string $url): bool
    {
        $host = ProductSourcePriority::host($url);
        $rows = $this->enabled()->filter(fn (ReferenceSite $site): bool => self::covers($site->domain, $host));

        return $rows->isEmpty() || $rows->contains(fn (ReferenceSite $site): bool => $site->access === ReferenceSite::ACCESS_BOT && $site->use_for_photos);
    }

    /**
     * The agent's word on each site it considered: hits and misses for the
     * table, a line in the request's journal.
     *
     * @param  list<array{site?: mixed, result?: mixed}>  $results
     */
    public function record(array $results, ?int $updateId, string $phase = 'product_research'): void
    {
        $sites = $this->enabled()->where('access', ReferenceSite::ACCESS_BOT);
        foreach ($results as $result) {
            $domain = ReferenceSite::normalizeDomain((string) ($result['site'] ?? ''));
            $decision = (string) ($result['result'] ?? '');
            if ($domain === '' || ! in_array($decision, ['found', 'not_found'], true)) {
                continue;
            }
            $matching = $sites->filter(fn (ReferenceSite $site): bool => self::covers($site->domain, $domain));
            foreach ($matching as $site) {
                $this->note($site, $decision === 'found', $decision === 'found' ? 'Нашёл товар' : 'Товара не нашёл');
            }
            if ($matching->isNotEmpty()) {
                $this->attempts->record(['telegram_update_id' => $updateId, 'product_url' => 'https://'.$domain, 'actor' => 'web_search',
                    'phase' => 'reference_site', 'action' => 'look_up_reference_site', 'status' => 'completed',
                    'decision' => $decision, 'output' => ['site' => $domain, 'asked_by' => $phase]]);
            }
        }
    }

    /**
     * Facts written into a product from these pages: a hit for each site they came from.
     *
     * @param  list<string>  $urls
     */
    public function noteFacts(array $urls, ?int $updateId): void
    {
        $sites = $this->enabled()->where('access', ReferenceSite::ACCESS_BOT);
        $hosts = collect($urls)->map(fn (string $url): string => ProductSourcePriority::host($url))->filter()->unique();
        foreach ($sites as $site) {
            $host = $hosts->first(fn (string $host): bool => self::covers($site->domain, $host));
            if ($host !== null) {
                $this->note($site, true, 'Дал характеристики товару');
                $this->attempts->record(['telegram_update_id' => $updateId, 'product_url' => 'https://'.$host, 'actor' => 'server',
                    'phase' => 'reference_site', 'action' => 'add_product_specifications', 'status' => 'completed',
                    'decision' => 'found', 'output' => ['site' => $site->domain]]);
            }
        }
    }

    public function note(ReferenceSite $site, bool $hit, string $result): void
    {
        $site->forceFill([
            'hits' => $site->hits + (int) $hit,
            'misses' => $site->misses + (int) ! $hit,
            'last_used_at' => now(),
            'last_result' => mb_substr($result, 0, 255),
        ]);
        if (! $hit && $site->hits === 0 && $site->misses >= self::AUTO_DISABLE_MISSES) {
            $site->forceFill(['enabled' => false,
                'last_result' => mb_substr('Отключён автоматически: '.$site->misses.' промахов без попаданий. '.$result, 0, 255)]);
        }
        $site->save();
    }

    /** The host is the row's site or one of its subdomains (ark.intel.com for intel.com, never the reverse). */
    public static function covers(string $domain, string $host): bool
    {
        $host = ReferenceSite::normalizeDomain($host);

        return $domain !== '' && $host !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
    }

    private static function take(ReferenceSite $site): string
    {
        return match (true) {
            $site->use_for_facts && $site->use_for_photos => 'facts and photos',
            $site->use_for_photos => 'photos only',
            default => 'facts only, never photos',
        };
    }

    /** @return Collection<int, ReferenceSite> */
    private function enabled(): Collection
    {
        try {
            return ReferenceSite::query()->where('enabled', true)->orderBy('sort_order')->orderBy('id')->get();
        } catch (Throwable) {
            // The table arrives with a migration; until then there is simply no reference site.
            return collect();
        }
    }
}
