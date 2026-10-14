<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Ai\StreamingProductResearch;
use App\Services\Photos\ImageUrls;
use App\Services\Products\ProductSourcePriority;
use App\Services\Products\ProductTextEvidenceContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class SearchProductTextEvidence implements Tool
{
    public function __construct(
        private readonly int $draftId,
        private readonly ?int $updateId,
        private readonly ?array $assemblySource = null,
        private readonly bool $enforceSearchBudget = false,
    ) {}

    public function description(): Stringable|string
    {
        return 'Find missing or conflicting text specifications using web search, including official PDFs. target_offer selects the current research offer or the observed offer at the selected gallery source. This only gathers evidence: it does not change the draft or photos. Reuse supplied evidence first. Results are persisted for recovery.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draft = ProductDraft::find($this->draftId);
        // Card assembly's tool: the search's money limit does not stop it (see
        // ProductCardAssembly), the assembly agent's step limit bounds it.
        $time = app(ProductSearchTimeBudget::class);
        $updateId = $this->updateId ?? $draft?->telegram_update_id;
        if (! $draft || $draft->status !== 'pending_review' || $draft->telegramUpdate?->cancel_requested_at
            || app(ProductSearchCancellation::class)->isCancelled($updateId)) {
            return json_encode(['found' => false, 'reason' => 'draft is no longer active']);
        }
        if ($this->enforceSearchBudget && app(ProductSearchCostBudget::class)->exceeded($updateId)) {
            return json_encode(['found' => false, 'reason' => 'search cost budget exhausted']);
        }
        if (! ($this->enforceSearchBudget ? $time->canStart($updateId, 20) : $time->assemblyCanStart($updateId, 20))) {
            return json_encode(['found' => false, 'reason' => 'search time exhausted']);
        }
        $target = (string) ($request['target_offer'] ?? 'current_offer');
        if (! in_array($target, ['current_offer', 'source_offer'], true)) {
            return json_encode(['found' => false, 'reason' => 'unknown target_offer']);
        }
        $selectedProduct = $draft->only(['title', 'model', 'brand', 'color', 'condition', 'specifications']);
        $inputFingerprint = hash('sha256', json_encode($selectedProduct, JSON_THROW_ON_ERROR));
        if ($target === 'source_offer') {
            $url = trim((string) ($this->assemblySource['url'] ?? ''));
            if ($url === '') {
                return json_encode(['found' => false, 'reason' => 'No selected gallery source was bound to this tool.']);
            }
            $observed = ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)
                ->where('url_hash', hash('sha256', ImageUrls::normalizeCandidateUrl($url)))->first();
            // The target is bound by the server, not an arbitrary model URL.
            // Do not keep the old offer's SKU/specifications in selected_product:
            // that would send the text researcher back to the same wrong SKU.
            $selectedProduct = [
                'source_url' => $url,
                'title' => $this->assemblySource['title'] ?? null,
                'identity_evidence' => $observed?->identity_evidence ?? ($this->assemblySource['_preflight_identity_evidence'] ?? ''),
                'observed_specification_text' => mb_substr($observed?->specification_text ?? ($this->assemblySource['_preflight_specification_text'] ?? ''), 0, 12_000),
                'condition' => $draft->condition,
            ];
        }
        $prompt = json_encode([
            'original_operator_request' => $draft->telegramUpdate?->text,
            'selected_product' => $selectedProduct,
            'known_sources' => $draft->sources,
            'known_evidence' => $draft->specification_evidence ?? [],
            'excluded_domains' => app(ProductSourcePriority::class)->blockedDomains(),
            'missing_or_conflicting_facts' => mb_substr((string) $request->string('question'), 0, 3000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $settings = app(AiSettings::class);
        $provider = $settings->providerFor('product_specification_reconciliation');
        $model = $settings->modelFor('product_specification_reconciliation');
        $run = AiRun::create([
            'telegram_update_id' => $updateId, 'provider' => $provider, 'model' => $model,
            'status' => 'running', 'prompt' => $prompt, 'started_at' => now(),
        ]);
        $searchItems = [];
        try {
            $response = app(StreamingProductResearch::class)->prompt(
                ProductTextEvidenceAgent::make(), $prompt, $provider, $model,
                $this->enforceSearchBudget ? $time->timeoutFor($updateId, $settings->productResearchTimeoutSeconds())
                    : $time->assemblyTimeoutFor($updateId, $settings->productResearchTimeoutSeconds()),
                $settings->productResearchIdleTimeoutSeconds(),
                function ($event) use (&$searchItems, $run): void {
                    if ($event instanceof ProviderToolEvent && $event->type === 'web_search_call' && $event->itemId !== '') {
                        $searchItems[$event->itemId] = true;
                        $run->update(['usage' => ['web_search_calls' => count($searchItems)], 'last_activity_at' => now()]);
                    }
                },
            );
            $run->update(['invocation_id' => $response->invocationId, 'response' => $response->toArray(),
                'usage' => [...$response->usage->toArray(), 'web_search_calls' => count($searchItems)]]);
            $data = $response->toArray();
            if (array_key_exists('facts', $data)) {
                $data['facts'] = ProductTextEvidenceContract::normalizeGroups($data['facts']);
            }
            $data = Validator::make($data, [
                ...ProductTextEvidenceContract::rules('facts', includeGroup: true),
                'facts' => ['present', 'array', 'max:100'],
                'summary' => ['required', 'string', 'max:1500'],
            ])->validate();
            $data['facts'] = array_values(array_filter($data['facts'],
                fn (array $fact): bool => ! app(ProductSourcePriority::class)->isBlockedUrl($fact['source_url'])));
            $run->update(['status' => 'completed', 'completed_at' => now()]);
            $draft->refresh();
            if ($draft->status !== 'pending_review' || $draft->telegramUpdate()->value('cancel_requested_at')
                || app(ProductSearchCancellation::class)->isCancelled($updateId)) {
                return json_encode(['found' => false, 'reason' => 'cancelled']);
            }
            if (! hash_equals($inputFingerprint, hash('sha256', json_encode(
                $draft->only(['title', 'model', 'brand', 'color', 'condition', 'specifications']), JSON_THROW_ON_ERROR)))) {
                return json_encode(['found' => false, 'reason' => 'Selected product changed during research. Evidence was not applied.']);
            }
            // Keep conflicting observations, deduplicate only identical facts.
            $facts = collect([...($draft->specification_evidence ?? []), ...$data['facts']])
                ->unique(fn ($fact) => hash('sha256', json_encode($fact)))->values()->all();
            $draft->update(['specification_evidence' => $facts]);

            return json_encode(['found' => true, ...$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);

            return json_encode(['found' => false, 'reason' => mb_substr($exception->getMessage(), 0, 800)]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->max(3000)->required(),
            'target_offer' => $schema->string()->description('current_offer researches the existing draft. source_offer researches the offer at the server-bound selected gallery page without committing a change. Defaults to current_offer.')->enum(['current_offer', 'source_offer']),
        ];
    }
}
