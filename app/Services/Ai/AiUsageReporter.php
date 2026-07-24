<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use Illuminate\Support\Collection;

class AiUsageReporter
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'last_24h' => $this->period(now()->subDay()),
            'last_7d' => $this->period(now()->subDays(7)),
            'last_30d' => $this->period(now()->subDays(30)),
            'all_time' => $this->period(null),
            'currency' => 'USD',
            'cost_note' => 'Estimated from configured per-million-token prices; null means pricing is not configured for that provider/model.',
        ];
    }

    /**
     * Usage for one Telegram interaction - the top-level agent call plus
     * every nested tool-invoked AiRun it triggered (research, image
     * discovery, vision...), all sharing the same telegram_update_id.
     * Pass $since to scope to only runs created from that point on (e.g.
     * a job's own start time), so a later step doesn't double-count an
     * earlier one's tokens.
     *
     * @return array<string, mixed>
     */
    public function forTelegramUpdate(int $telegramUpdateId, ?\DateTimeInterface $since = null): array
    {
        $query = AiRun::query()->where('telegram_update_id', $telegramUpdateId)->whereNotNull('usage');

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $this->summarizeRuns($query->get(['provider', 'model', 'usage']));
    }

    /** @return array<string, mixed> */
    private function period($from): array
    {
        $query = AiRun::query()->whereNotNull('usage');

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        return $this->summarizeRuns($query->get(['provider', 'model', 'usage']));
    }

    /** @param Collection<int, AiRun> $runs @return array<string, mixed> */
    private function summarizeRuns(Collection $runs): array
    {
        $byModel = $runs
            ->groupBy(fn (AiRun $run): string => $run->provider.':'.$run->model)
            ->map(fn (Collection $items): array => $this->modelSummary($items))
            ->values()
            ->all();
        $totals = $this->totals($runs);
        $knownCosts = collect($byModel)->pluck('estimated_cost_usd')->filter(fn ($value): bool => $value !== null);

        return [
            'runs' => $runs->count(),
            'tokens' => $totals,
            'estimated_cost_usd' => $knownCosts->isNotEmpty() ? round((float) $knownCosts->sum(), 6) : null,
            'by_model' => $byModel,
        ];
    }

    /** @param Collection<int, AiRun> $runs @return array<string, mixed> */
    private function modelSummary(Collection $runs): array
    {
        $first = $runs->first();
        $totals = $this->totals($runs);

        return [
            'provider' => $first?->provider,
            'model' => $first?->model,
            'runs' => $runs->count(),
            'tokens' => $totals,
            'estimated_cost_usd' => $this->estimateCost((string) $first?->provider, (string) $first?->model, $totals),
        ];
    }

    /** @param Collection<int, AiRun> $runs @return array<string, int> */
    private function totals(Collection $runs): array
    {
        $totals = [
            'input' => 0,
            'cached_input' => 0,
            'output' => 0,
            'reasoning' => 0,
            'total' => 0,
        ];

        foreach ($runs as $run) {
            $usage = is_array($run->usage) ? $run->usage : [];
            $input = $this->integer($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
            $cachedInput = $this->integer($usage['cache_read_input_tokens'] ?? $usage['cached_input_tokens'] ?? 0);
            $output = $this->integer($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
            $reasoning = $this->integer($usage['reasoning_tokens'] ?? 0);

            $totals['input'] += max(0, $input - $cachedInput);
            $totals['cached_input'] += $cachedInput;
            $totals['output'] += $output;
            $totals['reasoning'] += $reasoning;
            $totals['total'] += $input + $output + $reasoning;
        }

        return $totals;
    }

    /** @param array<string, int> $tokens */
    private function estimateCost(string $provider, string $model, array $tokens): ?float
    {
        // Model names like "gpt-5.4" contain a dot, which config()'s
        // dot-notation would otherwise parse as a nested key
        // (prices.openai.gpt-5.4 -> ['gpt-5']['4']) and never find a match.
        // Index into the provider's array directly with the literal model
        // string instead.
        $providerPrices = config("services.ai_usage.prices.{$provider}");
        $prices = is_array($providerPrices) ? ($providerPrices[$model] ?? null) : null;

        if (! is_array($prices)) {
            return null;
        }

        $cost = 0.0;
        $hasAnyPrice = false;

        foreach ([
            'input' => 'input_per_million',
            'cached_input' => 'cached_input_per_million',
            'output' => 'output_per_million',
            'reasoning' => 'reasoning_per_million',
        ] as $tokenKey => $priceKey) {
            $price = $prices[$priceKey] ?? null;

            if ($price === null || $price === '') {
                continue;
            }

            $hasAnyPrice = true;
            $cost += ($tokens[$tokenKey] ?? 0) * ((float) $price) / 1_000_000;
        }

        return $hasAnyPrice ? round($cost, 6) : null;
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}