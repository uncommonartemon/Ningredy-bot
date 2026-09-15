<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Checks a draft's whole card - title, model, color, description, and
 * specifications, all settled during research, possibly from a different
 * page than the one that ended up providing photos - against the actual,
 * final chosen photo source. See PROJECT_STRATEGY.md's "Единый источник
 * карточки" rule: description, specifications, and photos must all describe
 * one exact configuration from one logical source, which a plausible-but-
 * not-textually-identical photo source (deliberately allowed elsewhere in
 * this pipeline when the operator did not pin an exact SKU) would otherwise
 * quietly violate.
 *
 * There is no fixed quota of confirmed fields and no dictionary of which
 * attributes count as "required" - the agent itself judges whether the card
 * now sufficiently represents original_operator_request (see the agent's own
 * docblock). A specification the evidence never addresses, and that the
 * operator never asked about specifically, is dropped from the card rather
 * than kept at its old, now-unverified value.
 *
 * Every failure mode that means the AI was never actually consulted
 * (disabled, no budget, a technical error, an invalid response) returns
 * unavailable($reason) - the caller (ProductImageStorage,
 * ProductDraftWorkflow::approve()) must not treat that the same as a
 * successful reconciliation. The reason specifically distinguishes "the
 * whole shared search budget is spent" from every other cause, because only
 * that one must stop ProductImageStorage's automatic retries the same way
 * cost_budget/time_budget already do everywhere else - every other cause is
 * bounded by the draft's own attempt counter instead.
 *
 * This class itself never fetches the page - it hands the agent whatever
 * text was already captured while the page was open during the search (see
 * ProductImageResolver's extractPageSpecificationText()/
 * lastSpecificationText()), which is often empty (no ProductSourcePageEvidence
 * row at all - e.g. a source whose image_urls were handed over directly by
 * research with no page ever actually opened). An empty capture does not
 * skip the agent: the agent is still consulted, and its own
 * FetchProductSourcePageText tool (HTTP with a Playwright fallback) is
 * exactly the mechanism for bootstrapping evidence from nothing, including
 * for Playwright-only sources a plain re-fetch here could never see anyway.
 * A verification never attempted is not evidence the old card is wrong -
 * but it is a reason to actually attempt one now, not to declare the card
 * unreconciled forever.
 *
 * Every ProductSourcePageEvidence row already on this draft OTHER than
 * source_url's own (the agent's own tool may have found some on an earlier,
 * interrupted attempt) is handed to the agent too, as previously_fetched_pages
 * - so a retry does not re-pay to rediscover what a prior attempt already
 * found. Whether THIS attempt's own tool calls changed that table at all is
 * reported back on the outcome as madeProgress, independent of whether the
 * attempt overall reached reconciled - new evidence is progress even when
 * the card itself is still not_ready, and the caller's attempt counter must
 * not treat that the same as a repeat of the exact same failed work.
 */
class ProductSpecificationReconciler
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
    ) {}

    /** @param array<string, mixed> $source */
    public function reconcile(ProductDraft $draft, array $source, ?int $telegramUpdateId): SpecificationReconciliationOutcome
    {
        $url = trim((string) ($source['url'] ?? ''));

        if ($url === '') {
            return SpecificationReconciliationOutcome::unavailable('empty_url');
        }

        // The only legitimate free path: this exact draft was already
        // reconciled against this exact URL. Not a guess about content -
        // an identity comparison against a persisted fact about this draft.
        if ($draft->specifications_reconciled_source_url === $url) {
            return SpecificationReconciliationOutcome::alreadyReconciled($url);
        }

        if (! $this->settings->specificationReconciliationEnabled()) {
            return SpecificationReconciliationOutcome::disabled($url);
        }

        $updateId = $telegramUpdateId ?? $draft->telegram_update_id;
        $urlHash = hash('sha256', ProductImageStorage::normalizeCandidateUrl($url));
        $evidence = ProductSourcePageEvidence::query()
            ->where('product_draft_id', $draft->id)
            ->where('url_hash', $urlHash)
            ->first();

        if ($this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 20)) {
            return SpecificationReconciliationOutcome::unavailable('budget_exhausted');
        }

        // Anything the tool already found on an earlier, interrupted attempt
        // - a different page on this site, or this same page read via a
        // selector - so this attempt does not pay to rediscover it. The
        // agent decides whether it is enough; re-reading is still allowed
        // when it judges the saved text no longer answers what is needed.
        $previouslyFetchedPages = ProductSourcePageEvidence::query()
            ->where('product_draft_id', $draft->id)
            ->where('url_hash', '!=', $urlHash)
            ->get()
            ->map(fn (ProductSourcePageEvidence $page): array => [
                'url' => $page->url,
                'final_url' => $page->final_url,
                'captured_via' => $page->captured_via,
                'open_selector' => $page->open_selector,
                'specification_text' => mb_substr($page->specification_text, 0, 4_000),
                'identity_evidence' => mb_substr((string) $page->identity_evidence, 0, 2_000),
            ])
            ->values()
            ->all();
        // Whether THIS attempt's own tool calls actually changed what is
        // known - compared after the agent call regardless of how it ends,
        // so a technical failure that still found something new is not
        // indistinguishable from one that found nothing (see madeProgress
        // on the returned outcome). Built from the rows' own content
        // (specification_text/final_url/open_selector/click_outcome), not
        // row count or updated_at: a write that touches only a housekeeping
        // timestamp on unchanged content must not read as progress, and two
        // real, different writes landing in the same second must not read
        // as no progress - both were true of a signature built from count
        // and max(updated_at) alone.
        $evidenceSignature = function () use ($draft): string {
            return ProductSourcePageEvidence::query()
                ->where('product_draft_id', $draft->id)
                ->orderBy('id')
                ->get(['id', 'specification_text', 'final_url', 'open_selector', 'click_outcome'])
                ->map(fn (ProductSourcePageEvidence $page): string => implode('|', [
                    $page->id,
                    hash('sha256', (string) $page->specification_text),
                    (string) $page->final_url,
                    (string) $page->open_selector,
                    json_encode($page->click_outcome) ?: '',
                ]))
                ->implode(';');
        };
        $evidenceStateBefore = $evidenceSignature();
        $madeProgress = fn (): bool => $evidenceSignature() !== $evidenceStateBefore;

        $specifications = collect($draft->specifications ?? [])
            ->filter(fn (mixed $item): bool => is_array($item)
                && is_string($item['key'] ?? null) && $item['key'] !== ''
                && is_string($item['name'] ?? null) && $item['name'] !== '')
            ->values();
        $originalOperatorRequest = $draft->relationLoaded('telegramUpdate')
            ? $draft->telegramUpdate?->text
            : $draft->telegramUpdate()->value('text');
        $inputSpecifications = $specifications->map(fn (array $item): array => [
            'key' => (string) $item['key'],
            'name' => (string) $item['name'],
            'value' => (string) ($item['value'] ?? ''),
        ])->values()->all();
        $payload = [
            'original_operator_request' => is_string($originalOperatorRequest) ? $originalOperatorRequest : null,
            'current_title' => (string) $draft->title,
            'current_model' => (string) $draft->model,
            'current_color' => $draft->color,
            'current_description' => (string) $draft->description,
            'current_specifications' => $inputSpecifications,
            'source_url' => $url,
            'source_title' => is_string($source['title'] ?? null) ? $source['title'] : null,
            'source_identity_snippet' => mb_substr(
                (string) ($source['_preflight_identity_evidence'] ?? ''),
                0,
                2000,
            ),
            'source_specification_text' => mb_substr($evidence?->specification_text ?? '', 0, 12_000),
            'previously_fetched_pages' => $previouslyFetchedPages,
            'previous_assembly_feedback' => $source['_previous_assembly_feedback'] ?? null,
        ];
        $prompt = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $provider = $this->settings->providerFor('product_specification_reconciliation');
        $model = $this->settings->modelFor('product_specification_reconciliation');
        $timeout = $this->timeBudget->timeoutFor(
            $updateId,
            (int) config('services.product_specification_reconciliation.timeout', 90),
        );
        $run = $updateId ? AiRun::query()->create([
            'telegram_update_id' => $updateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]) : null;

        $sourceHost = trim((string) parse_url($url, PHP_URL_HOST));

        try {
            $response = app(OpenAiHeavyOperationGate::class)->run(
                $provider,
                $timeout,
                function () use ($sourceHost, $updateId, $draft, $prompt, $provider, $model, $timeout) {
                    // Waiting for another worker consumed real shared time.
                    // Do not start with a stale timeout after acquiring the lock.
                    if ($this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 20)) {
                        throw new \RuntimeException('Card assembly shared budget exhausted while waiting.');
                    }

                    return ProductSpecificationReconciliationAgent::make($sourceHost !== '' ? $sourceHost : null, $updateId, $draft->id)->prompt(
                        $prompt,
                        provider: $provider,
                        model: $model,
                        timeout: $this->timeBudget->timeoutFor($updateId, $timeout),
                    );
                },
            );
            // A schema/validation failure still consumed a real model response.
            // Preserve its usage and evidence before validating the payload.
            $run?->update([
                'invocation_id' => $response->invocationId,
                'response' => $response->toArray(),
                'usage' => $response->usage->toArray(),
            ]);
            $data = Validator::make($response->toArray(), [
                'overall_status' => ['required', 'in:reconciled,not_ready'],
                'summary' => ['required', 'string', 'max:500'],
                'title' => ['required', 'string', 'max:255'],
                'model' => ['required', 'string', 'max:255'],
                'color' => ['nullable', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:5000'],
                // 'present', not 'required' - Laravel's required treats an
                // empty array as absent, and a draft can genuinely have zero
                // specifications while still needing its title/model/color/
                // description reconciled (point 2 of the agreed scope).
                'specifications' => ['present', 'array'],
                'specifications.*.key' => ['required', 'string', 'max:100'],
                'specifications.*.name' => ['required', 'string', 'max:255'],
                'specifications.*.value' => ['required', 'string', 'max:2000'],
                'specifications.*.evidence' => ['required', 'in:confirmed,corrected,dropped'],
            ])->validate();

            // Deterministic validation, not trusting the model to preserve
            // structure: the agent must echo back exactly the keys it was
            // given, nothing invented, nothing silently missing (dropping a
            // field is expressed through its own "evidence" value, not by
            // omitting it from the response).
            $returnedKeys = collect($data['specifications'])->pluck('key')->sort()->values()->all();
            $expectedKeys = collect($inputSpecifications)->pluck('key')->sort()->values()->all();

            if ($returnedKeys !== $expectedKeys) {
                throw new \RuntimeException('Specification reconciliation response key set did not match the input.');
            }

            $run?->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $response->toArray(),
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            if ($data['overall_status'] === 'not_ready') {
                return SpecificationReconciliationOutcome::notReady($data['summary'], $madeProgress());
            }

            $reconciledSpecifications = collect($data['specifications'])
                // Dropped: the evidence never addressed it and the operator
                // never asked about it specifically - removed rather than
                // published at its old, now-unverified value.
                ->reject(fn (array $item): bool => $item['evidence'] === 'dropped')
                ->map(fn (array $item): array => [
                    'key' => $item['key'],
                    'name' => $item['name'],
                    'value' => $item['value'],
                ])
                ->values()
                ->all();

            return SpecificationReconciliationOutcome::reconciled(
                $url,
                $data['title'],
                $data['model'],
                $data['color'],
                $data['description'],
                $reconciledSpecifications,
                $data['summary'],
            );
        } catch (Throwable $exception) {
            $run?->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
            report($exception);

            return SpecificationReconciliationOutcome::unavailable(
                $this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 20)
                    ? 'budget_exhausted' : 'technical_error',
                $madeProgress(),
                mb_substr($exception->getMessage(), 0, 1500),
            );
        }
    }
}
