<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Photos\ImageUrls;
use App\Services\ProductSearch\Recovery\CardOfferSelection;
use App\Services\ProductSearch\Recovery\OfferSelectionUnavailable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks a draft's whole card - title, model, color, description, and
 * specifications, all settled during research, possibly from a different
 * page than the photo gallery - using applicable evidence from multiple
 * text sources. Photos remain one gallery; every fact must concern the
 * same exact configuration. The photo page need not repeat all text facts.
 *
 * There is no fixed quota of confirmed fields and no dictionary of which
 * attributes count as "required" - the agent itself judges whether the card
 * now sufficiently represents original_operator_request (see the agent's own
 * docblock). A specification the evidence never addresses, and that the
 * operator never asked about specifically, is dropped from the card rather
 * than kept at its old, now-unverified value.
 *
 * Disabled checks, budget exhaustion, technical failures and rejected AI
 * responses never count as successful reconciliation. The outcome records
 * which case occurred; an invalid response may already have spent tokens
 * and is returned to the agent with its concrete validation errors. The
 * caller (ProductImageStorage, ProductDraftWorkflow::approve()) must not
 * publish an unverified candidate. The reason distinguishes "the
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
        private readonly ProductSpecificationEvidenceGuard $evidenceGuard,
    ) {}

    /** @param array<string, mixed> $source */
    public function reconcile(ProductDraft $draft, array $source, ?int $telegramUpdateId, ?string $model = null): SpecificationReconciliationOutcome
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
        $urlHash = hash('sha256', ImageUrls::normalizeCandidateUrl($url));
        $evidence = ProductSourcePageEvidence::query()
            ->where('product_draft_id', $draft->id)
            ->where('url_hash', $urlHash)
            ->first();

        if (! $this->timeBudget->assemblyCanStart($updateId, 20)) {
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
        // Compare actual observations even after an interrupted call. The
        // same quotation with a reworded explanation is not new evidence;
        // a new quotation/source or changed captured page still is.
        $progress = app(ProductEvidenceProgress::class);
        $evidenceStateBefore = $progress->snapshot($draft);
        $madeProgress = fn (): bool => $progress->hasNewObservations($evidenceStateBefore, $progress->snapshot($draft));

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
            'source_url' => $item['source_url'] ?? null,
            'source_excerpt' => $item['source_excerpt'] ?? null,
            ...(array_key_exists('group', $item) ? ['group' => AiAttributeGroups::normalize($item['group'])] : []),
        ])->values()->all();
        $payload = [
            'offer_context' => app(CardOfferSelection::class)->context($draft, $source),
            'original_operator_request' => is_string($originalOperatorRequest) ? $originalOperatorRequest : null,
            'current_title' => (string) $draft->title,
            'current_model' => (string) $draft->model,
            'current_color' => $draft->color,
            'current_condition' => $draft->condition ?? 'new',
            'research_sources' => $draft->sources ?? [],
            'saved_text_evidence' => $draft->fresh()->specification_evidence ?? [],
            'differing_cited_observations' => $this->evidenceGuard->differingObservations([
                ...$inputSpecifications, ...($draft->fresh()->specification_evidence ?? []),
            ]),
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
        $model = $model ?? $this->settings->modelFor('product_specification_reconciliation');
        $timeout = $this->timeBudget->assemblyTimeoutFor(
            $updateId,
            (int) config('services.product_specification_reconciliation.timeout', 90),
        );
        $previous = $source['_previous_assembly_feedback'] ?? [];
        $retryCandidate = ($previous['reason'] ?? null) === 'identity_check_unavailable'
            ? ($previous['repair_context']['previous_candidate'] ?? null) : null;
        $retryCandidate = is_array($retryCandidate) ? $retryCandidate : null;
        $run = $updateId && $retryCandidate === null ? AiRun::query()->create([
            'telegram_update_id' => $updateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]) : null;

        $sourceHost = trim((string) parse_url($url, PHP_URL_HOST));

        $candidate = null;
        try {
            $response = $retryCandidate === null ? app(OpenAiHeavyOperationGate::class)->run(
                $provider,
                $timeout,
                function () use ($sourceHost, $updateId, $draft, $prompt, $provider, $model, $timeout, $source) {
                    // Waiting for another worker consumed real shared time.
                    // Do not start with a stale timeout after acquiring the lock.
                    if (! $this->timeBudget->assemblyCanStart($updateId, 20)) {
                        throw new \RuntimeException('Card assembly shared budget exhausted while waiting.');
                    }

                    return ProductSpecificationReconciliationAgent::make($sourceHost !== '' ? $sourceHost : null, $updateId, $draft->id, $source)->prompt(
                        $prompt,
                        provider: $provider,
                        model: $model,
                        timeout: $this->timeBudget->assemblyTimeoutFor($updateId, $timeout),
                    );
                },
            ) : null;
            // A schema/validation failure still consumed a real model response.
            // Preserve its usage and evidence before validating the payload.
            if ($response !== null) {
                $run?->update([
                    'invocation_id' => $response->invocationId,
                    'response' => $response->toArray(),
                    'usage' => $response->usage->toArray(),
                ]);
            }
            // A retry of a failed requirement check revalidates retained data,
            // never assumes it is trusted and never buys its generation again.
            $normalized = $retryCandidate ?? $response->toArray();
            // Keep the rejected proposal as data for the next attempt. It is
            // not saved to the draft or trusted merely because the model said it.
            $candidate = $normalized;
            // Commentary length cannot invalidate otherwise verified facts.
            // Keep the original response in the run above; shorten only the
            // display summary, never a product value or its cited evidence.
            if (is_string($normalized['summary'] ?? null)) {
                $normalized['summary'] = mb_substr($normalized['summary'], 0, 500);
            }
            // The same for each value's explanation: request #102 threw away a
            // whole card over a 19th field's reason running past 300 characters.
            $inputByKey = collect($inputSpecifications)->keyBy('key');
            foreach ((array) ($normalized['specifications'] ?? []) as $index => $specification) {
                if (is_array($specification) && is_string($specification['reason'] ?? null)) {
                    $normalized['specifications'][$index]['reason'] = mb_substr($specification['reason'], 0, 300);
                }
                if (is_array($specification)) {
                    $previous = $inputByKey->get($specification['key'] ?? '', []);
                    if (array_key_exists('group', $specification) || array_key_exists('group', $previous)) {
                        $normalized['specifications'][$index]['group'] = AiAttributeGroups::normalize(
                            array_key_exists('group', $specification) ? $specification['group'] : $previous['group'],
                        );
                    }
                }
            }
            $data = Validator::make($normalized, [
                'overall_status' => ['required', 'in:reconciled,not_ready'],
                'recovery_action' => ['sometimes', 'in:none,retry_current_source,try_other_source'],
                'summary' => ['required', 'string', 'max:500'],
                'title' => ['required', 'string', 'max:255'],
                'model' => ['required', 'string', 'max:255'],
                'color' => ['nullable', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:5000'],
                'translations' => ['sometimes', 'array'],
                'translations.*.title' => ['required', 'string', 'max:255'],
                'translations.*.description' => ['required', 'string', 'max:5000'],
                // 'present', not 'required' - Laravel's required treats an
                // empty array as absent, and a draft can genuinely have zero
                // specifications while still needing its title/model/color/
                // description reconciled (point 2 of the agreed scope).
                'specifications' => ['present', 'array'],
                'specifications.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', 'distinct'],
                'specifications.*.name' => ['required', 'string', 'max:255'],
                'specifications.*.value' => ['required', 'string', 'max:2000'],
                'specifications.*.group' => ['sometimes', 'nullable', 'array:id,name,name_cs'],
                'specifications.*.evidence' => ['required', 'in:confirmed,corrected,dropped'],
                'specifications.*.source_url' => ['nullable', 'url:http,https', 'max:2048'],
                'specifications.*.source_excerpt' => ['nullable', 'string', 'max:800'],
                'specifications.*.reason' => ['nullable', 'string', 'max:300'],
                'specifications.*.considered_source_urls' => ['sometimes', 'array'],
                'specifications.*.considered_source_urls.*' => ['url:http,https', 'max:2048'],
                'offer_selection' => ['sometimes', 'array'],
                'offer_selection.action' => ['required_with:offer_selection', 'in:retain_selected_offer,adopt_source_offer'],
                'offer_selection.source_url' => ['required_with:offer_selection', 'url:http,https', 'max:2048'],
                'offer_selection.reason' => ['required_with:offer_selection', 'string', 'max:1500'],
            ])->validate();

            // Deterministic validation, not trusting the model to preserve
            // structure: the agent must account for every input key, and new
            // keys require cited evidence. Nothing silently missing (dropping a
            // field is expressed through its own "evidence" value, not by
            // omitting it from the response).
            $returnedKeys = collect($data['specifications'])->pluck('key')->sort()->values()->all();
            $expectedKeys = collect($inputSpecifications)->pluck('key')->sort()->values()->all();

            if (array_diff($expectedKeys, $returnedKeys) !== []) {
                throw ValidationException::withMessages(['specifications' => [
                    'Missing input keys: '.implode(', ', array_diff($expectedKeys, $returnedKeys)),
                ]]);
            }
            if ($data['overall_status'] === 'reconciled') {
                // Tools may have fetched evidence during this very call.
                // Read it again before validation; never authorize a citation
                // using only the URL or the answer being validated itself.
                $observedPages = [...ProductSourcePageEvidence::where('product_draft_id', $draft->id)->get()->toArray(), [
                    'url' => $url,
                    'identity_evidence' => $source['_preflight_identity_evidence'] ?? '',
                    'specification_text' => $source['_preflight_specification_text'] ?? '',
                ]];
                $this->evidenceGuard->validate($inputSpecifications, $data['specifications'],
                    $draft->fresh()->specification_evidence ?? [], $observedPages);
                $offerSelection = app(CardOfferSelection::class)->validate($draft, $source, $data, $observedPages, $updateId);
            }

            if ($response !== null) {
                $run?->update([
                    'invocation_id' => $response->invocationId,
                    'status' => 'completed',
                    'response' => $response->toArray(),
                    'usage' => $response->usage->toArray(),
                    'completed_at' => now(),
                ]);
            }

            if ($data['overall_status'] === 'not_ready') {
                return SpecificationReconciliationOutcome::notReady($data['summary'], $madeProgress(), [
                    'previous_candidate' => $candidate,
                    'validation_errors' => [],
                    'recovery_action' => $data['recovery_action'] ?? 'retry_current_source',
                ]);
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
                    ...array_intersect_key($item, array_flip(['group', 'source_url', 'source_excerpt', 'reason', 'considered_source_urls'])),
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
                $this->cardTranslations($normalized['translations'] ?? null, $reconciledSpecifications),
                $offerSelection ?? null,
            );
        } catch (Throwable $exception) {
            $run?->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
            report($exception);

            $invalidResponse = $exception instanceof ValidationException
                || $exception instanceof InvalidSpecificationEvidence;
            // Another worker held the OpenAI queue longer than this call was
            // willing to wait, or OpenAI rate-limited the account (request
            // #53: both models refused for a minute). The card is not wrong;
            // the provider was busy, and the gate already waits out its pause.
            $providerBusy = $exception->getPrevious() instanceof LockTimeoutException
                || app(OpenAiHeavyOperationGate::class)->isRateLimit($exception);

            return SpecificationReconciliationOutcome::unavailable(
                ! $this->timeBudget->assemblyCanStart($updateId, 20)
                    ? 'budget_exhausted'
                    : ($exception instanceof OfferSelectionUnavailable ? 'identity_check_unavailable'
                        : ($invalidResponse ? 'invalid_response' : ($providerBusy ? 'provider_busy' : 'technical_error'))),
                $madeProgress(),
                mb_substr($exception->getMessage(), 0, 1500),
                $candidate === null ? null : [
                    'previous_candidate' => $candidate,
                    'validation_errors' => match (true) {
                        $exception instanceof ValidationException => $exception->errors(),
                        $exception instanceof InvalidSpecificationEvidence => $exception->violations,
                        default => [mb_substr($exception->getMessage(), 0, 1500)],
                    },
                ],
            );
        }
    }

    /**
     * The card's translations as stored on the draft: the translated title and
     * description, plus a translated name and value for each specification
     * that survived reconciliation. A translation is a courtesy, not evidence:
     * an item for a dropped or unknown key, or a malformed one, is left out
     * rather than failing a card whose facts are already verified - that
     * specification simply shows in English.
     *
     * @param  array<int, array<string, mixed>>  $reconciledSpecifications
     * @return array<string, array<string, mixed>>|null
     */
    private function cardTranslations(mixed $translations, array $reconciledSpecifications): ?array
    {
        if (! is_array($translations)) {
            return null;
        }

        $retainedKeys = array_column($reconciledSpecifications, 'key');
        $specifications = CatalogLocales::specificationTranslations($translations);
        $card = [];

        foreach (CatalogLocales::textTranslations($translations) ?? [] as $locale => $text) {
            $card[$locale] = [
                ...$text,
                'specifications' => collect($specifications[$locale] ?? [])
                    ->only($retainedKeys)
                    ->map(fn (array $item, string $key): array => ['key' => $key, ...$item])
                    ->values()
                    ->all(),
            ];
        }

        return $card;
    }
}
