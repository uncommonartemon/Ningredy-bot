<?php

namespace App\Services\ProductSearch\Recovery;

use App\Jobs\DeliverTelegramAssistantReply;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\ProductSearch\SearchJournal;
use App\Services\ProductSearch\Supervision\SearchProgressSupervisor;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Looks at the operator's open requests from outside the search process, once a
 * minute. The watcher, the failure saver and the card repair all run inside the
 * search and are called by it between steps: when one step hangs, nobody calls
 * them, and a request waiting behind a long search is invisible to all of them.
 *
 * Judgement belongs to the watcher agent (operator rule, 2026-10-21). It reads
 * the search's whole story itself; the guardian wakes it on its minute - when
 * the search did something new since the last look, and every few minutes while
 * it is silent - and adds what the journal cannot show: how long since the last
 * step and which page was in work. The agent lets the search run, ends a page or
 * a site, has it wrap up into a card, or shows what exists right now, and decides
 * whether the operator hears anything. The app keeps only the operator's own
 * time budget (two minutes before the limit the operator gets what exists) and
 * facts that need no judgement: a request queued behind another search, a
 * vanished process (its search goes on from its saved draft), an undelivered reply.
 *
 * Nothing here raises a budget or starts a paid search, and no message carries
 * a technical error. Every action is recorded in the search journal (phase
 * guardian) and taken at most once per request.
 */
final class RequestGuardian
{
    private const OPEN_STATUSES = ['received', 'transcribed', 'processing', 'delivering'];

    /** Rows the guardian and the watcher write themselves never prove a search alive. */
    private const OWN_PHASES = SearchJournal::OWN_PHASES;

    private const OWN_STAGES = ['search_progress_supervisor'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly SearchProgressSupervisor $supervisor,
        private readonly DraftTelegramPresenter $presenter,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly AiSettings $settings,
        private readonly SearchFailureSaver $saver,
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
        // Where the search looked and what it got there, from the journal: a card
        // without photos names its reasons, so the operator can give a link or let go.
        $looked = SearchJournal::whereWeLooked($update->id, $draft);
        $this->telegram->sendMessage($chat, $intro.' '.match (true) {
            $photos === 0 => 'Фото не найдены, характеристики сохранены. Продолжить поиск фото можно кнопкой под карточкой.',
            $draft->reconciliationPending() => "Сохранено фото: {$photos}, характеристики не сверены. Можно принять как есть, продолжить поиск или исправить.",
            default => "Сохранено фото: {$photos}. Можно добавить в каталог, продолжить поиск или исправить.",
        }.($looked === [] ? '' : "\n\nГде искали:\n• ".implode("\n• ", $looked)));
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
        // A worker holds its job for as long as it lives, and a live worker started
        // before the job it took. A reservation older than every running worker of
        // that queue belongs to one that is gone: live #11 sat silent for twenty
        // minutes after the bot was restarted under its research step.
        if ($silent >= (int) config('services.request_guardian.abandoned_after_seconds', 180)
            && $this->reservationOutlivedItsWorker($job)) {
            return $this->recoverOrphanedJob($update, $job);
        }
        // A step that is not coming back: no journal row and no AI call has moved for
        // this long. Live #176 sat 14 minutes and #177 42 minutes inside one call with
        // no look recorded, until the operator restarted the bot. This floor is a rule,
        // not the watcher's judgement, so it holds whatever else is stuck.
        $journalSilent = $this->journalSilentSeconds($update);
        Log::info('Request guardian look.', ['update' => $update->id, 'silent' => $silent, 'journal_silent' => $journalSilent,
            'elapsed' => $this->elapsedSeconds($update), 'job_reserved' => $job['reserved_at'] !== null]);
        if ($journalSilent >= (int) config('services.request_guardian.hang_after_seconds', 600)
            && $this->draftFor($update) !== null && $this->claim('deliver', $update)) {
            $minutes = intdiv($journalSilent, 60);
            $this->showFound($update, "⚠️ Шаг поиска не отвечает {$minutes} мин. Показываю, что уже найдено.");
            // On Windows a job has no timeout: the stuck worker holds its queue until the bot is restarted.
            $this->telegram->sendMessage((string) $update->chat_id,
                "⚠️ Процесс поиска завис на запросе #{$update->id}: ни одного шага за {$minutes} мин. Чтобы бот принимал новые запросы, перезапустите его.");
            $this->record($update, $this->draftFor($update), 'hang_shown_found', 'completed',
                "Шаг поиска не отвечает {$minutes} мин; найденное показано, оператор попрошен перезапустить бота.", ['journal_silent_seconds' => $journalSilent]);

            return 'hang_shown_found';
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
            return $this->wakeTheWatcher($update, $silent, ['wrap_up_requested_minutes_ago' => intdiv(now()->timestamp - $wrapUp, 60)]);
        }
        if ($wrapUp > 0 || (int) Cache::get('request-guard:wait:'.$update->id, 0) > now()->timestamp) {
            return null;
        }

        // Whether a look is due - something new since the last one, or minutes of
        // silence - the watcher's own pace decides, shared with the search's checkpoints.
        return $this->wakeTheWatcher($update, $silent);
    }

    /** The watcher reads the journal and decides; the guardian only carries the decision out. */
    private function wakeTheWatcher(TelegramUpdate $update, int $silent, array $extra = []): ?string
    {
        // The watcher's journal starts with the draft; before it, only the budget applies.
        $draft = $this->draftFor($update);
        if ($draft === null) {
            return null;
        }
        $current = ['kind' => 'outside_check', 'minutes_since_last_step' => intdiv($silent, 60),
            'operator_already_told' => Cache::has('request-guard:note:'.$update->id), ...$extra];
        // The page the search last worked on: a "leave" from here is for that page alone.
        $page = ProductSourceAttempt::query()->where('telegram_update_id', $update->id)->whereNotIn('phase', self::OWN_PHASES)
            ->where('product_url', '!=', '')->latest('id')->value('product_url');
        if (is_string($page) && $page !== '') {
            $current += ['url' => $page, 'host' => strtolower((string) parse_url($page, PHP_URL_HOST))];
        }
        $verdict = $this->supervisor->review($update->id, $draft, $current, fromOutside: true);

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
        // The operator sees one thing: the card. A note the watcher would send them
        // ("the operator may prefer what exists") is the decision itself: the search
        // wraps up and the card says where it looked (operator, 2026-10-25: no
        // messages or questions during a search).
        $note = trim((string) ($verdict['operator_note'] ?? ''));
        if ($note === '' || ! Cache::add('request-guard:note:'.$update->id, true, now()->addMinutes(10))) {
            return null;
        }
        if ($draft !== null && ($premature = $this->supervisor->tooEarlyToEnd($update->id, $draft)) !== null) {
            $this->record($update, $draft, 'note_too_early', 'completed', 'Наблюдатель хотел свернуть поиск, но рано: '.$premature.'.', ['reason' => $note]);

            return null;
        }
        $this->supervisor->requestWrapUp($update->id, $note);
        Cache::put('request-guard:wrap-up:'.$update->id, now()->timestamp, now()->addHour());
        $this->record($update, $draft, 'wrap_up', 'completed',
            'Наблюдатель хотел спросить оператора — вместо вопроса поиск сворачивается и собирает карточку из найденного.', ['reason' => $note]);

        return 'watcher_wrap_up';
    }

    private function handleOvertime(TelegramUpdate $update): ?string
    {
        $elapsed = $this->elapsedSeconds($update);
        $minutes = intdiv($elapsed, 60);
        $draft = $this->draftFor($update);
        // Two minutes before the limit the photo search ends, but the card is still
        // assembled from what exists: assembly has its own overrun after the limit
        // (PROJECT_STRATEGY: assembly is not bounded by the search limits). Cutting
        // here instead gave live #151 a cut mid-assembly and #161 an unassembled
        // card after a page opened at the 28th minute. Only past the assembly's own
        // deadline is what exists shown as it is.
        if ($draft !== null && $elapsed <= $this->settings->searchMaxSeconds() + ProductSearchTimeBudget::ASSEMBLY_OVERRUN_SECONDS) {
            if (! $this->claim('overtime_wrap_up', $update)) {
                return null;
            }
            $reason = "Время поиска фото вышло ({$minutes} мин); карточка собирается из найденного.";
            $this->supervisor->requestWrapUp($update->id, $reason);
            Cache::put('request-guard:wrap-up:'.$update->id, now()->timestamp, now()->addHour());
            $this->record($update, $draft, 'overtime_wrap_up', 'completed', $reason, ['elapsed_minutes' => $minutes]);

            return 'overtime_wrap_up';
        }
        // Even a card without photos is an answer with a "continue" button; silence is not.
        if ($draft !== null && $this->claim('deliver', $update)) {
            $this->showFound($update, "⏳ Лимит времени поиска ({$minutes} мин). Показываю, что уже найдено.");

            return 'overtime_shown_found';
        }
        if ($draft !== null || ! $this->claim('overtime_notice', $update)) {
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
        if (($draft = $this->draftFor($update)) !== null) {
            // With time and money left the search goes on from where it stopped,
            // once per draft; otherwise the operator gets what it found.
            if ((int) $draft->telegram_update_id === $update->id
                && $this->saver->resume($update, $draft, ['kind' => 'worker_stopped'])) {
                AiRun::query()->where('telegram_update_id', $update->id)->where('status', 'running')
                    ->update(['status' => 'failed', 'error' => 'Процесс обработки исчез без ответа.', 'completed_at' => now()]);
                $this->record($update, $draft, 'resume_abandoned', 'completed', 'Процесс исчез с сохранённым черновиком; поиск продолжен с места остановки.', []);

                return 'abandoned_resumed';
            }
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

    /** No answer about the workers is no evidence: then nothing is done here. */
    private function reservationOutlivedItsWorker(array $job): bool
    {
        $result = Process::timeout(20)->run(['powershell.exe', '-NoProfile', '-Command',
            "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { \$_.CommandLine -match 'artisan\\s+queue:work' } | "
            .'ForEach-Object { ([DateTimeOffset]$_.CreationDate).ToUnixTimeSeconds().ToString() + \' \' + $_.CommandLine }']);
        if (! $result->successful()) {
            return false;
        }
        $starts = [];
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if (preg_match('/^(\d+) .*--queue=(\S+)/', trim($line), $match)
                && in_array($job['queue'], explode(',', trim($match[2], '"\'')), true)) {
                $starts[] = (int) $match[1];
            }
        }

        return $starts !== [] && min($starts) > $job['reserved_at'];
    }

    /**
     * The request goes back to the queue while it has attempts left - the
     * operator need not send it again; otherwise it is ended the way a vanished
     * process is. Its old job is never left to replay on its own later.
     */
    private function recoverOrphanedJob(TelegramUpdate $update, array $job): ?string
    {
        if (! $this->claim('orphaned:'.$job['id'], $update)) {
            return null;
        }
        AiRun::query()->where('telegram_update_id', $update->id)->where('status', 'running')
            ->update(['status' => 'failed', 'error' => 'Обработчик остановился во время работы.', 'completed_at' => now()]);
        if ($job['attempts'] < $job['max_tries']) {
            // The dead attempt's overlap lock outlives it, and the message job drops
            // a delivery that finds the lock taken (live #11's retry vanished so).
            if ($job['class'] === ProcessTelegramMessage::class) {
                $stand = new ProcessTelegramMessage($update->id);
                foreach ($stand->middleware() as $middleware) {
                    if ($middleware instanceof WithoutOverlapping) {
                        Cache::lock($middleware->getLockKey($stand))->forceRelease();
                    }
                }
            }
            DB::table('jobs')->where('id', $job['id'])->update(['reserved_at' => null, 'available_at' => now()->timestamp]);
            // A request with a saved draft goes on from it, and says so itself when it does.
            $saved = $job['class'] === ProcessTelegramMessage::class
                && ProductDraft::query()->where('telegram_update_id', $update->id)->where('status', 'pending_review')->exists();
            if (! $saved) {
                $this->telegram->sendMessage((string) $update->chat_id,
                    '⚠️ Обработка запроса прервалась: обработчик остановился. Запускаю запрос заново — отправлять его ещё раз не нужно.');
            }
            $this->record($update, null, 'retry_orphaned_job', 'completed', $saved
                ? 'Задачу держал остановленный обработчик; она возвращена в очередь и продолжит поиск с сохранённого черновика.'
                : 'Задачу держал остановленный обработчик; она возвращена в очередь.', $job);

            return 'retry_orphaned_job';
        }
        DB::table('jobs')->where('id', $job['id'])->delete();

        return $this->rescueAbandoned($update);
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
        foreach (DB::table('jobs')->orderBy('id')->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'created_at']) as $row) {
            $queue = (string) $row->queue;
            if ($row->reserved_at !== null) {
                $busy[$queue] = min($busy[$queue] ?? PHP_INT_MAX, (int) $row->reserved_at);
            }
            // Read from the serialized command, never unserialized: written by another process.
            $payload = json_decode((string) $row->payload, true);
            $command = (string) data_get($payload, 'data.command');
            preg_match_all('/"(?:telegramUpdateId|updateId)";i:(\d+);/', $command, $matches);
            foreach ($matches[1] as $id) {
                $current = $jobs[(int) $id] ?? null;
                if ($current === null || ($current['reserved_at'] === null && $row->reserved_at !== null)) {
                    $jobs[(int) $id] = ['id' => (int) $row->id, 'queue' => $queue,
                        'reserved_at' => $row->reserved_at === null ? null : (int) $row->reserved_at,
                        'created_at' => (int) $row->created_at, 'attempts' => (int) $row->attempts,
                        'max_tries' => (int) data_get($payload, 'maxTries', 1), 'class' => (string) data_get($payload, 'displayName')];
                }
            }
        }

        return [$jobs, $busy];
    }

    /** Seconds since the search itself last wrote a journal row or finished an AI call; the request row's own touches do not count. */
    private function journalSilentSeconds(TelegramUpdate $update): int
    {
        $marks = array_filter([
            $this->timestamp(ProductSourceAttempt::query()->where('telegram_update_id', $update->id)
                ->whereNotIn('phase', self::OWN_PHASES)->max('created_at')),
            $this->timestamp(AiRun::query()->where('telegram_update_id', $update->id)
                ->where(fn ($query) => $query->whereNull('activity->stage')->orWhereNotIn('activity->stage', self::OWN_STAGES))
                ->max('updated_at')),
            $update->created_at?->getTimestamp(),
        ]);

        return max(0, now()->timestamp - max($marks));
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
