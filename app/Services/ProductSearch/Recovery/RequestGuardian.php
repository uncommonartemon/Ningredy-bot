<?php

namespace App\Services\ProductSearch\Recovery;

use App\Jobs\DeliverTelegramAssistantReply;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\ProductSearch\Supervision\SearchProgressSupervisor;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Looks at the operator's open requests from outside the search process, once a
 * minute. The watcher, the failure saver and the card repair all run inside the
 * search and are called by it between steps: when one step hangs, nobody calls
 * them, and a request waiting behind a long search is invisible to all of them.
 *
 * Judgement belongs to the watcher agent (operator rule, 2026-10-21). It reads
 * the search journal itself; the guardian only wakes it every few minutes while
 * a search runs and adds the one fact the journal cannot show - how long since
 * the last step. The agent lets the search run, has it wrap up into a card, or
 * shows what exists right now, and decides whether the operator hears anything.
 * The app keeps only the operator's own time budget (two minutes before the
 * limit the operator gets what exists) and facts that need no judgement: a
 * request queued behind another search, a vanished process, an undelivered reply.
 *
 * Nothing here raises a budget or starts a paid search, and no message carries
 * a technical error. Every action is recorded in the search journal (phase
 * guardian) and taken at most once per request.
 */
final class RequestGuardian
{
    private const OPEN_STATUSES = ['received', 'transcribed', 'processing', 'delivering'];

    /** Rows the guardian and the watcher write themselves never prove a search alive. */
    private const OWN_PHASES = ['search_supervision', 'guardian', 'delivery_guard'];

    private const OWN_STAGES = ['search_progress_supervisor'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly SearchProgressSupervisor $supervisor,
        private readonly DraftTelegramPresenter $presenter,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly AiSettings $settings,
    ) {}

    /** @return list<string> one line per action taken */
    public function sweep(): array
    {
        if (! config('services.request_guardian.enabled', true)) {
            return [];
        }
        [$jobs, $busy] = $this->queueState();
        $done = [];
        TelegramUpdate::query()->whereIn('status', self::OPEN_STATUSES)->whereNull('processed_at')
            ->whereNull('cancel_requested_at')->whereNotNull('chat_id')
            ->where('created_at', '>', now()->subHours(6))->orderBy('id')
            ->each(function (TelegramUpdate $update) use ($jobs, $busy, &$done): void {
                try {
                    $action = in_array($update->status, ['received', 'transcribed'], true)
                        ? $this->watchQueued($update, $jobs, $busy)
                        : $this->watchRunning($update, $jobs);
                } catch (Throwable $exception) {
                    // One request's trouble must not hide the others.
                    report($exception);
                    $action = null;
                }
                if ($action !== null) {
                    $done[] = "#{$update->id} {$action}";
                }
            });

        return [...$done, ...$this->redeliverStuckReplies()];
    }

    /**
     * Shows what the request has already found and stops the rest of it. Also
     * the "Показать найденное" button. The cancellation goes first: the stuck
     * process then discards its late result when it returns, and the presenter
     * does not start another automatic cycle for a cancelled request.
     */
    public function showFound(TelegramUpdate $update, string $intro): bool
    {
        $draft = $this->draftFor($update);
        if ($draft === null) {
            return false;
        }
        $payload = TelegramUpdate::query()->whereKey($update->id)->value('payload');
        $payload = is_array($payload) ? $payload : (json_decode((string) $payload, true) ?: []);
        TelegramUpdate::query()->whereKey($update->id)->update([
            'cancel_requested_at' => now(),
            'payload' => json_encode([...$payload, '_guard_delivered_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE),
        ]);
        if ($draft->gallery_search_stop_reason === null) {
            // Gives the card its "continue search" button with a fresh budget.
            $draft->forceFill(['gallery_search_stop_reason' => 'stalled'])->save();
        }
        $chat = (string) $update->chat_id;
        $photos = count($draft->verifiedPhotoPaths());
        $this->telegram->sendMessage($chat, $intro.' '.match (true) {
            $photos === 0 => 'Фото не найдены, характеристики сохранены. Продолжить поиск фото можно кнопкой под карточкой.',
            $draft->reconciliationPending() => "Сохранено фото: {$photos}, характеристики не сверены. Можно принять как есть, продолжить поиск или исправить.",
            default => "Сохранено фото: {$photos}. Можно добавить в каталог, продолжить поиск или исправить.",
        });
        $this->presenter->sendReview($this->telegram, $chat, $draft->fresh('media'));
        TelegramUpdate::query()->whereKey($update->id)->update(['status' => 'completed', 'processed_at' => now()]);
        $this->record($update, $draft, 'show_found', 'completed', $intro, ['photos' => $draft->media()->count()]);

        return true;
    }

    /** The guardian already showed what this request found: its late end is not a cancellation. */
    public static function delivered(int $updateId): bool
    {
        return filled(data_get(TelegramUpdate::query()->find($updateId)?->payload, '_guard_delivered_at'));
    }

    public function draftFor(TelegramUpdate $update): ?ProductDraft
    {
        // An operator's continuation works on a draft of an earlier request.
        return ProductDraft::query()->where('telegram_update_id', $update->id)->where('status', 'pending_review')->latest('id')->first()
            ?? ProductDraft::query()->where('status', 'pending_review')->whereKey(
                ProductSourceAttempt::query()->where('telegram_update_id', $update->id)->whereNotNull('product_draft_id')
                    ->latest('id')->value('product_draft_id'))->first();
    }

    /** @param array<int, array{queue: string, reserved_at: ?int, created_at: int}> $jobs */
    private function watchQueued(TelegramUpdate $update, array $jobs, array $busy): ?string
    {
        $job = $jobs[$update->id] ?? null;
        if ($job === null || $job['reserved_at'] !== null
            || now()->timestamp - $job['created_at'] < (int) config('services.request_guardian.queue_notice_after_seconds', 60)) {
            return null;
        }
        // Nothing running ahead means a stopped worker: queue:health-check reports that.
        $running = $busy[$job['queue']] ?? null;
        if ($running === null || ! $this->claim('queue_notice', $update)) {
            return null;
        }
        $minutes = max(1, intdiv(now()->timestamp - $running, 60));
        $this->telegram->sendMessage((string) $update->chat_id,
            "⏳ Запрос принят и ждёт очереди: сейчас выполняется другой поиск (идёт {$minutes} мин). Начну сразу после него.",
            ['inline_keyboard' => [[['text' => '✖ Отменить этот запрос', 'callback_data' => "search:cancel:{$update->id}"]]]]);
        $this->record($update, null, 'queue_notice', 'completed', 'Запрос ждёт за другим поиском; оператор предупреждён.', ['ahead_minutes' => $minutes]);

        return 'queue_notice';
    }

    /** @param array<int, array{queue: string, reserved_at: ?int, created_at: int}> $jobs */
    private function watchRunning(TelegramUpdate $update, array $jobs): ?string
    {
        $silent = $this->silentSeconds($update);
        $job = $jobs[$update->id] ?? null;
        if ($job === null) {
            return $silent >= (int) config('services.request_guardian.abandoned_after_seconds', 180)
                ? $this->rescueAbandoned($update) : null;
        }
        // Released for another attempt: queue:health-check owns it until a worker takes it.
        if ($job['reserved_at'] === null) {
            return null;
        }
        // The operator's own time budget is the one hard limit: two minutes before
        // it, the operator gets what exists. Live #598 and #611 kept searching for
        // photos into the 33rd minute through continuation slices.
        if ($this->elapsedSeconds($update) > $this->settings->searchMaxSeconds() - 120) {
            return $this->handleOvertime($update);
        }
        // The watcher told the search to wrap up. If it is still running minutes
        // later, the watcher decides again whether to show what exists now or let
        // the card finish: live #650 was cut while its card was being assembled.
        $wrapUp = (int) Cache::get('request-guard:wrap-up:'.$update->id, 0);
        if ($wrapUp > 0 && now()->timestamp - $wrapUp > 300) {
            return Cache::add('request-guard:review:'.$update->id, true, now()->addSeconds($this->reviewInterval()))
                ? $this->wakeTheWatcher($update, $silent, ['wrap_up_requested_minutes_ago' => intdiv(now()->timestamp - $wrapUp, 60)])
                : null;
        }
        if ($wrapUp > 0 || (int) Cache::get('request-guard:wait:'.$update->id, 0) > now()->timestamp
            // A search reaching its own checkpoints already calls the watcher itself.
            || ProductSourceAttempt::query()->where('telegram_update_id', $update->id)->where('phase', 'search_supervision')
                ->where('created_at', '>', now()->subSeconds($this->reviewInterval()))->exists()
            || ! Cache::add('request-guard:review:'.$update->id, true, now()->addSeconds($this->reviewInterval()))) {
            return null;
        }

        return $this->wakeTheWatcher($update, $silent);
    }

    private function reviewInterval(): int
    {
        return max(60, (int) config('services.request_guardian.review_interval_seconds', 180));
    }

    /** The watcher reads the journal and decides; the guardian only carries the decision out. */
    private function wakeTheWatcher(TelegramUpdate $update, int $silent, array $extra = []): ?string
    {
        // The watcher's journal starts with the draft; before it, only the budget applies.
        $draft = $this->draftFor($update);
        if ($draft === null) {
            return null;
        }
        $verdict = $this->supervisor->review($update->id, $draft, [
            'kind' => 'outside_check', 'minutes_since_last_step' => intdiv($silent, 60),
            'operator_already_told' => Cache::has('request-guard:note:'.$update->id), ...$extra,
        ], fromOutside: true);

        if ($verdict['decision'] === 'show_now' && $this->claim('deliver', $update)) {
            $this->showFound($update, '⏳ '.($verdict['reason'] ?: 'Поиск остановлен.'));

            return 'watcher_shown_found';
        }
        if ($verdict['decision'] === 'deliver_now') {
            // review() keeps the verdict standing: at its next step the search ends
            // the photo search and assembles the card itself. Cutting it here showed
            // live #621 an unassembled card.
            Cache::put('request-guard:wrap-up:'.$update->id, now()->timestamp, now()->addHour());
            $this->record($update, $draft, 'wrap_up', 'completed', 'Наблюдатель велел поиску свернуться и собрать карточку из найденного.',
                ['reason' => $verdict['reason']]);

            return 'watcher_wrap_up';
        }
        $note = trim((string) ($verdict['operator_note'] ?? ''));
        if ($note === '' || ! Cache::add('request-guard:note:'.$update->id, true, now()->addMinutes(10))) {
            return null;
        }
        $buttons = [[['text' => '⏳ Ждать дальше', 'callback_data' => "guard:wait:{$update->id}"],
            ['text' => '✖ Отменить поиск', 'callback_data' => "search:cancel:{$update->id}"]]];
        if ($draft->verifiedPhotoPaths() !== [] || ! $draft->reconciliationPending()) {
            array_unshift($buttons, [['text' => '📦 Показать найденное', 'callback_data' => "guard:show:{$update->id}"]]);
        }
        $this->telegram->sendMessage((string) $update->chat_id, '⏳ '.$note, ['inline_keyboard' => $buttons]);
        $this->record($update, $draft, 'watcher_note', 'completed', $note, ['silent_minutes' => intdiv($silent, 60)]);

        return 'watcher_note';
    }

    private function handleOvertime(TelegramUpdate $update): ?string
    {
        $minutes = intdiv($this->elapsedSeconds($update), 60);
        // Even a card without photos is an answer with a "continue" button; silence is not.
        if ($this->draftFor($update) !== null && $this->claim('deliver', $update)) {
            $this->showFound($update, "⏳ Поиск подходит к лимиту времени ({$minutes} мин). Показываю, что уже найдено.");

            return 'overtime_shown_found';
        }
        if ($this->draftFor($update) !== null || ! $this->claim('overtime_notice', $update)) {
            return null;
        }
        $this->telegram->sendMessage((string) $update->chat_id,
            "⏳ Поиск идёт уже {$minutes} мин, а карточка товара ещё не найдена. Можно подождать или отменить.",
            ['inline_keyboard' => [[['text' => '✖ Отменить поиск', 'callback_data' => "search:cancel:{$update->id}"]]]]);
        $this->record($update, null, 'overtime_notice', 'completed', "Поиск идёт {$minutes} мин без карточки; оператор предупреждён.", ['elapsed_minutes' => $minutes]);

        return 'overtime_notice';
    }

    /** The process is gone: no job holds the request and nothing has moved for minutes. */
    private function rescueAbandoned(TelegramUpdate $update): ?string
    {
        if (! $this->claim('abandoned', $update)) {
            return null;
        }
        // A reply already prepared is delivered, never searched for again.
        $checkpoint = AiOperation::query()->where('telegram_update_id', $update->id)->where('action', 'deliver_assistant_reply')
            ->whereNotIn('status', ['completed', 'cancelled'])->latest('id')->first();
        if ($checkpoint !== null) {
            DeliverTelegramAssistantReply::dispatch($checkpoint->id);
            $this->record($update, null, 'redeliver_abandoned_reply', 'completed', 'Процесс исчез после подготовки ответа; ответ доставляется заново.', ['checkpoint_id' => $checkpoint->id]);

            return 'redeliver_abandoned_reply';
        }
        if ($this->draftFor($update) !== null) {
            $this->showFound($update, '⚠️ Обработка поиска прервалась. Показываю, что уже найдено.');

            return 'abandoned_shown_found';
        }
        TelegramUpdate::query()->whereKey($update->id)->update(['status' => 'failed', 'processed_at' => now(),
            'error' => 'Процесс обработки исчез без ответа (request guardian).']);
        AiRun::query()->where('telegram_update_id', $update->id)->where('status', 'running')
            ->update(['status' => 'failed', 'error' => 'Процесс обработки исчез без ответа.', 'completed_at' => now()]);
        $this->telegram->sendMessage((string) $update->chat_id, '⚠️ Обработка запроса прервалась и не завершилась. Отправьте его ещё раз.');
        $this->record($update, null, 'abandoned_notice', 'completed', 'Процесс исчез без ответа и без черновика; оператор попрошен повторить.', []);

        return 'abandoned_notice';
    }

    /** @return list<string> */
    private function redeliverStuckReplies(): array
    {
        $done = [];
        AiOperation::query()->where('action', 'deliver_assistant_reply')->whereIn('status', ['failed', 'running', 'pending'])
            ->where('updated_at', '<', now()->subMinutes(5))->where('created_at', '>', now()->subHour())
            ->orderBy('id')->each(function (AiOperation $checkpoint) use (&$done): void {
                if (! Cache::add('request-guard:redeliver:'.$checkpoint->id, true, now()->addMinutes(5))) {
                    return;
                }
                DeliverTelegramAssistantReply::dispatch($checkpoint->id);
                $done[] = "checkpoint {$checkpoint->id} redeliver";
            });

        return $done;
    }

    /**
     * Jobs by the request they serve, and when each busy queue started what it runs now.
     *
     * @return array{0: array<int, array{queue: string, reserved_at: ?int, created_at: int}>, 1: array<string, int>}
     */
    private function queueState(): array
    {
        $jobs = [];
        $busy = [];
        foreach (DB::table('jobs')->orderBy('id')->get(['queue', 'payload', 'reserved_at', 'created_at']) as $row) {
            $queue = (string) $row->queue;
            if ($row->reserved_at !== null) {
                $busy[$queue] = min($busy[$queue] ?? PHP_INT_MAX, (int) $row->reserved_at);
            }
            // Read from the serialized command, never unserialized: written by another process.
            $command = (string) data_get(json_decode((string) $row->payload, true), 'data.command');
            preg_match_all('/"(?:telegramUpdateId|updateId)";i:(\d+);/', $command, $matches);
            foreach ($matches[1] as $id) {
                $current = $jobs[(int) $id] ?? null;
                if ($current === null || ($current['reserved_at'] === null && $row->reserved_at !== null)) {
                    $jobs[(int) $id] = ['queue' => $queue, 'reserved_at' => $row->reserved_at === null ? null : (int) $row->reserved_at,
                        'created_at' => (int) $row->created_at];
                }
            }
        }

        return [$jobs, $busy];
    }

    private function silentSeconds(TelegramUpdate $update): int
    {
        $marks = array_filter([
            $update->updated_at?->getTimestamp(),
            $this->timestamp(ProductSourceAttempt::query()->where('telegram_update_id', $update->id)
                ->whereNotIn('phase', self::OWN_PHASES)->max('created_at')),
            $this->timestamp(AiRun::query()->where('telegram_update_id', $update->id)
                ->where(fn ($query) => $query->whereNull('activity->stage')->orWhereNotIn('activity->stage', self::OWN_STAGES))
                ->max('updated_at')),
        ]);

        return $marks === [] ? 0 : max(0, now()->timestamp - max($marks));
    }

    private function elapsedSeconds(TelegramUpdate $update): int
    {
        $started = $this->timestamp(AiRun::query()->where('telegram_update_id', $update->id)->min('created_at'))
            ?? $update->created_at?->getTimestamp() ?? now()->timestamp;

        return max(0, now()->timestamp - $started);
    }

    private function timestamp(mixed $value): ?int
    {
        return $value === null ? null : (strtotime((string) $value) ?: null);
    }

    /** Once per request and kind, even with several schedulers running. */
    private function claim(string $kind, TelegramUpdate $update): bool
    {
        return Cache::add($this->key($kind, $update), now()->toIso8601String(), now()->addHours(6));
    }

    private function key(string $kind, TelegramUpdate $update): string
    {
        return "request-guard:{$kind}:{$update->id}";
    }

    private function record(TelegramUpdate $update, ?ProductDraft $draft, string $action, string $status, string $message, array $output): void
    {
        $this->attempts->record([
            'telegram_update_id' => $update->id, 'product_draft_id' => $draft?->id,
            'product_url' => (string) ($draft?->primary_source_url ?? ''), 'actor' => 'server',
            'phase' => 'guardian', 'action' => $action, 'status' => $status, 'decision' => $action,
            'message' => mb_substr($message, 0, 1000), 'output' => $output,
        ]);
    }
}
