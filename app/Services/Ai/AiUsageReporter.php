<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use Illuminate\Support\Collection;

class AiUsageReporter
{
    public function __construct(private readonly AiModelCatalog $models) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'last_24h' => $this->period(now()->subDay()),
            'last_7d' => $this->period(now()->subDays(7)),
            'last_30d' => $this->period(now()->subDays(30)),
            'all_time' => $this->period(null),
            'currency' => 'USD',
            'cost_note' => 'Estimated from the checked-in official standard-tier rate card; null means the provider/model is absent from the catalog.',
        ];
    }

    /**
     * Usage for one Telegram interaction - the top-level agent call plus
     * every nested tool-invoked AiRun it triggered, all sharing the same
     * telegram_update_id.
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

            // Laravel AI already removes cached input from OpenAI's promptTokens.
            // OpenAI outputTokens already includes its reasoning-token detail.
            $uncachedInput = $run->provider === 'openai'
                ? $input
                : max(0, $input - $cachedInput);

            $totals['input'] += $uncachedInput;
            $totals['cached_input'] += $cachedInput;
            $totals['output'] += $output;
            $totals['reasoning'] += $reasoning;
            $totals['total'] += $uncachedInput + $cachedInput + $output;
        }

        return $totals;
    }

    /** @param array<string, int> $tokens */
    private function estimateCost(string $provider, string $model, array $tokens): ?float
    {
        $prices = $this->models->pricing($provider, $model);

        if ($prices === null) {
            return null;
        }

        $cost = 0.0;

        foreach ([
            'input' => 'input_per_million',
            'cached_input' => 'cached_input_per_million',
            'output' => 'output_per_million',
        ] as $tokenKey => $priceKey) {
            $price = $prices[$priceKey] ?? null;

            if (! is_numeric($price)) {
                return null;
            }

            $cost += ($tokens[$tokenKey] ?? 0) * ((float) $price) / 1_000_000;
        }

        return round($cost, 6);
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
