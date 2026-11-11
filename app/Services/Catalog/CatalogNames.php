<?php

namespace App\Services\Catalog;

use App\Ai\Agents\CatalogTranslationAgent;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Services\Ai\AiSettings;
use App\Services\Products\CatalogLocales;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The names the storefront shows beside every product: categories, groups of characteristics
 * and characteristics. One rule for all three (operator, 2026-11-11, «почему в категориях
 * каша»): each has a name in every language of the shop, and a missing one is filled here.
 *
 * Before, each kind was translated its own way, and groups were never translated at all: the
 * model that proposed a group gave an English and a Czech name, so a Ukrainian page showed
 * Czech group names between Ukrainian filters.
 */
class CatalogNames
{
    /** How many names one request to the model carries. */
    private const int NAMES_PER_CALL = 120;

    /** The same unfilled set is not sent again for this long. */
    private const int RETRY_AFTER_HOURS = 6;

    private const string UNFILLED_KEY = 'catalog-names:unfilled';

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * The languages every name must have: the English one, the shop's and the ones cards are translated into.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_values(array_unique([CatalogLocales::CONTENT_SOURCE,
            ...CatalogLocales::storefrontLocales(), ...array_keys(CatalogLocales::contentTranslations())]));
    }

    /**
     * Every name that lacks a language, keyed by a stable id.
     *
     * @return array<string, array{id: string, kind: string, text: string, locales: list<string>}>
     */
    public function missing(): array
    {
        $locales = self::locales();
        $items = [];
        $add = function (string $id, string $kind, ?string $source, array $have) use (&$items, $locales): void {
            $source = trim((string) $source);
            $lacking = array_values(array_filter($locales,
                fn (string $locale): bool => ! (is_string($have[$locale] ?? null) && trim($have[$locale]) !== '')));
            if ($source !== '' && $lacking !== []) {
                $items[$id] = ['id' => $id, 'kind' => $kind, 'text' => $source, 'locales' => $lacking];
            }
        };
        foreach (AttributeGroup::query()->orderBy('id')->get() as $group) {
            $add("group.{$group->id}", 'group_name', $group->name, $group->translations ?? []);
        }
        foreach (AttributeDefinition::query()->orderBy('id')->get() as $definition) {
            $have = $definition->translations ?? [];
            // A definition keeps its English name among its translations; its label may be Russian.
            $add("definition.{$definition->id}", 'spec_name', $have[CatalogLocales::CONTENT_SOURCE] ?? $definition->label, $have);
        }
        foreach (Category::query()->with('translations')->orderBy('id')->get() as $category) {
            $add("category.{$category->id}", 'category_name', $category->name, $category->translations->pluck('name', 'locale')->all());
        }

        return $items;
    }

    /** How many names were filled in. */
    public function fill(): int
    {
        $filled = 0;
        $items = $this->missing();
        // A name written in English is its own English name: no model is asked for it.
        foreach ($items as $id => $item) {
            if (in_array(CatalogLocales::CONTENT_SOURCE, $item['locales'], true) && preg_match('/\p{Cyrillic}/u', $item['text']) !== 1) {
                $filled += $this->write($id, [CatalogLocales::CONTENT_SOURCE => $item['text']]);
            }
        }
        $items = $this->missing();
        if ($items === [] || Cache::get(self::UNFILLED_KEY) === $this->signature($items)) {
            return $filled;
        }
        try {
            foreach (array_chunk($items, self::NAMES_PER_CALL, true) as $chunk) {
                $filled += $this->translate($chunk);
            }
        } finally {
            // What the model left unfilled, or a failed call, is not asked again every few minutes.
            $left = $this->missing();
            $left === [] ? Cache::forget(self::UNFILLED_KEY)
                : Cache::put(self::UNFILLED_KEY, $this->signature($left), now()->addHours(self::RETRY_AFTER_HOURS));
        }

        return $filled;
    }

    /** Asks again at once what an earlier request left unfilled (the admin's button). */
    public function forgetUnfilled(): void
    {
        Cache::forget(self::UNFILLED_KEY);
    }

    /** @param  array<string, array{id: string, kind: string, text: string, locales: list<string>}>  $items */
    private function translate(array $items): int
    {
        $languages = array_intersect_key(CatalogLocales::LABELS, array_flip(self::locales()));
        $run = AiRun::query()->create([
            'provider' => $this->settings->providerFor('server_assistant'), 'model' => $this->settings->modelFor('server_assistant'),
            'status' => 'running', 'prompt' => mb_substr(json_encode(array_values($items), JSON_UNESCAPED_UNICODE), 0, 60000), 'started_at' => now(),
            'activity' => ['stage' => 'catalog_names', 'names' => count($items)],
        ]);
        try {
            $response = (new CatalogTranslationAgent($languages))->prompt(
                json_encode(['items' => array_values($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                provider: $run->provider, model: $run->model, timeout: 180);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);

            throw $exception;
        }
        $run->update(['status' => 'completed', 'usage' => $response->usage->toArray(), 'invocation_id' => $response->invocationId,
            'response' => $response->toArray(), 'completed_at' => now()]);

        $translated = [];
        foreach ((array) ($response->toArray()['translations'] ?? []) as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $locale = (string) ($entry['locale'] ?? '');
            $text = trim((string) ($entry['text'] ?? ''));
            if (isset($items[$id]) && in_array($locale, $items[$id]['locales'], true) && $text !== '' && mb_strlen($text) <= 255) {
                $translated[$id][$locale] = $text;
            }
        }
        $filled = 0;
        foreach ($translated as $id => $names) {
            $filled += $this->write($id, $names);
        }

        return $filled;
    }

    /**
     * Adds names in languages that have none; a name someone already gave is never replaced.
     *
     * @param  array<string, string>  $names
     */
    private function write(string $id, array $names): int
    {
        [$kind, $key] = explode('.', $id, 2);
        if ($kind === 'category') {
            $category = Category::query()->find((int) $key);
            $written = 0;
            foreach ($category === null ? [] : $names as $locale => $name) {
                $written += (int) $category->translations()->firstOrCreate(['locale' => $locale], ['name' => $name])->wasRecentlyCreated;
            }

            return $written;
        }
        $model = ($kind === 'group' ? AttributeGroup::query() : AttributeDefinition::query())->find((int) $key);
        if ($model === null) {
            return 0;
        }
        $current = array_filter($model->translations ?? [], fn (mixed $text): bool => is_string($text) && trim($text) !== '');
        $added = array_diff_key($names, $current);
        if ($added !== []) {
            $model->forceFill(['translations' => [...$current, ...$added]])->saveQuietly();
        }

        return count($added);
    }

    /** @param  array<string, array{id: string, kind: string, text: string, locales: list<string>}>  $items */
    private function signature(array $items): string
    {
        return hash('sha256', (string) json_encode(array_map(
            fn (array $item): array => [$item['id'], $item['text'], $item['locales']], array_values($items)), JSON_UNESCAPED_UNICODE));
    }
}
