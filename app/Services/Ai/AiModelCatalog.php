<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use Illuminate\Support\Facades\Schema;

class AiModelCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function models(string $provider = 'openai', bool $selectableOnly = false): array
    {
        if (Schema::hasTable('ai_models')) {
            $query = AiModel::query()->where('provider', $provider);

            if ($selectableOnly) {
                $query->where('is_enabled', true)->where('is_available', true);
            }

            $models = [];

            foreach ($query->orderBy('id')->get() as $record) {
                $models[$record->model] = [
                    'label' => $record->label,
                    'input_per_million' => $record->input_per_million,
                    'cached_input_per_million' => $record->cached_input_per_million,
                    'output_per_million' => $record->output_per_million,
                    'source_url' => $record->source_url,
                    'pricing_checked_at' => $record->pricing_checked_at?->toDateString(),
                    'is_available' => $record->is_available,
                    'is_enabled' => $record->is_enabled,
                ];
            }

            if ($models !== []) {
                return $models;
            }
        }

        $models = config("ai_model_catalog.providers.{$provider}.models", []);

        return is_array($models) ? $models : [];
    }

    public function has(string $provider, ?string $model): bool
    {
        return is_string($model) && array_key_exists($model, $this->models($provider, true));
    }

    /** @return array<string, string> */
    public function options(string $provider = 'openai'): array
    {
        $options = [];

        foreach ($this->models($provider, true) as $model => $details) {
            $label = (string) ($details['label'] ?? $model);
            $input = $this->formatPrice($details['input_per_million'] ?? null);
            $cached = $this->formatPrice($details['cached_input_per_million'] ?? null);
            $output = $this->formatPrice($details['output_per_million'] ?? null);

            $options[$model] = "{$label} — \${$input} вход / \${$cached} кеш / \${$output} выход";
        }

        return $options;
    }

    /** @return array<string, mixed>|null */
    public function pricing(string $provider, string $model): ?array
    {
        $pricing = $this->models($provider)[$model] ?? null;

        return is_array($pricing) ? $pricing : null;
    }

    public function pricingCheckedAt(): ?string
    {
        if (Schema::hasTable('ai_models')) {
            $date = AiModel::query()->max('pricing_checked_at');

            if ($date !== null) {
                return (string) $date;
            }
        }

        $date = config('ai_model_catalog.pricing_checked_at');

        return is_string($date) && $date !== '' ? $date : null;
    }

    private function formatPrice(mixed $price): string
    {
        if (! is_numeric($price)) {
            return '?';
        }

        return rtrim(rtrim(number_format((float) $price, 4, '.', ''), '0'), '.');
    }
}
