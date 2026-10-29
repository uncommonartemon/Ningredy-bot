<?php

namespace App\Services\ProductSearch\Supervision;

use App\Ai\Agents\SearchProgressAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\Category;
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
 * While a photo search runs, an agent reads its whole story - every step in
 * order, what keeps repeating, its own earlier answers - and may end the
 * current page, drop a site or end the photo search. It looks whenever the
 * search has done something new, at most about once a minute, and every few
 * minutes while the search is silent; never twice at the same picture. Its
 * answers go to one mailbox every loop of the search reads. When the agent
 * cannot answer, the search continues exactly as it would have without it.
 */
final class SearchProgressSupervisor
{
    public const DECISIONS = ['continue', 'leave_current_page', 'skip_site', 'deliver_now', 'show_now'];

    private const LOOK_KEY = 'search-progress-look:';

    private const DELIVER_KEY = 'search-progress-deliver:';

    private const LEAVE_KEY = 'search-progress-leave:';

    private const DROPPED_KEY = 'search-progress-dropped:';

    private const RULED_OUT_KEY = 'search-progress-ruled-out:';

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    /** A new search (or an operator's continuation) starts without an earlier "deliver now". */
    public function beginSession(?int $updateId): void
    {
        // A standing "end the photo search" belongs to the request - one update id -
        // for as long as it lives, automatic continuation cycles included: live #178
        // wrapped up at the watcher's word, assembled its card, and the automatic
        // continuation then began a new cycle every fifteen seconds with the verdict
        // forgotten. An operator's continuation is a new request with its own id.
    }

    /** The reason the agent gave for ending the photo search, while that decision stands. */
    public function deliverNowReason(?int $updateId): ?string
    {
        $reason = $updateId ? Cache::get(self::DELIVER_KEY.$updateId) : null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * The same standing "end the photo search, assemble the card" verdict, given
     * from outside the search: the guardian says it at the time limit, and the
     * search obeys at its next step exactly as it obeys the agent's own.
     */
    public function requestWrapUp(int $updateId, string $reason): void
    {
        Cache::put(self::DELIVER_KEY.$updateId, $reason, now()->addHours(2));
    }

    /**
     * A "leave the current page" not yet carried out, for the page it was said
     * about; taking it marks it done. A page that ended before it could act
     * leaves the verdict to expire, so it never ends the next page instead.
     */
    public function takeLeaveVerdict(?int $updateId, ?string $url = null): ?string
    {
        $verdict = $updateId ? Cache::get(self::LEAVE_KEY.$updateId) : null;
        if (! is_array($verdict) || ! is_string($verdict['reason'] ?? null)
            || (filled($verdict['url'] ?? null) && filled($url) && rtrim((string) $verdict['url'], '/') !== rtrim((string) $url, '/'))) {
            return null;
        }
        Cache::forget(self::LEAVE_KEY.$updateId);

        return $verdict['reason'];
    }

    /** No page of a site the agent dropped is opened again in this search. */
    public function dropSite(?int $updateId, string $site, string $reason): void
    {
        $site = self::site($site);
        if (! $updateId || $site === '') {
            return;
        }
        Cache::put(self::DROPPED_KEY.$updateId, [...$this->droppedSites($updateId), $site => $reason], now()->addHours(2));
    }

    /**
     * The browser just took frames from a page of a site the agent had dropped: the
     * drop no longer stands (live #328: dns-shop.ru answered the quick check with 401,
     * the agent dropped the site, the browser then confirmed 24 frames, and the drop
     * threw them away - the card went out with none). True when a drop was lifted.
     */
    public function liftSiteDrop(?int $updateId, string $site): bool
    {
        $site = self::site($site);
        $dropped = $this->droppedSites($updateId);
        if (! $updateId || $site === '' || ! array_key_exists($site, $dropped)) {
            return false;
        }
        unset($dropped[$site]);
        Cache::put(self::DROPPED_KEY.$updateId, $dropped, now()->addHours(2));

        return true;
    }

    /** Why the agent dropped the site of this page or host, while that stands. */
    public function siteDropReason(?int $updateId, ?string $urlOrHost): ?string
    {
        $reason = $updateId && filled($urlOrHost) ? ($this->droppedSites($updateId)[self::site((string) $urlOrHost)] ?? null) : null;

        return is_string($reason) ? $reason : null;
    }

    /** @return array<string, string> host => the agent's reason */
    public function droppedSites(?int $updateId): array
    {
        $dropped = $updateId ? Cache::get(self::DROPPED_KEY.$updateId, []) : [];

        return is_array($dropped) ? $dropped : [];
    }

    /** One spelling per site: a page address or a host, without case or "www.". */
    public static function site(string $urlOrHost): string
    {
        $host = str_contains($urlOrHost, '://') ? (string) parse_url($urlOrHost, PHP_URL_HOST) : $urlOrHost;

        return (string) preg_replace('/^www\./', '', strtolower(trim($host)));
    }

    /**
     * The frames a page was showing when the agent ended it. The same frames
     * on another page of that site are the same dead end (live #58: the
     * watcher left de.msi.com/…/shopnow after eight rounds of five frames, and
     * …/ without /shopnow then trained eight more rounds on those five).
     */
    public function ruleOutFrames(?int $updateId, string $host, string $frameSet, string $reason): void
    {
        if (! $updateId || $host === '' || $frameSet === '') {
            return;
        }
        $ruledOut = Cache::get(self::RULED_OUT_KEY.$updateId, []);
        $ruledOut[strtolower($host)][$frameSet] = $reason;
        Cache::put(self::RULED_OUT_KEY.$updateId, $ruledOut, now()->addHours(2));
    }

    public function ruledOutReason(?int $updateId, string $host, string $frameSet): ?string
    {
        $reason = $updateId ? (Cache::get(self::RULED_OUT_KEY.$updateId, [])[strtolower($host)][$frameSet] ?? null) : null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $current  what the search is doing right now
     * @param  null|callable(string): void  $progress
     * @param  bool  $fromOutside  asked by the request guardian on its minute: the search may be
     *                             inside a step that has not returned, so the age and budget
     *                             gates that pace in-search looks do not apply
     * @return array{decision: string, reason: ?string}
     */
    public function review(?int $updateId, ?ProductDraft $draft, array $current, ?callable $progress = null, bool $fromOutside = false): array
    {
        $continue = ['decision' => 'continue', 'reason' => null];
        if (! $updateId || $draft === null) {
            return $continue;
        }
        // The search obeys a standing verdict; a look from outside is always a fresh decision.
        if (! $fromOutside && ($deliver = $this->deliverNowReason($updateId)) !== null) {
            return ['decision' => 'deliver_now', 'reason' => $deliver];
        }
        try {
            $mark = SearchJournal::mark($updateId);
            if (! config('services.search_progress_supervisor.enabled', true)
                || (! $fromOutside && (($this->timeBudget->elapsedSeconds($updateId) ?? 0) < (int) config('services.search_progress_supervisor.after_seconds', 480)
                    // Money can go faster than time: live #566 spent a dollar in four minutes.
                    && ($this->costBudget->spentFraction($updateId) ?? 0) < (float) config('services.search_progress_supervisor.after_spent_fraction', 0.4)))
                || (! $fromOutside && ($this->costBudget->limit() <= 0 || $this->costBudget->unmeasurable($updateId)
                    || $this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 30)))
                || ! $this->due($updateId, $mark, $fromOutside)) {
                return $continue;
            }

            $verdict = $this->ask($draft, $updateId, $this->payload($draft, $updateId, $current), $fromOutside);
        } catch (ProductSearchCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $verdict = null;
        }

        if ($verdict === null) {
            $this->record($draft, $updateId, $current, 'failed', 'supervisor_unavailable',
                'Наблюдатель поиска не ответил; поиск продолжается как обычно.', null, $mark ?? 0);

            return $continue;
        }
        if ($verdict['decision'] === 'skip_site'
            && ($verdict['site'] = $this->siteToDrop($updateId, $verdict['site'], $current)) === null) {
            // A site nobody can name cannot be dropped; the page in work can still be left.
            $verdict['decision'] = 'leave_current_page';
        }
        // Once the card is being assembled the photo search is over: a verdict about it
        // from outside would only leave a standing "wrap up" for a search that has
        // already wrapped up (live #168, #173). A stuck assembly is still show_now.
        if ($fromOutside && in_array($verdict['decision'], ['deliver_now', 'leave_current_page', 'skip_site'], true)
            && SearchJournal::phase($updateId) === 'card_assembly') {
            $verdict = [...$verdict, 'decision' => 'continue', 'site' => null,
                'reason' => 'Поиск фото уже завершён, идёт сборка карточки. '.$verdict['reason']];
        }
        if ($verdict['decision'] === 'deliver_now' && ($premature = $this->tooEarlyToEnd($updateId, $draft, $current)) !== null) {
            $verdict = [...$verdict, 'decision' => 'continue', 'site' => null,
                'reason' => 'Рано сворачивать: '.$premature.'. '.$verdict['reason']];
        }

        $this->record($draft, $updateId, $current, 'completed', $verdict['decision'], $verdict['reason'], $verdict, $mark);
        if ($verdict['decision'] === 'skip_site') {
            $this->dropSite($updateId, $verdict['site'], $verdict['reason']);
        } elseif ($verdict['decision'] === 'leave_current_page' && $fromOutside) {
            // A look from outside cannot reach the page itself, so the page in
            // training carries it out at its next round: the guardian's look said
            // "leave" twice for live #58 and nothing acted on it. A verdict given
            // inside the search is carried out by its caller on the spot.
            Cache::put(self::LEAVE_KEY.$updateId, ['reason' => $verdict['reason'], 'url' => $current['url'] ?? null], now()->addMinutes(10));
        }
        if ($verdict['decision'] === 'deliver_now') {
            Cache::put(self::DELIVER_KEY.$updateId, $verdict['reason'], now()->addHours(2));
            $progress?->__invoke('Наблюдатель поиска: заканчиваю поиск фото и собираю карточку из найденного. '.$verdict['reason']);
        } elseif ($verdict['decision'] === 'skip_site') {
            $progress?->__invoke("Наблюдатель поиска: больше не открываю страницы сайта {$verdict['site']}. ".$verdict['reason']);
        } elseif ($verdict['decision'] === 'leave_current_page') {
            $progress?->__invoke('Наблюдатель поиска: ухожу с этой страницы к следующему источнику. '.$verdict['reason']);
        }

        return $verdict;
    }

    /**
     * Why ending the photo search now would be premature, or null when it may end.
     * With fewer photos than the card's minimum, time and money left, and the
     * fallback discovery of new shops not yet tried, "deliver now" is a card
     * without photos for no reason: live #203 ended at minute 16 with none while
     * the shops that had the photos were one discovery round away; #178, #179,
     * #200 the same. The watcher judges; this is the one line it may not cross.
     */
    public function tooEarlyToEnd(int $updateId, ProductDraft $draft, array $current = []): ?string
    {
        $minimum = Category::query()->where('slug', trim((string) $draft->category))->first()?->minimumVerifiedImages() ?? 3;
        $photos = max($draft->media()->count(), SearchJournal::acceptedPhotos($updateId));
        if ($photos >= $minimum || ($current['kind'] ?? null) === 'fallback_discovery' || SearchJournal::fallbackRounds($updateId) > 0
            || $this->costBudget->exceeded($updateId) || ! $this->timeBudget->canStart($updateId, 180)) {
            return null;
        }

        return "фото {$photos} из {$minimum}, а резервный поиск новых магазинов ещё не запускался";
    }

    /** @return array{decision: string, reason: string, operator_note: ?string, site: ?string} */
    public function validate(array $response): array
    {
        $data = Validator::make($response, [
            'decision' => ['required', Rule::in(self::DECISIONS)],
            'reason' => ['required', 'string', 'max:400'],
            'operator_note' => ['sometimes', 'nullable', 'string', 'max:400'],
            'site' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        return ['decision' => $data['decision'], 'reason' => trim($data['reason']),
            'operator_note' => filled($data['operator_note'] ?? null) ? trim($data['operator_note']) : null,
            'site' => filled($data['site'] ?? null) ? trim($data['site']) : null];
    }

    /**
     * One look at a time, and never twice at the same picture. From outside -
     * the guardian's minute - a look when the search did something since the
     * last one, and every few minutes while it is silent: the silence is then
     * the news. The search's own checkpoints look only when nobody has for a
     * while, so a lagging guardian is covered and no look is paid for twice.
     */
    private function due(int $updateId, int $mark, bool $fromOutside): bool
    {
        $last = ProductSourceAttempt::query()->where('telegram_update_id', $updateId)
            ->where('phase', 'search_supervision')->latest('id')->first(['created_at', 'output']);
        if ($last !== null) {
            $since = now()->getTimestamp() - $last->created_at->getTimestamp();
            $changed = $mark > (int) data_get($last->output, 'journal_mark', 0);
            $due = $fromOutside
                ? ($changed && $since >= (int) config('services.request_guardian.look_after_activity_seconds', 60))
                    || $since >= (int) config('services.request_guardian.review_interval_seconds', 180)
                : $changed && $since >= (int) config('services.search_progress_supervisor.interval_seconds', 90);
            if (! $due) {
                return false;
            }
        }

        // The guardian and the search may reach the same moment: one of them looks.
        return Cache::add(self::LOOK_KEY.$updateId, true, now()->addSeconds(30));
    }

    /**
     * The site the agent named when this search has been there (or it is the
     * page in work), else the page in work's own site; null when neither is known.
     */
    private function siteToDrop(int $updateId, ?string $named, array $current): ?string
    {
        $here = self::site((string) ($current['host'] ?? $current['url'] ?? ''));
        $named = self::site((string) $named);
        if ($named === '' || $named === $here) {
            return $here !== '' ? $here : null;
        }
        $seen = ProductSourceAttempt::query()->where('telegram_update_id', $updateId)->whereNotIn('phase', SearchJournal::OWN_PHASES)
            ->where('product_url', '!=', '')->distinct()->pluck('product_url')
            ->map(fn (string $url): string => self::site($url))->filter()->unique()->values();
        // "msi.com" for the one MSI host this search opened is still that host.
        $matches = $seen->contains($named) ? collect([$named]) : $seen->filter(fn (string $site): bool => str_ends_with($site, '.'.$named));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function ask(ProductDraft $draft, int $updateId, array $payload, bool $fromOutside = false): ?array
    {
        $prompt = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $provider = $this->settings->providerFor('search_progress_supervisor');
        $model = $this->settings->modelFor('search_progress_supervisor');
        $timeout = $fromOutside
            ? max(5, (int) config('services.request_guardian.outside_review_timeout', 30))
            : $this->timeBudget->timeoutFor($updateId, (int) config('services.search_progress_supervisor.timeout', 45));
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId, 'provider' => $provider, 'model' => $model,
            'status' => 'running', 'prompt' => $prompt, 'started_at' => now(),
            'activity' => ['stage' => 'search_progress_supervisor', 'search_budget' => true, 'product_draft_id' => $draft->id],
        ]);
        try {
            $call = fn () => SearchProgressAgent::make()->prompt($prompt, provider: $provider, model: $model, timeout: $timeout);
            // The stalled search may be the one holding the heavy-operation gate;
            // a short look from outside must not queue behind it.
            $response = $fromOutside ? $call() : app(OpenAiHeavyOperationGate::class)->run($provider, $timeout, $call);
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
        // What only grows comes first: the provider discounts a prompt's repeated beginning.
        return [
            ...ProductRequestContext::forDraft($draft)->forAgent(),
            'timeline' => SearchJournal::timeline($updateId),
            'repeats' => SearchJournal::repeats($updateId),
            'pages_opened' => SearchJournal::pagesOpened($updateId, $draft),
            'structured_database_lookups' => SearchJournal::codeLookups($updateId),
            'elapsed_minutes' => round(($this->timeBudget->elapsedSeconds($updateId) ?? 0) / 60, 1),
            'working_minutes_left' => round(($this->timeBudget->remainingWorkingSeconds($updateId) ?? 0) / 60, 1),
            'search_budget' => ['spent_usd' => $this->costBudget->spent($updateId), 'limit_usd' => $this->costBudget->limit()],
            'photos_saved_for_card' => $draft->media()->count(),
            'minimum_photos_for_card' => Category::query()->where('slug', trim((string) $draft->category))->first()?->minimumVerifiedImages() ?? 3,
            'minutes_since_last_accepted_photo' => SearchJournal::minutesSinceLastAcceptedPhoto($updateId),
            'fallback_discovery_rounds' => SearchJournal::fallbackRounds($updateId),
            'search_phase' => SearchJournal::phase($updateId),
            'frames_held_unchecked' => array_intersect_key($this->timeBudget->heldForCheck($updateId), array_flip(['sets', 'frames'])),
            'current_activity' => $current,
            'open_calls' => SearchJournal::openCalls($updateId),
            'sites_dropped' => $this->droppedSites($updateId),
            'earlier_supervisor_decisions' => ProductSourceAttempt::query()->where('telegram_update_id', $updateId)
                ->where('phase', 'search_supervision')->orderBy('id')->get(['decision', 'message'])
                ->map(fn (ProductSourceAttempt $row): array => ['decision' => $row->decision, 'reason' => $row->message])
                ->take(-5)->values()->all(),
        ];
    }

    /** @param int $mark the journal as this look saw it: the next look waits for something newer */
    private function record(ProductDraft $draft, int $updateId, array $current, string $status, string $decision, string $message, ?array $verdict, int $mark): void
    {
        $this->attempts->record([
            'telegram_update_id' => $updateId, 'product_draft_id' => $draft->id,
            'product_url' => (string) ($current['url'] ?? $draft->primary_source_url ?? ''),
            'actor' => 'ai', 'phase' => 'search_supervision', 'action' => 'review_progress',
            'status' => $status, 'decision' => $decision, 'message' => mb_substr($message, 0, 1000),
            'input' => $current, 'output' => ['verdict' => $verdict, 'journal_mark' => $mark],
        ]);
    }
}
