<?php

namespace App\Services\Products;

use App\Ai\Agents\CatalogTranslationAgent;
use App\Models\AiRun;
use App\Models\Product;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fills the translations a catalog product's own texts lack in the shop's languages: its
 * title and description, the names and colours of its configurations and the values of its
 * characteristics. Which texts lack which language is decided by CardTranslationState; names
 * shared by many products are CatalogNames' work. Nothing that already has a translation is
 * touched, and an answer that cannot stand as a translation is not stored, so it is asked again.
 */
final class CatalogTextTranslator
{
    /** The same missing texts of a product are not asked again for this long. */
    private const int RETRY_AFTER_HOURS = 6;

    /**
     * How much text one request carries: characters of source times the languages asked, plus a
     * little for each entry. The answer is bounded (CatalogTranslationAgent::MAX_OUTPUT_TOKENS);
     * a long card asked in one request could come back cut off and fail whole, every time.
     */
    private const int CHARACTERS_PER_CALL = 12_000;

    private const int CHARACTERS_PER_ENTRY = 40;

    public function __construct(private readonly AiSettings $settings, private readonly CardTranslationState $state) {}

    /**
     * Products with any text a buyer reads lacking a shop language.
     *
     * @return array<int, int>
     */
    public function pendingProductIds(): array
    {
        return $this->state->pendingProductIds();
    }

    /**
     * The pending products the scheduler may ask about now. Translations were made only when
     * something asked for them, so one failed or interrupted request (a worker stopped at the
     * end of an evening) left a card untranslated for good. The scheduler asks again; what it
     * asked about and the model left unfilled, or a call that failed, waits some hours, so a
     * text that will not translate does not cost a request every few minutes. A product whose
     * missing texts changed since is asked at once. A product that lacks only shared names
     * waits for CatalogNames, which asks for them once for the whole catalog.
     *
     * @return array<int, int>
     */
    public function dueProductIds(): array
    {
        return collect($this->pendingProductIds())
            ->filter(fn (int $id): bool => ($product = Product::query()->find($id)) !== null && $this->isDue($product))->values()->all();
    }

    /** Whether the product's own missing texts were not asked about within the last hours. */
    public function isDue(Product $product): bool
    {
        $items = $this->cardItems($product);

        return $items !== [] && Cache::get(self::askedKey($product->id)) !== self::signature($items);
    }

    /** Whether any text of this product a buyer reads lacks a shop language. */
    public function lacksTranslations(Product $product): bool
    {
        return $this->state->lacks($product);
    }

    /** How many texts were filled in. */
    public function translate(Product $product): int
    {
        $languages = CatalogLocales::contentTranslations();
        $items = $this->cardItems($product);
        if ($items === []) {
            Cache::forget(self::askedKey($product->id));

            return $this->mirrorIntoVariants($product->fresh(['variants']), array_keys($languages));
        }
        // Asked now: until this request ends with everything filled, the scheduler does not ask
        // the same again for some hours. A failed call keeps the mark; a worker stopped
        // mid-call leaves it too, and the next catch-up after the wait asks again.
        Cache::put(self::askedKey($product->id), self::signature($items), now()->addHours(self::RETRY_AFTER_HOURS));

        $filled = 0;
        $failure = null;
        foreach ($this->batches($items) as $batch) {
            try {
                $filled += $this->write($product, $batch, $this->ask($product, $batch, $languages));
            } catch (Throwable $exception) {
                // The other requests are still made: one failed answer does not leave the whole card.
                $failure ??= $exception;
            }
        }

        $filled += $this->mirrorIntoVariants($product->fresh(['variants']), array_keys($languages));
        $left = $this->cardItems($product->fresh());
        $left === [] ? Cache::forget(self::askedKey($product->id))
            : Cache::put(self::askedKey($product->id), self::signature($left), now()->addHours(self::RETRY_AFTER_HOURS));
        if ($failure !== null) {
            throw $failure;
        }

        return $filled;
    }

    /**
     * The product's own texts a request carries: shared names are left to CatalogNames.
     *
     * @return array<string, array{id: string, kind: string, text: string, locales: list<string>, target: mixed, field: ?string, shared: bool}>
     */
    private function cardItems(Product $product): array
    {
        return array_filter($this->state->missing($product), fn (array $item): bool => ! $item['shared']);
    }

    /**
     * The same text is asked once (a configuration named by its colour, «Yes» in ten values),
     * and the texts are cut into requests whose answers fit.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @return list<array<string, array{entry: array{id: string, kind: string, text: string, locales: list<string>}, items: list<array<string, mixed>>}>>
     */
    private function batches(array $items): array
    {
        $byText = [];
        foreach ($items as $item) {
            $key = $item['text'];
            $byText[$key] ??= ['entry' => ['id' => $item['id'], 'kind' => $item['kind'], 'text' => $item['text'], 'locales' => []], 'items' => []];
            $byText[$key]['entry']['locales'] = array_values(array_unique([...$byText[$key]['entry']['locales'], ...$item['locales']]));
            $byText[$key]['items'][] = $item;
        }
        $batches = [];
        $batch = [];
        $size = 0;
        foreach ($byText as $group) {
            $cost = (mb_strlen($group['entry']['text']) + self::CHARACTERS_PER_ENTRY) * count($group['entry']['locales']);
            if ($batch !== [] && $size + $cost > self::CHARACTERS_PER_CALL) {
                $batches[] = $batch;
                [$batch, $size] = [[], 0];
            }
            $batch[$group['entry']['id']] = $group;
            $size += $cost;
        }

        return $batch === [] ? $batches : [...$batches, $batch];
    }

    /**
     * @param  array<string, array{entry: array<string, mixed>, items: list<array<string, mixed>>}>  $batch
     * @param  array<string, string>  $languages
     * @return array<string, array<string, string>> entry id => locale => text, only answers that can stand
     */
    private function ask(Product $product, array $batch, array $languages): array
    {
        $entries = array_values(array_map(fn (array $group): array => $group['entry'], $batch));
        $run = AiRun::query()->create([
            'provider' => $this->settings->providerFor('server_assistant'), 'model' => $this->settings->modelFor('server_assistant'),
            'status' => 'running', 'prompt' => mb_substr(json_encode($entries, JSON_UNESCAPED_UNICODE), 0, 60000), 'started_at' => now(),
            'activity' => ['stage' => 'catalog_translation', 'product_id' => $product->id],
        ]);
        try {
            $response = (new CatalogTranslationAgent($languages))->prompt(
                json_encode(['items' => $entries], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                provider: $run->provider, model: $run->model, timeout: 180);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);

            throw $exception;
        }
        $run->update(['status' => 'completed', 'usage' => $response->usage->toArray(), 'invocation_id' => $response->invocationId,
            'response' => $response->toArray(), 'completed_at' => now()]);

        $answers = [];
        foreach ((array) ($response->toArray()['translations'] ?? []) as $answer) {
            $id = (string) ($answer['id'] ?? '');
            $locale = (string) ($answer['locale'] ?? '');
            $text = trim((string) ($answer['text'] ?? ''));
            $entry = $batch[$id]['entry'] ?? null;
            if ($entry !== null && in_array($locale, $entry['locales'], true)
                && CardTranslationState::acceptable($entry['kind'], $entry['text'], $text, $locale)) {
                $answers[$id][$locale] = $text;
            }
        }

        return $answers;
    }

    private static function askedKey(int $productId): string
    {
        return 'catalog-text:asked:'.$productId;
    }

    /** @param array<string, array{id: string, text: string, locales: array<int, string>}> $items */
    private static function signature(array $items): string
    {
        return sha1(json_encode(array_values(array_map(fn (array $item): array => [$item['id'], $item['text'], $item['locales']], $items)), JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * A variant's translations are the product's own text for that variant (the draft
     * workflow copies the card's translations there; the storefront shows them over the
     * product's). A variant missing a language takes the product's text in it - never a
     * translation of its configuration name (live 2026-11-01: «Base configuration»
     * translated into the title made every Ukrainian catalog card read «Базова конфігурація»).
     *
     * @param  array<int, string>  $locales
     */
    private function mirrorIntoVariants(Product $product, array $locales): int
    {
        $filled = 0;
        foreach ($product->variants as $variant) {
            $translations = $variant->translations ?? [];
            foreach ($locales as $locale) {
                foreach (['title', 'description'] as $field) {
                    $text = $product->translations[$locale][$field] ?? null;
                    if (filled($text) && ! filled($translations[$locale][$field] ?? null)) {
                        $translations[$locale][$field] = $text;
                        $filled++;
                    }
                }
            }
            if ($translations !== ($variant->translations ?? [])) {
                $variant->update(['translations' => $translations]);
            }
        }

        return $filled;
    }

    /**
     * Writes each answer into every text it was asked for, in the languages that text lacked.
     * A text of a card (title, a configuration's name) is one field of a language's entry;
     * a characteristic's value is the entry itself.
     *
     * @param  array<string, array{entry: array<string, mixed>, items: list<array<string, mixed>>}>  $batch
     * @param  array<string, array<string, string>>  $answers
     */
    private function write(Product $product, array $batch, array $answers): int
    {
        $filled = 0;
        foreach ($batch as $id => $group) {
            foreach ($group['items'] as $item) {
                $added = array_intersect_key($answers[$id] ?? [], array_flip($item['locales']));
                if ($added === []) {
                    continue;
                }
                $target = $item['target']->fresh();
                if ($target === null) {
                    continue;
                }
                $translations = $target->translations ?? [];
                foreach ($added as $locale => $text) {
                    $translations[$locale] = $item['field'] === null ? $text
                        : [...(is_array($translations[$locale] ?? null) ? $translations[$locale] : []), $item['field'] => $text];
                    $filled++;
                }
                $target->update(['translations' => $translations]);
            }
        }

        return $filled;
    }
}
