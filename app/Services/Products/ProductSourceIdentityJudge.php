<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Judges the narrow grey zone ProductIdentityMatcher's fast literal text
 * match cannot resolve on its own - see ProductSourceIdentityAgent's
 * docblock for the real case (a B&H Photo Video listing for the exact
 * requested Apple SKU, rejected only because its URL ordered the same words
 * differently than the requested model string).
 *
 * Every failure mode that means the AI was never actually consulted
 * (disabled, no budget, nothing to check, a technical/AI error, an invalid
 * response) returns 'unavailable', not 'uncertain' - a caller that would
 * reject a source on an explicit operator identifier the AI genuinely
 * judged too ambiguous to confirm must not apply that same rejection to a
 * question that was simply never asked. 'uncertain' is reserved for the AI
 * actually having looked and said so; the caller decides what each means,
 * but only 'uncertain' is evidence of anything.
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
        return $this->evaluate($draft, $source, $telegramUpdateId, false);
    }

    /** The same semantic check/cache, within the already-started assembly window. */
    public function judgeForAssembly(ProductDraft $draft, array $source, ?int $telegramUpdateId, ?array $proposedCard = null): string
    {
        return $this->evaluate($draft, $source, $telegramUpdateId, true, $proposedCard);
    }

    private function evaluate(ProductDraft $draft, array $source, ?int $telegramUpdateId, bool $assembly, ?array $proposedCard = null): string
    {
        if (! $this->settings->sourceIdentityAgentEnabled()) {
            return 'unavailable';
        }

        $updateId = $telegramUpdateId ?? $draft->telegram_update_id;

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
            return 'unavailable';
        }

        $requestContext = ProductRequestContext::forDraft($draft)->forAgent();
        $payload = [
            'verification_stage' => $assembly ? 'assembled_offer' : 'source_eligibility',
            // What the operator actually asked for, not only what research
            // settled on. A page naming a different specific configuration
            // than requested_model/requested_identifiers is not automatically
            // a conflict when the operator never specified that far - this is
            // what lets the agent tell "the operator wanted red and got
            // black" apart from "the operator only said the product line, and
            // research happened to settle on one SKU of several that would
            // equally have satisfied the request".
            ...$requestContext,
            'requested_model' => $draft->model,
            'requested_identifiers' => $identifiers,
            'requested_color' => $draft->color,
            'evidence_url' => $source['url'] ?? null,
            'evidence_title' => $source['title'] ?? null,
            'evidence_text' => mb_substr($evidenceText, 0, $proposedCard !== null ? 12000 : 2000),
        ];
        if ($proposedCard !== null) {
            // This verdict concerns the proposed whole card, not merely whether
            // the page fits a broad request. Include it in the cache key too.
            $payload['proposed_card'] = array_intersect_key($proposedCard,
                array_flip(['title', 'model', 'color', 'description', 'specifications']));
        }
        $prompt = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $provider = $this->settings->providerFor('product_source_identity');
        $model = $this->settings->modelFor('product_source_identity');
        // Main-source and fallback paths can ask the identical question.
        // Reuse only a completed verdict for this exact input and search;
        // technical failures are not cached as product evidence.
        $cached = $updateId ? AiRun::query()
            ->where('telegram_update_id', $updateId)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('prompt', $prompt)
            ->where('status', 'completed')
            ->latest('id')->first() : null;
        $cachedMatch = $cached?->response['match'] ?? null;
        if (in_array($cachedMatch, ['confirmed', 'conflicting', 'uncertain'], true)) {
            return $cachedMatch;
        }
        if ($assembly
            ? ! $this->timeBudget->assemblyCanStart($updateId, 10)
            : (! $this->timeBudget->canStart($updateId, 10) || app(ProductSearchCostBudget::class)->exceeded($updateId))) {
            return 'unavailable';
        }

        $configuredTimeout = (int) config('services.product_source_identity.timeout', 20);
        $timeout = $assembly
            ? $this->timeBudget->assemblyTimeoutFor($updateId, $configuredTimeout)
            : $this->timeBudget->timeoutFor($updateId, $configuredTimeout);
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

            return 'unavailable';
        }
    }
}
