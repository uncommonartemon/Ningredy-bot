<?php

namespace App\Services\ProductSearch\Catalogs;

use App\Models\BrandCatalog;
use App\Services\Products\ProductSourceAttemptRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The brand's own catalog, read the same way for every brand: the operator's
 * table says where it is and how a code is found there. Icecat keeps its
 * priority; a catalog is the next official source (operator, 2026-10-27).
 */
final class BrandCatalogs
{
    /** A catalog that missed this often without a single hit is switched off with a note. */
    private const int AUTO_DISABLE_MISSES = 15;

    public function __construct(private readonly ProductSourceAttemptRecorder $attempts) {}

    /** @return Collection<int, BrandCatalog> enabled catalogs whose brand or alias is named in the text */
    public function forText(string $text): Collection
    {
        $text = mb_strtolower($text);

        return $this->enabled()->filter(fn (BrandCatalog $catalog): bool => collect($catalog->names())
            ->contains(fn (string $name): bool => preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text) === 1))
            ->values();
    }

    /** @return Collection<int, BrandCatalog> */
    public function forBrand(?string $brand): Collection
    {
        $brand = mb_strtolower(trim((string) $brand));

        return $brand === '' ? collect() : $this->enabled()
            ->filter(fn (BrandCatalog $catalog): bool => in_array($brand, $catalog->names(), true))->values();
    }

    /**
     * Code-like words of a request: letters and digits together, four or more
     * characters ("83JJ000ASB", "RZ09-0508", "NX.B0ZEC.001").
     *
     * @return list<string>
     */
    public static function codesIn(string $text): array
    {
        return collect(preg_split('/[\s,;()]+/u', $text) ?: [])
            ->map(fn (string $word): string => trim($word, " \t.:#\"'"))
            ->filter(fn (string $word): bool => mb_strlen($word) >= 4 && preg_match('/\p{L}/u', $word) === 1 && preg_match('/\p{N}/u', $word) === 1)
            ->unique()->values()->all();
    }

    /**
     * What the research agent is told about each matching catalog: where it is,
     * how to look the code up, the addresses that open it, and its notes.
     *
     * @param  Collection<int, BrandCatalog>  $catalogs
     * @param  list<string>  $codes
     * @return list<array<string, mixed>>
     */
    public function hints(Collection $catalogs, array $codes, ?string $model = null): array
    {
        return $catalogs->map(fn (BrandCatalog $catalog): array => array_filter([
            'brand' => $catalog->brand,
            'catalog' => $catalog->catalog_url,
            'search' => $this->fill($catalog->search_hint, $catalog, $codes, $model, encode: false, keepUnfilled: true),
            'lookup_urls' => $this->fill($catalog->lookup_template, $catalog, $codes, $model, encode: true),
            'instructions' => $catalog->instructions,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []))->values()->all();
    }

    /**
     * Sources the brand's catalog adds after research settled on a product:
     * pages opened by the product's codes, and a public API's photos and facts.
     *
     * @param  list<string>  $codes
     * @return list<array{source: array<string, mixed>, specification_text: string}>
     */
    public function sourcesFor(?string $brand, array $codes, ?string $model, ?int $updateId): array
    {
        $found = [];
        foreach ($this->forBrand($brand) as $catalog) {
            foreach ($this->fill($catalog->lookup_template, $catalog, $codes, $model, encode: true) as $url) {
                $found[] = ['source' => ['title' => $catalog->brand.' official catalog', 'url' => $url, 'type' => 'manufacturer',
                    'roles' => ['facts', 'exact_photo'], 'image_urls' => [], '_brand_catalog' => true,
                    'applicability_reason' => 'The brand\'s own catalog page opened by the selected product code.'], 'specification_text' => ''];
            }
            foreach ($this->fill($catalog->api_template, $catalog, $codes, $model, encode: true) as $url) {
                $read = $this->readApi($catalog, $url, $updateId);
                if ($read !== null) {
                    $found[] = $read;
                    break;
                }
            }
        }

        return $found;
    }

    public function note(BrandCatalog $catalog, bool $hit, string $result): void
    {
        $catalog->forceFill([
            'hits' => $catalog->hits + (int) $hit,
            'misses' => $catalog->misses + (int) ! $hit,
            'last_used_at' => now(),
            'last_result' => mb_substr($result, 0, 255),
        ]);
        if (! $hit && $catalog->hits === 0 && $catalog->misses >= self::AUTO_DISABLE_MISSES) {
            $catalog->forceFill(['enabled' => false,
                'last_result' => mb_substr('Отключён автоматически: '.$catalog->misses.' промахов без попаданий. '.$result, 0, 255)]);
        }
        $catalog->save();
    }

    /** @return array{source: array<string, mixed>, specification_text: string}|null */
    private function readApi(BrandCatalog $catalog, string $url, ?int $updateId): ?array
    {
        $map = (array) ($catalog->response_map ?? []);
        try {
            $response = Http::timeout(10)->acceptJson()->get($url);
            $json = $response->successful() ? $response->json() : null;
        } catch (Throwable $exception) {
            $json = null;
            Log::notice('Brand catalog API was unavailable.', ['brand' => $catalog->brand, 'error' => $exception->getMessage()]);
        }
        $images = is_array($json) ? collect(self::read($json, (string) ($map['images'] ?? '')))
            ->filter(fn (mixed $image): bool => is_string($image) && preg_match('#^https?://#i', $image) === 1)->unique()->values()->all() : [];
        $title = is_array($json) ? (string) (collect(self::read($json, (string) ($map['title'] ?? '')))->first() ?? '') : '';
        $text = is_array($json) ? collect((array) ($map['text'] ?? []))
            ->flatMap(fn (mixed $path): array => self::read($json, (string) $path))
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))->implode("\n") : '';
        $hit = $images !== [] || $text !== '';
        $this->note($catalog, $hit, $hit ? 'API: фото '.count($images).', текст '.mb_strlen($text).' симв.' : 'API: ничего по коду');
        $this->attempts->record(['telegram_update_id' => $updateId, 'product_url' => $url, 'actor' => 'server',
            'phase' => 'brand_catalog', 'action' => 'read_brand_catalog_api', 'status' => 'completed',
            'decision' => $hit ? 'found' : 'not_found', 'output' => ['brand' => $catalog->brand, 'images' => count($images), 'text_length' => mb_strlen($text)]]);
        if (! $hit) {
            return null;
        }

        return ['source' => ['title' => $title !== '' ? $title : $catalog->brand.' official catalog', 'url' => $url, 'type' => 'manufacturer',
            'roles' => ['facts', 'exact_photo'], 'image_urls' => $images, '_brand_catalog' => true,
            'applicability_reason' => 'The brand\'s own catalog API answered for the selected product code.'], 'specification_text' => $text];
    }

    /**
     * Values at a dot path, with [*] for every element of a list ("data.images[*].url").
     *
     * @return list<mixed>
     */
    public static function read(array $json, string $path): array
    {
        if (trim($path) === '') {
            return [];
        }
        $values = [$json];
        foreach (explode('.', $path) as $segment) {
            $each = str_ends_with($segment, '[*]');
            $key = $each ? substr($segment, 0, -3) : $segment;
            $next = [];
            foreach ($values as $value) {
                $child = $key === '' ? $value : (is_array($value) ? Arr::get($value, $key) : null);
                if ($each) {
                    $next = [...$next, ...(is_array($child) ? array_values($child) : [])];
                } elseif ($child !== null) {
                    $next[] = $child;
                }
            }
            $values = $next;
        }

        return array_values(array_filter($values, fn (mixed $value): bool => ! is_array($value) || array_is_list($value) === false));
    }

    /**
     * A template filled with each code (or the model when it asks for {model}).
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function fill(?string $template, BrandCatalog $catalog, array $codes, ?string $model, bool $encode, bool $keepUnfilled = false): array
    {
        $template = trim((string) $template);
        if ($template === '') {
            return [];
        }
        $value = fn (string $raw): string => $encode ? rawurlencode($raw) : $raw;
        $base = str_replace('{brand}', $value((string) $catalog->brand), $template);
        // A search the agent runs itself may keep a placeholder it can fill from what it learns.
        if ($keepUnfilled && ((str_contains($base, '{code}') && $codes === []) || (str_contains($base, '{model}') && blank($model)))) {
            return [$base];
        }
        if (str_contains($base, '{code}')) {
            return collect($codes)->map(fn (string $code): string => str_replace('{code}', $value($code), $base))
                ->map(fn (string $filled): string => str_contains($filled, '{model}')
                    ? (filled($model) ? str_replace('{model}', $value((string) $model), $filled) : '') : $filled)
                ->filter()->unique()->take(3)->values()->all();
        }
        if (str_contains($base, '{model}')) {
            return filled($model) ? [str_replace('{model}', $value((string) $model), $base)] : [];
        }

        return [$base];
    }

    /** @return Collection<int, BrandCatalog> */
    private function enabled(): Collection
    {
        try {
            return BrandCatalog::query()->where('enabled', true)->orderBy('id')->get();
        } catch (Throwable $exception) {
            // The table arrives with a migration; until then there is simply no catalog.
            return collect();
        }
    }
}
