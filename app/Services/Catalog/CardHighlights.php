<?php

namespace App\Services\Catalog;

use App\Ai\Agents\CardHighlightsAgent;
use App\Models\AiRun;
use App\Models\Product;
use App\Services\Ai\AiSettings;
use Throwable;

/**
 * The characteristics a product's catalog card shows first, chosen once by a model from the
 * product's own characteristics and kept on the product (`card_attribute_keys`). The
 * storefront fills the rest of the card in the usual order, so a key that was removed
 * later only gives its row back.
 */
class CardHighlights
{
    public function __construct(private readonly AiSettings $settings) {}

    /**
     * Products whose card was never chosen and that have characteristics to choose from.
     *
     * @return list<int>
     */
    public function pendingProductIds(int $limit): array
    {
        return Product::query()->whereNull('card_attribute_keys')
            ->where(fn ($query) => $query->whereHas('attributes')->orWhereHas('variants.attributes'))
            ->orderBy('id')->limit($limit)->pluck('id')->all();
    }

    /** @return list<string> the keys the card now shows first */
    public function choose(Product $product): array
    {
        $rows = $this->rows($product);
        if ($rows === []) {
            $product->forceFill(['card_attribute_keys' => []])->saveQuietly();

            return [];
        }
        $run = AiRun::query()->create([
            'provider' => $this->settings->providerFor('server_assistant'), 'model' => $this->settings->modelFor('server_assistant'),
            'status' => 'running', 'prompt' => mb_substr(json_encode($rows, JSON_UNESCAPED_UNICODE), 0, 60000), 'started_at' => now(),
            'activity' => ['stage' => 'card_highlights', 'product_id' => $product->id],
        ]);
        try {
            $response = (new CardHighlightsAgent)->prompt(json_encode([
                'product' => $product->title,
                'category' => $product->category?->name,
                'characteristics' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), provider: $run->provider, model: $run->model, timeout: 120);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            // Not tried again by itself: the card keeps the usual order until someone asks again.
            $product->forceFill(['card_attribute_keys' => []])->saveQuietly();

            throw $exception;
        }
        $run->update(['status' => 'completed', 'usage' => $response->usage->toArray(), 'invocation_id' => $response->invocationId,
            'response' => $response->toArray(), 'completed_at' => now()]);

        $known = array_column($rows, 'key');
        $keys = array_values(array_unique(array_filter((array) ($response->toArray()['keys'] ?? []),
            fn (mixed $key): bool => is_string($key) && in_array($key, $known, true))));
        $keys = array_slice($keys, 0, CardHighlightsAgent::CARD_ROWS);
        $product->forceFill(['card_attribute_keys' => $keys])->saveQuietly();

        return $keys;
    }

    /**
     * The characteristics the card can show: the product's own and its main offer's, as on the card.
     *
     * @return list<array{key: string, name: string, value: string}>
     */
    private function rows(Product $product): array
    {
        $product->loadMissing(['attributes.definition', 'variants.attributes.definition', 'category']);
        $variants = $product->variants->sortBy('id');
        $variant = $variants->firstWhere('is_default', true) ?: $variants->first();
        $attributes = $product->attributes->whereNull('product_variant_id')
            ->concat($variant?->attributes ?? [])->keyBy('attribute_definition_id');

        return array_values(array_map(fn (array $attribute): array => [
            'key' => (string) $attribute['key'],
            'name' => (string) $attribute['label'],
            'value' => trim($attribute['value'].' '.($attribute['unit'] ?? '')),
        ], AttributePresentation::values($attributes)));
    }
}
