<?php

namespace App\Services\ProductSearch\Recovery;

use App\Ai\Agents\ProductCardRecoveryAgent;
use App\Ai\Tools\FetchProductSourcePageText;
use App\Ai\Tools\LookupProductCode;
use App\Ai\Tools\SearchProductTextEvidence;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductEvidenceProgress;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSourceAttemptRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Runs once local card repairs and the fallback model are exhausted while the
 * request still has budget. The agent picks a DIFFERENT step; this class
 * executes it with existing tools and only then lets the saved gallery's card
 * be reconciled again. It never searches or replaces photos.
 */
final class ProductCardRecoverySupervisor
{
    public const ACTIONS = ['repair_contract', 'research_text', 'read_text', 'lookup_code', 'stronger_model', 'blocked'];

    // A bug guard, not a budget: money, time and repeat detection stop sooner.
    private const MAX_STEPS = 6;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly CardRepairPatch $patch,
        private readonly ProductEvidenceProgress $evidenceProgress,
    ) {}

    /**
     * @param  null|callable(string): void  $progress
     * @return bool true when a different step changed the input and the card should be reconciled again
     */
    public function recover(ProductDraft $draft, int $updateId, ?callable $progress = null): bool
    {
        $cancellation = app(ProductSearchCancellation::class);
        $cancellation->throwIfCancelled($updateId);
        $state = $draft->card_repair_state ?? [];
        $failure = $state['last_failure'] ?? null;
        $history = array_values(array_filter($state['supervisor']['history'] ?? [], 'is_array'));
        $generation = $draft->telegram_update_id;
        if (! is_array($failure) || $draft->status !== 'pending_review') {
            return false;
        }
        if (count($history) >= self::MAX_STEPS) {
            $this->record($draft, $updateId, 'stop', 'interrupted', 'safety_limit',
                'Управляющее восстановление достигло страховочного предела шагов.', ['history' => $history]);

            return false;
        }
        if ($this->settings->maxSearchCostUsd() <= 0 || $this->costBudget->unmeasurable($updateId)
            || $this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 40)) {
            return false;
        }

        $evidenceBefore = $this->evidenceFingerprint($draft);
        $payload = $this->payload($draft, $updateId, $state, $failure, $history);
        $decision = $this->ask($draft, $updateId, $payload);
        if (! $this->stillCurrent($draft, $generation, $updateId)) {
            return false;
        }
        if ($decision === null) {
            $this->record($draft, $updateId, 'decide', 'failed', 'supervisor_unavailable',
                'Управляющий агент не вернул пригодного решения; сохранённые фото не тронуты.', []);

            return false;
        }

        $key = $this->actionKey($decision);
        // The same step on the same evidence buys the same answer again.
        if (collect($history)->contains(fn (array $step): bool => ($step['key'] ?? null) === $key
            && ($step['evidence_after'] ?? null) === $evidenceBefore)) {
            $this->record($draft, $updateId, $decision['action'], 'interrupted', 'repeat_without_new_evidence',
                'Предложенный шаг уже выполнялся без новых данных; повтор не оплачивается.',
                ['decision' => $decision, 'diagnosis' => $this->diagnosis($decision, $failure, 'repeat_without_new_evidence')]);

            return false;
        }

        $execution = match ($decision['action']) {
            'research_text' => $this->researchText($draft, $updateId, $decision),
            'read_text' => $this->readText($draft, $updateId, $decision),
            'repair_contract' => $this->repairContract($state, $decision),
            'lookup_code' => $this->lookupCode($draft, $updateId, $decision),
            // Last resort, 20x pricier: the next assembly may use the fallback model once.
            'stronger_model' => ['changed' => $this->settings->cardAssemblyFallbackModel() !== null, 'outcome' => 'stronger_model_allowed', 'detail' => null],
            default => ['changed' => false, 'outcome' => 'blocked', 'detail' => null],
        };
        if (! $this->stillCurrent($draft, $generation, $updateId)) {
            return false;
        }

        $draft->refresh();
        $state = $execution['state'] ?? ($draft->card_repair_state ?? []);
        $evidenceAfter = $this->evidenceFingerprint($draft);
        $changed = $decision['action'] !== 'blocked' && ($execution['changed'] || $evidenceAfter !== $evidenceBefore);
        $history[] = [
            'key' => $key, 'action' => $decision['action'],
            'query_or_url' => mb_substr($decision['query_or_url'], 0, 500),
            'repair_paths' => $decision['repair_paths'],
            'comment' => mb_substr($decision['comment'], 0, 500),
            'outcome' => $execution['outcome'], 'detail' => $execution['detail'],
            'evidence_before' => $evidenceBefore, 'evidence_after' => $evidenceAfter,
            'changed' => $changed, 'at' => now()->toIso8601String(),
        ];
        $state['supervisor'] = [
            'history' => $history,
            'generation' => (int) ($state['supervisor']['generation'] ?? 0) + ($changed ? 1 : 0),
            'guidance' => $changed ? mb_substr($decision['guidance'], 0, 2500) : ($state['supervisor']['guidance'] ?? null),
            'stronger_model' => $decision['action'] === 'stronger_model' && $changed,
        ];
        $draft->update(['card_repair_state' => $state]);

        $diagnosis = $this->diagnosis($decision, $failure, $changed ? null : $execution['outcome']);
        $this->record($draft, $updateId, $decision['action'], $changed ? 'completed' : 'interrupted',
            $changed ? 'retry_card_assembly' : $execution['outcome'],
            $decision['action'] === 'blocked'
                ? 'Управляющий агент сообщил о препятствии: '.mb_substr($decision['comment'], 0, 300)
                : ($changed
                    ? 'Выполнен другой шаг восстановления характеристик; сверка повторяется по сохранённой галерее.'
                    : 'Шаг восстановления не дал новых данных.'),
            ['decision' => $decision, 'execution' => $execution['detail'], 'diagnosis' => $diagnosis,
                'evidence_changed' => $evidenceAfter !== $evidenceBefore]);
        if ($changed) {
            $progress?->__invoke(match ($decision['action']) {
                'research_text' => 'Ищу недостающие сведения о характеристиках в других источниках; фото уже сохранены.',
                'read_text' => 'Читаю дополнительную страницу с характеристиками; фото уже сохранены.',
                'lookup_code' => 'Проверяю код товара в базах производителей; фото уже сохранены.',
                'stronger_model' => 'Последняя попытка: собираю карточку более сильной моделью.',
                default => 'Уточняю исправление карточки по уже собранным доказательствам.',
            });
        }

        return $changed;
    }

    private function ask(ProductDraft $draft, int $updateId, array $payload): ?array
    {
        $prompt = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $provider = $this->settings->providerFor('product_specification_reconciliation');
        $model = $this->settings->modelFor('product_specification_reconciliation');
        $timeout = $this->timeBudget->timeoutFor($updateId, (int) config('services.product_specification_reconciliation.timeout', 90));
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId, 'provider' => $provider, 'model' => $model,
            'status' => 'running', 'prompt' => $prompt, 'started_at' => now(),
            'activity' => ['stage' => 'card_recovery_supervisor', 'search_budget' => true, 'product_draft_id' => $draft->id],
        ]);
        try {
            $response = app(OpenAiHeavyOperationGate::class)->run($provider, $timeout,
                function () use ($prompt, $provider, $model, $timeout, $updateId) {
                    app(ProductSearchCancellation::class)->throwIfCancelled($updateId);

                    return ProductCardRecoveryAgent::make()->prompt($prompt,
                        provider: $provider, model: $model, timeout: $timeout);
                });
            // A rejected structured answer is still a paid call.
            $run->update(['invocation_id' => $response->invocationId,
                'response' => $response->toArray(), 'usage' => $response->usage->toArray()]);
            app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
            $decision = $this->validate($response->toArray());
            $run->update(['status' => 'completed', 'completed_at' => now()]);

            return $decision;
        } catch (ProductSearchCancelled $exception) {
            $run->update(['status' => 'cancelled', 'error' => $exception->getMessage(), 'completed_at' => now()]);
            throw $exception;
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);

            return null;
        }
    }

    /** @return array{action: string, comment: string, guidance: string, query_or_url: string, repair_paths: list<string>, blocked_by: list<string>} */
    public function validate(array $response): array
    {
        $data = Validator::make($response, [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'comment' => ['required', 'string', 'max:1500'],
            'guidance' => ['present', 'nullable', 'string', 'max:2500'],
            'query_or_url' => ['present', 'nullable', 'string', 'max:2000'],
            'repair_paths' => ['present', 'array', 'max:50'],
            'repair_paths.*' => ['string', 'max:200'],
            'blocked_by' => ['present', 'array', 'max:30'],
            'blocked_by.*' => ['string', 'max:200'],
        ])->validate();
        $data['guidance'] = trim((string) $data['guidance']);
        $data['query_or_url'] = trim((string) $data['query_or_url']);
        $data['repair_paths'] = array_values(array_unique($data['repair_paths']));
        if (in_array($data['action'], ['research_text', 'read_text', 'lookup_code'], true) && $data['query_or_url'] === '') {
            throw ValidationException::withMessages(['query_or_url' => 'This action needs a question or URL.']);
        }
        if ($data['action'] === 'read_text' && ! $this->publicUrl($data['query_or_url'])) {
            throw ValidationException::withMessages(['query_or_url' => 'read_text needs a public http(s) URL.']);
        }

        return $data;
    }

    private function researchText(ProductDraft $draft, int $updateId, array $decision): array
    {
        $result = json_decode((string) (new SearchProductTextEvidence($draft->id, $updateId,
            assemblySource: $draft->photo_source_context ?? null, enforceSearchBudget: true))
            ->handle(new Request(['question' => $decision['query_or_url'], 'target_offer' => 'current_offer'])), true) ?: [];
        $found = (bool) ($result['found'] ?? false);
        $factCount = is_array($result['facts'] ?? null) ? count($result['facts']) : 0;

        return ['changed' => false, 'outcome' => $found && $factCount > 0 ? 'evidence_collected' : 'no_new_evidence',
            'detail' => ['found' => $found, 'reason' => $result['reason'] ?? null,
                'fact_count' => $factCount,
                'summary' => isset($result['summary']) ? mb_substr((string) $result['summary'], 0, 500) : null]];
    }

    private function readText(ProductDraft $draft, int $updateId, array $decision): array
    {
        $host = strtolower((string) parse_url($decision['query_or_url'], PHP_URL_HOST));
        $result = json_decode((string) (new FetchProductSourcePageText($host, $updateId, $draft->id, allowAdditionalSources: true))
            ->handle(new Request(['url' => $decision['query_or_url'], 'open_selector' => null, 'force_browser' => false])), true) ?: [];
        $fetched = (bool) ($result['fetched'] ?? false);

        return ['changed' => false, 'outcome' => $fetched ? 'page_read' : 'page_not_read',
            'detail' => ['fetched' => $fetched, 'reason' => $result['reason'] ?? null, 'final_url' => $result['final_url'] ?? null]];
    }

    private function lookupCode(ProductDraft $draft, int $updateId, array $decision): array
    {
        $result = json_decode((string) (new LookupProductCode($updateId, $draft->id))
            ->handle(new Request(['code' => $decision['query_or_url'], 'brand' => $draft->brand])), true) ?: [];
        $found = collect($result['results'] ?? [])->where('status', 'found');

        return ['changed' => false, 'outcome' => $found->isNotEmpty() ? 'code_found' : 'code_not_found',
            'detail' => ['found' => $found->map(fn (array $item): array => array_intersect_key($item, array_flip(['source', 'title', 'part_number', 'page_url'])))->values()->all()]];
    }

    private function repairContract(array $state, array $decision): array
    {
        $detail = ['guidance' => $decision['guidance'] !== '', 'expanded_paths' => []];
        $prior = $state['previous_feedback']['repair_context']['patch_repair'] ?? null;
        if ($decision['repair_paths'] !== [] && is_array($prior)) {
            try {
                $state['previous_feedback']['repair_context']['patch_repair'] = $this->patch->expandForRecovery($prior, $decision['repair_paths']);
                $detail['expanded_paths'] = $decision['repair_paths'];
            } catch (ValidationException $exception) {
                $detail['rejected_paths'] = $exception->errors();
            }
        }
        $changed = $decision['guidance'] !== '' || $detail['expanded_paths'] !== [];

        return ['changed' => $changed, 'outcome' => $changed ? 'contract_clarified' : 'nothing_to_clarify',
            'detail' => $detail, 'state' => $state];
    }

    private function payload(ProductDraft $draft, int $updateId, array $state, array $failure, array $history): array
    {
        $context = is_array($failure['repair_context'] ?? null) ? $failure['repair_context'] : [];
        $patch = $state['previous_feedback']['repair_context']['patch_repair'] ?? null;
        $affected = is_array($patch['paths'] ?? null) ? collect($patch['paths'])
            ->filter(fn ($segments): bool => is_array($segments) && ($segments[0] ?? null) === 'specifications')
            ->map(fn (array $segments): ?string => $patch['base']['specifications'][$segments[1]]['key'] ?? null)
            ->filter()->unique()->values()->all() : [];
        $textSources = collect($draft->specification_evidence ?? [])->pluck('source_url')
            ->merge(ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->pluck('url'))
            ->filter(fn ($url): bool => is_string($url) && $url !== '')->unique()->take(40)->values()->all();

        return [
            ...ProductRequestContext::forDraft($draft)->forAgent(),
            'blockers' => [
                'status' => $failure['status'] ?? null,
                'reason' => $failure['reason'] ?? null,
                'assembly_summary' => isset($failure['summary']) ? mb_substr((string) $failure['summary'], 0, 2000) : null,
                'failure_kind' => $context['failure_kind'] ?? null,
                'unresolved_fields' => array_slice((array) ($context['unresolved_fields'] ?? []), 0, 30),
                'validation_errors' => mb_substr(json_encode($context['validation_errors'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', 0, 4000),
                'citation_obligations' => $context['citation_obligations'] ?? [],
            ],
            'affected_specification_keys' => $affected,
            'saved_photos' => ['source_url' => $draft->primary_source_url, 'count' => $draft->media()->count(), 'kept' => true],
            'research_sources' => collect($draft->sources ?? [])->map(fn ($source): array => is_array($source)
                ? array_intersect_key($source, array_flip(['url', 'title', 'type', 'roles'])) : [])->filter()->take(20)->values()->all(),
            'saved_text_evidence_urls' => $textSources,
            'executed_recovery_steps' => array_map(fn (array $step): array => array_intersect_key($step,
                array_flip(['action', 'query_or_url', 'repair_paths', 'outcome', 'changed'])), array_slice($history, -8)),
            'search_budget' => ['spent_usd' => $this->costBudget->spent($updateId), 'limit_usd' => $this->settings->maxSearchCostUsd()],
        ];
    }

    private function diagnosis(array $decision, array $failure, ?string $stop): array
    {
        return FailureDiagnosis::make('card_recovery',
            $stop ?? (string) ($failure['repair_context']['failure_kind'] ?? $failure['reason'] ?? $failure['status'] ?? 'not_ready'),
            serverReason: isset($failure['summary']) ? (string) $failure['summary'] : null,
            agentComment: $decision['comment'],
            blockedBy: $decision['blocked_by'],
            suggestedNextAction: $decision['action'],
            retained: ['photos_preserved' => true]);
    }

    private function actionKey(array $decision): string
    {
        $paths = $decision['repair_paths'];
        sort($paths);

        return hash('sha256', json_encode([$decision['action'],
            mb_strtolower(preg_replace('/\s+/u', ' ', $decision['query_or_url']) ?? ''), $paths], JSON_THROW_ON_ERROR));
    }

    private function evidenceFingerprint(ProductDraft $draft): string
    {
        $observations = array_keys($this->evidenceProgress->snapshot($draft));
        sort($observations);

        return hash('sha256', json_encode($observations, JSON_THROW_ON_ERROR));
    }

    private function stillCurrent(ProductDraft $draft, mixed $generation, int $updateId): bool
    {
        $fresh = $draft->fresh();

        return $fresh !== null && $fresh->status === 'pending_review'
            && $fresh->telegram_update_id === $generation
            && ! app(ProductSearchCancellation::class)->isCancelled($updateId);
    }

    private function publicUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isPublicIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        // The fetch tool applies its own redirect/network policy; this only
        // refuses obviously private targets before a paid step is recorded.
        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && $host !== '' && ! isset($parts['user']) && ! isset($parts['pass'])
            && $host !== 'localhost' && ! str_ends_with($host, '.local') && ! str_ends_with($host, '.internal')
            && (! $isIp || $isPublicIp);
    }

    private function record(ProductDraft $draft, int $updateId, string $action, string $status, string $decision, string $message, array $output): void
    {
        $this->attempts->record([
            'telegram_update_id' => $updateId, 'product_draft_id' => $draft->id,
            'product_url' => $draft->primary_source_url ?? '', 'actor' => 'ai',
            'phase' => 'card_recovery', 'action' => $action, 'status' => $status,
            'decision' => $decision, 'message' => $message, 'output' => $output,
        ]);
    }
}
