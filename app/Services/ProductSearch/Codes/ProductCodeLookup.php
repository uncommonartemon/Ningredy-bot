<?php

namespace App\Services\ProductSearch\Codes;

use App\Models\ProductSourceAttempt;
use App\Services\Products\ProductSourceAttemptRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Structured product databases looked up by code: Open Icecat (official
 * specifications and gallery for sponsoring brands) and UPCitemdb (barcode to
 * brand/model). Results are hints an agent may use or reject - never a
 * verdict - and every failure is recorded and swallowed so a search always
 * continues on its ordinary path (operator decision 2026-10-19).
 */
final class ProductCodeLookup
{
    public const SOURCES = ['icecat', 'upcitemdb'];

    public function __construct(private readonly ProductSourceAttemptRecorder $attempts) {}

    /** Valid GTIN-8/12/13/14 codes in free text, by check digit. @return array<int, string> */
    public static function gtins(string $text): array
    {
        preg_match_all('/(?<!\d)(\d{8}|\d{12,14})(?!\d)/', $text, $matches);

        return array_values(array_unique(array_filter($matches[1], fn (string $code): bool => self::validGtin($code))));
    }

    public static function validGtin(string $code): bool
    {
        if (! preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return false;
        }
        $digits = array_map('intval', str_split($code));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /**
     * Every code tried in turn; an EAN that UPCitemdb maps to brand+model is
     * also tried on Icecat by that part code. @return array<int, array<string, mixed>>
     */
    public function lookup(array $gtins, ?string $brand, array $partCodes, ?int $updateId, string $caller): array
    {
        $results = [];
        foreach (array_slice(array_values(array_unique(array_filter($gtins))), 0, 3) as $gtin) {
            $icecat = $this->icecat($gtin, null, null, $updateId, $caller);
            $results[] = $icecat;
            if (! $icecat['found']) {
                $upc = $this->upcitemdb($gtin, $updateId, $caller);
                $results[] = $upc;
                if ($upc['found'] && filled($upc['brand'] ?? null) && filled($upc['part_number'] ?? null)) {
                    $results[] = $this->icecat(null, $upc['brand'], $upc['part_number'], $updateId, $caller);
                }
            }
        }
        if (filled($brand)) {
            // A localisation or option suffix after "#" (AA3B6UA#ABA, live #453) is not
            // part of the datasheet's code, so the base code is asked too.
            $parts = collect($partCodes)->filter(fn ($code): bool => is_string($code) && trim($code) !== '')
                ->flatMap(fn (string $code): array => str_contains($code, '#') ? [$code, trim((string) strstr($code, '#', true))] : [$code])
                // A phrase is not a code (live #370: a configured model went out as ProductCode and
                // the API answered "Exceed max string length").
                ->filter(fn (string $code): bool => mb_strlen(trim($code)) <= 40)
                ->filter()->unique()->take(4)->values()->all();
            foreach ($parts as $part) {
                if (collect($results)->contains(fn (array $result): bool => $result['found'] && $result['source'] === 'icecat')) {
                    break;
                }
                $results[] = $this->icecat(null, $brand, $part, $updateId, $caller);
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function icecat(?string $gtin, ?string $brand, ?string $partCode, ?int $updateId, string $caller): array
    {
        $code = $gtin ?? trim((string) $brand).' '.trim((string) $partCode);
        $query = $gtin !== null ? ['GTIN' => $gtin] : ['Brand' => (string) $brand, 'ProductCode' => (string) $partCode];

        // The code asked matters: Icecat files ASUS GA403UV-G14.R94060 under part 90NR0I02-M003W0 (live #456).
        return [...$this->icecatQuery($query, $code, $brand, $updateId, $caller), 'queried_part' => $partCode];
    }

    /**
     * A datasheet by its own number: the page the research found by name is read
     * through the API, never fetched (the site answers a plain request with a bot
     * check). Operator, 2026-10-30: Icecat first for a name without a code too.
     *
     * @return array<string, mixed>
     */
    public function icecatById(string $id, ?int $updateId, string $caller): array
    {
        return $this->icecatQuery(['icecat_id' => $id], 'icecat_id '.$id, null, $updateId, $caller);
    }

    /** The datasheet number in the address of a page on the catalogue's own site (`…/stealth-14-…-120744916.html`). */
    public static function icecatIdFromUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $site = strtolower(trim((string) config('services.icecat.site', 'icecat.biz')));
        if ($site === '' || ($host !== $site && ! str_ends_with($host, '.'.$site))) {
            return null;
        }

        return preg_match('~-(\d{5,})\.html?$~', (string) parse_url($url, PHP_URL_PATH), $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function icecatQuery(array $query, string $code, ?string $brand, ?int $updateId, string $caller): array
    {
        $username = (string) config('services.icecat.username');
        if ($username === '') {
            return $this->outcome('icecat', $code, 'not_configured', [], $updateId, $caller);
        }
        $query = ['UserName' => $username, 'Language' => 'en', ...$query];
        $key = 'code-lookup:icecat:'.md5(json_encode($query));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $this->outcome('icecat', $code, $cached['status'], $cached['data'], $updateId, $caller, cached: true, brand: $brand);
        }
        try {
            $response = Http::timeout(20)->acceptJson()->get((string) config('services.icecat.url'), $query);
            $body = $response->json() ?? [];
            $message = (string) ($body['Message'] ?? '');
            [$status, $data] = match (true) {
                $response->successful() && is_array($body['data']['GeneralInfo'] ?? null) => ['found', $this->icecatData($body['data'])],
                str_contains($message, 'app_key') || str_contains($message, 'restriction') => ['restricted', ['message' => $message]],
                // An unknown GTIN comes back as 400 "The GTIN can not be found" (live #443), not 404.
                $response->status() === 404, $response->status() === 400 && str_contains(strtolower($message), 'not be found') => ['not_found', ['message' => $message]],
                default => ['error', ['message' => $message ?: 'HTTP '.$response->status()]],
            };
        } catch (Throwable $exception) {
            [$status, $data] = ['error', ['message' => mb_substr($exception->getMessage(), 0, 200)]];
        }
        if ($status !== 'error') {
            Cache::put($key, ['status' => $status, 'data' => $data], now()->addDays(30));
        }

        return $this->outcome('icecat', $code, $status, $data, $updateId, $caller, brand: $brand);
    }

    /** @return array<string, mixed> */
    public function upcitemdb(string $gtin, ?int $updateId, string $caller): array
    {
        $key = 'code-lookup:upcitemdb:'.$gtin;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $this->outcome('upcitemdb', $gtin, $cached['status'], $cached['data'], $updateId, $caller, cached: true);
        }
        $quotaKey = 'code-lookup:upcitemdb:calls:'.now()->format('Y-m-d');
        if ((int) Cache::get($quotaKey, 0) >= (int) config('services.upcitemdb.daily_limit', 90)) {
            return $this->outcome('upcitemdb', $gtin, 'quota', [], $updateId, $caller);
        }
        Cache::put($quotaKey, (int) Cache::get($quotaKey, 0) + 1, now()->endOfDay());
        try {
            $response = Http::timeout(20)->acceptJson()->get((string) config('services.upcitemdb.url'), ['upc' => $gtin]);
            $item = $response->json('items.0');
            [$status, $data] = match (true) {
                $response->status() === 429 => ['quota', []],
                $response->successful() && is_array($item) => ['found', [
                    'title' => (string) ($item['title'] ?? ''), 'brand' => (string) ($item['brand'] ?? ''),
                    'part_number' => (string) ($item['model'] ?? ''), 'gtins' => array_values(array_filter([$item['ean'] ?? null, $item['upc'] ?? null])),
                    'color' => (string) ($item['color'] ?? ''), 'description' => mb_substr(strip_tags((string) ($item['description'] ?? '')), 0, 400),
                    'images' => array_values(array_slice(array_filter((array) ($item['images'] ?? []), 'is_string'), 0, 8)),
                    'merchants' => array_values(array_slice(array_unique(array_filter(array_column((array) ($item['offers'] ?? []), 'merchant'))), 0, 8)),
                ]],
                $response->successful() => ['not_found', []],
                default => ['error', ['message' => 'HTTP '.$response->status()]],
            };
        } catch (Throwable $exception) {
            [$status, $data] = ['error', ['message' => mb_substr($exception->getMessage(), 0, 200)]];
        }
        if (in_array($status, ['found', 'not_found'], true)) {
            Cache::put($key, ['status' => $status, 'data' => $data], now()->addDays(30));
        }

        return $this->outcome('upcitemdb', $gtin, $status, $data, $updateId, $caller);
    }

    /** How each database did recently, so an agent can judge whether asking is worth it. */
    public function trackRecord(int $days = 30): array
    {
        // A cached answer repeats an earlier call; counting it would inflate the record.
        $rows = ProductSourceAttempt::query()->where('phase', 'code_lookup')->where('created_at', '>=', now()->subDays($days))
            ->get(['action', 'decision', 'input'])->reject(fn (ProductSourceAttempt $row): bool => (bool) data_get($row->input, 'cached'));
        $record = [];
        foreach (self::SOURCES as $source) {
            $mine = $rows->where('action', 'lookup_'.$source);
            $record[$source] = ['calls' => $mine->count(), 'found' => $mine->where('decision', 'found')->count(),
                'not_found' => $mine->where('decision', 'not_found')->count(),
                'restricted_or_quota' => $mine->whereIn('decision', ['restricted', 'quota'])->count(),
                'errors' => $mine->where('decision', 'error')->count()];
        }
        $verdicts = ProductSourceAttempt::query()->where('phase', 'code_lookup')->where('action', 'agent_verdict')
            ->where('created_at', '>=', now()->subDays($days))->get(['decision']);
        $record['agent_verdicts'] = ['used' => $verdicts->where('decision', 'used')->count(),
            'ignored' => $verdicts->where('decision', 'ignored')->count()];
        // Brands whose datasheets need a paid Icecat plan: asking again by part code returns the same.
        $record['icecat_closed_brands'] = $rows->where('action', 'lookup_icecat')->where('decision', 'restricted')
            ->map(fn (ProductSourceAttempt $row): string => trim((string) data_get($row->input, 'brand')))
            ->filter()->unique(fn (string $brand): string => mb_strtolower($brand))->values()->all();

        return $record;
    }

    /** The agent's own use-or-ignore decision about lookup results. */
    public function recordVerdict(?int $updateId, string $decision, string $reason, string $caller): void
    {
        $this->attempts->record(['telegram_update_id' => $updateId, 'product_url' => 'https://icecat.biz/',
            'actor' => 'agent', 'phase' => 'code_lookup', 'action' => 'agent_verdict', 'status' => 'completed',
            'decision' => in_array($decision, ['used', 'ignored', 'not_applicable'], true) ? $decision : 'not_applicable',
            'message' => mb_substr($reason, 0, 500), 'input' => ['caller' => $caller]]);
    }

    /** @return array<string, mixed> */
    private function icecatData(array $data): array
    {
        $general = $data['GeneralInfo'];
        $brand = (string) ($general['Brand'] ?? '');
        $part = (string) ($general['BrandPartCode'] ?? '');
        $id = (string) ($general['IcecatId'] ?? '');
        $images = collect((array) ($data['Gallery'] ?? []))
            ->filter(fn (mixed $image): bool => is_array($image) && ($image['IsPrivate'] ?? '0') !== '1' && filled($image['Pic'] ?? null))
            ->sortBy(fn (array $image): int => (int) ($image['No'] ?? 0))
            ->map(fn (array $image): array => ['url' => (string) $image['Pic'], 'width' => (int) ($image['PicWidth'] ?? 0),
                'height' => (int) ($image['PicHeight'] ?? 0)])
            // The whole public gallery: a cap of twelve here left 49 official frames
            // never downloaded over 35 live searches, while the card wanted ten after
            // the size, duplicate and Vision checks had thinned the first twelve.
            ->values()->all();
        $specifications = [];
        foreach ((array) ($data['FeaturesGroups'] ?? []) as $group) {
            $groupName = (string) data_get($group, 'FeatureGroup.Name.Value', '');
            foreach ((array) ($group['Features'] ?? []) as $feature) {
                $value = trim((string) ($feature['PresentationValue'] ?? $feature['Value'] ?? ''));
                $name = trim((string) data_get($feature, 'Feature.Name.Value', ''));
                if ($value !== '' && $name !== '') {
                    $specifications[] = ($groupName !== '' ? $groupName.' · ' : '').$name.': '.$value;
                }
            }
        }
        $slug = static fn (string $text): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', Str::lower(Str::ascii($text))), '-');

        return [
            'title' => (string) ($general['Title'] ?? ''), 'brand' => $brand, 'part_number' => $part,
            'gtins' => array_values(array_filter((array) ($general['GTIN'] ?? []), 'is_string')),
            'product_name' => (string) ($general['ProductName'] ?? ''), 'icecat_id' => $id,
            'page_url' => $id !== '' && $brand !== '' && $part !== ''
                ? 'https://icecat.biz/p/'.$slug($brand).'/'.$slug($part).'/'.($slug((string) ($general['ProductName'] ?? 'product')) ?: 'product').'-'.$id.'.html'
                : null,
            'manufacturer_url' => data_get($general, 'Description.URL') ?: null,
            'description' => mb_substr(trim(strip_tags((string) data_get($general, 'Description.LongDesc', ''))), 0, 600),
            'images' => $images,
            'specifications' => array_slice($specifications, 0, 120),
        ];
    }

    /** @return array<string, mixed> */
    private function outcome(string $source, string $code, string $status, array $data, ?int $updateId, string $caller, bool $cached = false, ?string $brand = null): array
    {
        $this->attempts->record(['telegram_update_id' => $updateId,
            'product_url' => (string) ($data['page_url'] ?? ($source === 'icecat' ? 'https://icecat.biz/' : 'https://www.upcitemdb.com/')),
            'actor' => 'code_lookup', 'phase' => 'code_lookup', 'action' => 'lookup_'.$source,
            'status' => in_array($status, ['found', 'not_found'], true) ? 'completed' : 'skipped', 'decision' => $status,
            'message' => $data['message'] ?? null,
            'input' => ['code' => $code, 'caller' => $caller, 'cached' => $cached,
                'brand' => ($data['brand'] ?? null) ?: ($brand ?? null)],
            'output' => array_intersect_key($data, array_flip(['title', 'brand', 'part_number', 'gtins', 'page_url']))
                + ['image_count' => count((array) ($data['images'] ?? [])), 'specification_count' => count((array) ($data['specifications'] ?? []))]]);

        return ['source' => $source, 'code' => $code, 'found' => $status === 'found', 'status' => $status, ...$data];
    }
}
