<?php

namespace App\Services\ProductSearch\Supervision;

use App\Ai\Agents\SearchProgressAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\ProductSearch\SearchJournal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Every few minutes of a long photo search an agent reads what the search has
 * done so far and may end the current page or the photo search early. Its
 * answer is advice the search follows; when the agent cannot answer, the
 * search continues exactly as it would have without it.
 */
final class SearchProgressSupervisor
{
    public const DECISIONS = ['continue', 'leave_current_page', 'deliver_now'];

    private const REVIEW_KEY = 'search-progress-review:';

    private const DELIVER_KEY = 'search-progress-deliver:';

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    /** A new search (or an operator's continuation) starts without an earlier "deliver now". */
    public function beginSession(?int $updateId): void
    {
        if ($updateId) {
            Cache::forget(self::DELIVER_KEY.$updateId);
            Cache::forget(self::REVIEW_KEY.$updateId);
        }
    }

    /** The reason the agent gave for ending the photo search, while that decision stands. */
    public function deliverNowReason(?int $updateId): ?string
    {
        $reason = $updateId ? Cache::get(self::DELIVER_KEY.$updateId) : null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $current  what the search is doing right now
     * @param  null|callable(string): void  $progress
     * @return array{decision: string, reason: ?string}
     */
    public function review(?int $updateId, ?ProductDraft $draft, array $current, ?callable $progress = null): array
    {
        $continue = ['decision' => 'continue', 'reason' => null];
        if (! $updateId || $draft === null) {
            return $continue;
        }
        if (($deliver = $this->deliverNowReason($updateId)) !== null) {
            return ['decision' => 'deliver_now', 'reason' => $deliver];
        }
        try {
            if (! config('services.search_progress_supervisor.enabled', true)
                || ($this->timeBudget->elapsedSeconds($updateId) ?? 0) < (int) config('services.search_progress_supervisor.after_seconds', 480)
                || $this->costBudget->limit() <= 0 || $this->costBudget->unmeasurable($updateId)
                || $this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 30)
                || ! Cache::add(self::REVIEW_KEY.$updateId, now()->toIso8601String(),
                    max(60, (int) config('services.search_progress_supervisor.interval_seconds', 240)))) {
                return $continue;
            }

            $verdict = $this->ask($draft, $updateId, $this->payload($draft, $updateId, $current));
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $verdict = null;
        }

        if ($verdict === null) {
            $this->record($draft, $updateId, $current, 'failed', 'supervisor_unavailable',
                'Наблюдатель поиска не ответил; поиск продолжается как обычно.', null);

            return $continue;
        }

        $this->record($draft, $updateId, $current, 'completed', $verdict['decision'], $verdict['reason'], $verdict);
        if ($verdict['decision'] === 'deliver_now') {
            Cache::put(self::DELIVER_KEY.$updateId, $verdict['reason'], now()->addHours(2));
            $progress?->__invoke('Наблюдатель поиска: заканчиваю поиск фото и собираю карточку из найденного. '.$verdict['reason']);
        } elseif ($verdict['decision'] === 'leave_current_page') {
            $progress?->__invoke('Наблюдатель поиска: ухожу с этой страницы к следующему источнику. '.$verdict['reason']);
        }

        return $verdict;
    }

    /** @return array{decision: string, reason: string} */
    public function validate(array $response): array
    {
        $data = Validator::make($response, [
            'decision' => ['required', Rule::in(self::DECISIONS)],
            'reason' => ['required', 'string', 'max:400'],
        ])->validate();

        return ['decision' => $data['decision'], 'reason' => trim($data['reason'])];
    }

    private function ask(ProductDraft $draft, int $updateId, array $payload): ?array
    {
        $prompt = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $provider = $this->settings->providerFor('search_progress_supervisor');
        $model = $this->settings->modelFor('search_progress_supervisor');
        $timeout = $this->timeBudget->timeoutFor($updateId, (int) config('services.search_progress_supervisor.timeout', 45));
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId, 'provider' => $provider, 'model' => $model,
            'status' => 'running', 'prompt' => $prompt, 'started_at' => now(),
            'activity' => ['stage' => 'search_progress_supervisor', 'search_budget' => true, 'product_draft_id' => $draft->id],
        ]);
        try {
            $response = app(OpenAiHeavyOperationGate::class)->run($provider, $timeout,
                fn () => SearchProgressAgent::make()->prompt($prompt, provider: $provider, model: $model, timeout: $timeout));
            $run->update(['invocation_id' => $response->invocationId,
                'response' => $response->toArray(), 'usage' => $response->usage->toArray()]);
            $verdict = $this->validate($response->toArray());
            $run->update(['status' => 'completed', 'completed_at' => now()]);

            return $verdict;
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);

            return null;
        }
    }

    private function payload(ProductDraft $draft, int $updateId, array $current): array
    {
        return [
            ...ProductRequestContext::forDraft($draft)->forAgent(),
            'elapsed_minutes' => round(($this->timeBudget->elapsedSeconds($updateId) ?? 0) / 60, 1),
            'working_minutes_left' => round(($this->timeBudget->remainingWorkingSeconds($updateId) ?? 0) / 60, 1),
            'search_budget' => ['spent_usd' => $this->costBudget->spent($updateId), 'limit_usd' => $this->costBudget->limit()],
            'photos_saved_for_card' => $draft->media()->count(),
            'current_activity' => $current,
            'pages_opened' => SearchJournal::pagesOpened($updateId, $draft),
            'structured_database_lookups' => SearchJournal::codeLookups($updateId),
            'earlier_supervisor_decisions' => ProductSourceAttempt::query()->where('telegram_update_id', $updateId)
                ->where('phase', 'search_supervision')->orderBy('id')->get(['decision', 'message'])
                ->map(fn (ProductSourceAttempt $row): array => ['decision' => $row->decision, 'reason' => $row->message])
                ->take(-5)->values()->all(),
        ];
    }

    private function record(ProductDraft $draft, int $updateId, array $current, string $status, string $decision, string $message, ?array $verdict): void
    {
        $this->attempts->record([
            'telegram_update_id' => $updateId, 'product_draft_id' => $draft->id,
            'product_url' => (string) ($current['url'] ?? $draft->primary_source_url ?? ''),
            'actor' => 'ai', 'phase' => 'search_supervision', 'action' => 'review_progress',
            'status' => $status, 'decision' => $decision, 'message' => mb_substr($message, 0, 1000),
            'input' => $current, 'output' => ['verdict' => $verdict],
        ]);
    }
}
