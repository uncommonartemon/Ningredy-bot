<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Judges the narrow grey zone ProductIdentityMatcher's fast literal text
 * match cannot resolve on its own - see ProductSourceIdentityAgent's
 * docblock for the real case (a B&H Photo Video listing for the exact
 * requested Apple SKU, rejected only because its URL ordered the same words
 * differently than the requested model string). Every failure mode here
 * (disabled, no budget, AI error, invalid response) degrades to 'uncertain'
 * - the same outcome ProductIdentityMatcher's own rejection already
 * produced before this class existed - so a problem with this optional
 * enhancement can never crash or block the broader search.
 */
class ProductSourceIdentityJudge
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
    ) {}

    /** @param array<string, mixed> $source */
    public function judge(ProductDraft $draft, array $source, ?int $telegramUpdateId): string
    {
        if (! $this->settings->sourceIdentityAgentEnabled()) {
            return 'uncertain';
        }

        $updateId = $telegramUpdateId ?? $draft->telegram_update_id;

        if (! $this->timeBudget->canStart($updateId, 10)) {
            return 'uncertain';
        }

        $identifiers = collect($draft->specifications ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['key'] ?? null, [
                'model', 'sku', 'mpn', 'ean', 'upc', 'gtin',
            ], true))
            ->map(fn (array $item): string => (string) ($item['value'] ?? ''))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
        $evidenceText = collect([
            $source['title'] ?? null,
            $source['_preflight_identity_evidence'] ?? null,
        ])->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->implode(' ');

        if (trim((string) $draft->model) === '' && $identifiers === []) {
            return 'uncertain';
        }

        $payload = [
            'requested_model' => $draft->model,
            'requested_identifiers' => $identifiers,
            'requested_color' => $draft->color,
            'evidence_url' => $source['url'] ?? null,
            'evidence_title' => $source['title'] ?? null,
            'evidence_text' => mb_substr($evidenceText, 0, 2000),
        ];
        $prompt = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $provider = $this->settings->providerFor('product_source_identity');
        $model = $this->settings->modelFor('product_source_identity');
        $timeout = $this->timeBudget->timeoutFor($updateId, (int) config('services.product_source_identity.timeout', 20));
        $run = $updateId ? AiRun::query()->create([
            'telegram_update_id' => $updateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]) : null;

        try {
            $response = app(OpenAiHeavyOperationGate::class)->run(
                $provider,
                $timeout,
                fn () => ProductSourceIdentityAgent::make()->prompt(
                    $prompt,
                    provider: $provider,
                    model: $model,
                    timeout: $timeout,
                ),
            );
            $data = Validator::make($response->toArray(), [
                'match' => ['required', 'in:confirmed,conflicting,uncertain'],
                'confidence' => ['required', 'numeric', 'between:0,1'],
                'reason' => ['required', 'string', 'max:500'],
            ])->validate();
            $run?->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $response->toArray(),
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            return $data['match'];
        } catch (Throwable $exception) {
            $run?->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
            report($exception);

            return 'uncertain';
        }
    }
}
