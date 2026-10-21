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
 * search: when one step hangs they hang with it, and a second request waiting
 * behind a long search is invisible to all of them. The guardian is told nothing
 * by the search; it reads what the search left behind.
 *
 * - a request waiting behind another search gets one "queued" notice;
 * - a step with no progress for a while is judged by the same watcher agent,
 *   which may show what is already found; otherwise the operator hears that the
 *   search is slow, with buttons, and a long stall shows the saved draft anyway;
 * - a request whose process vanished gets its prepared reply or saved draft, or
 *   a plain "send it again";
 * - a reply that could not be delivered is delivered again later.
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

    private const STEP_LABELS = [
        'research' => 'поиск карточки товара',
        'research_repair' => 'поиск карточки товара',
        'image_discovery' => 'поиск страниц с фото',
        'gallery_preflight' => 'проверка страницы магазина',
        'gallery_training' => 'обучение сбору галереи на сайте магазина',
        'active_recipe' => 'сбор галереи на сайте магазина',
        'source_resolution' => 'чтение страницы магазина',
        'source_selection' => 'выбор источника',
        'image_download' => 'загрузка фото',
        'fallback_image_download' => 'загрузка фото',
        'image_verification' => 'проверка фото',
        'vision' => 'проверка фото',
        'photo_applicability' => 'проверка фото',
        'source_identity' => 'проверка, тот ли это товар',
        'card_assembly' => 'сборка характеристик',
        'card_repair' => 'исправление характеристик',
        'card_recovery' => 'исправление характеристик',
        'card_recovery_supervisor' => 'исправление характеристик',
        'text_evidence' => 'поиск характеристик в тексте',
        'assistant' => 'подготовка ответа',
    ];

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
        $this->telegram->sendMessage($chat, $intro.' Можно добавить в каталог, продолжить поиск или исправить.');
        $this->presenter->sendReview($this->telegram, $chat, $draft->fresh('media'));
        TelegramUpdate::query()->whereKey($update->id)->update(['status' => 'completed', 'processed_at' => now()]);
        $this->record($update, $draft, 'show_found', 'completed', $intro, ['photos' => $draft->media()->count()]);

        return true;
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
        $overtime = $this->elapsedSeconds($update) > $this->settings->searchMaxSeconds() + 120;
        if (($silent < (int) config('services.request_guardian.stall_after_seconds', 360) && ! $overtime)
            || (int) Cache::get('request-guard:wait:'.$update->id, 0) > now()->timestamp) {
            return null;
        }

        return $this->handleStall($update, $silent, $overtime);
    }

    private function handleStall(TelegramUpdate $update, int $silent, bool $overtime): ?string
    {
        $draft = $this->draftFor($update);
        // Stopping a search early is worth it only for something to show.
        $showable = $draft !== null && ($draft->verifiedPhotoPaths() !== [] || ! $draft->reconciliationPending());
        $step = $this->currentStep($update);
        $minutes = max(1, intdiv($silent, 60));

        if (! Cache::has($this->key('stall_notice', $update))) {
            $verdict = $draft === null ? ['decision' => 'continue', 'reason' => null]
                : $this->supervisor->review($update->id, $draft, [
                    'kind' => 'stalled_step', 'step' => $step, 'silent_minutes' => $minutes,
                    'over_time_limit' => $overtime, 'found_result_can_be_shown' => $showable,
                ], fromOutside: true);
            if ($verdict['decision'] === 'deliver_now' && $showable && $this->claim('deliver', $update)) {
                $this->showFound($update, "⚠️ Поиск застрял на шаге «{$step}». Показываю, что уже найдено."
                    .(filled($verdict['reason']) ? "\n".$verdict['reason'] : ''));

                return 'stall_shown_found';
            }
            if (! $this->claim('stall_notice', $update)) {
                return null;
            }
            $buttons = [[['text' => '⏳ Ждать дальше', 'callback_data' => "guard:wait:{$update->id}"],
                ['text' => '✖ Отменить поиск', 'callback_data' => "search:cancel:{$update->id}"]]];
            if ($showable) {
                array_unshift($buttons, [['text' => '📦 Показать найденное', 'callback_data' => "guard:show:{$update->id}"]]);
            }
            $this->telegram->sendMessage((string) $update->chat_id,
                "⏳ Поиск идёт дольше обычного: шаг «{$step}» уже {$minutes} мин без продвижения."
                .($showable ? ' Найденное сохранено.' : ''), ['inline_keyboard' => $buttons]);
            $this->record($update, $draft, 'stall_notice', 'completed', "Шаг «{$step}» молчит {$minutes} мин; оператор предупреждён.",
                ['silent_minutes' => $minutes, 'over_time_limit' => $overtime, 'showable' => $showable, 'watcher' => $verdict]);

            return 'stall_notice';
        }

        if (($silent < (int) config('services.request_guardian.hard_stall_after_seconds', 720) && ! $overtime)
            || ! $this->claim('hard_stall', $update)) {
            return null;
        }
        if ($showable && $this->claim('deliver', $update)) {
            $this->showFound($update, "⚠️ Поиск завис на шаге «{$step}» ({$minutes} мин без продвижения). Показываю, что уже найдено.");

            return 'hard_stall_shown_found';
        }
        $this->telegram->sendMessage((string) $update->chat_id,
            "⚠️ Поиск, похоже, завис на шаге «{$step}» ({$minutes} мин без продвижения). Показать пока нечего. Можно отменить и отправить запрос заново.",
            ['inline_keyboard' => [[['text' => '✖ Отменить поиск', 'callback_data' => "search:cancel:{$update->id}"]]]]);
        $this->record($update, $draft, 'hard_stall_notice', 'completed', "Шаг «{$step}» молчит {$minutes} мин, показать нечего.", ['silent_minutes' => $minutes]);

        return 'hard_stall_notice';
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

    /** The step that is open now: a specific running call first, then the newest journal row. */
    private function currentStep(TelegramUpdate $update): string
    {
        $running = AiRun::query()->where('telegram_update_id', $update->id)->where('status', 'running')
            ->latest('id')->get(['activity'])->map(fn (AiRun $run): ?string => data_get($run->activity, 'stage'))
            ->first(fn (?string $stage): bool => $stage !== null && $stage !== 'assistant' && ! in_array($stage, self::OWN_STAGES, true));
        $stage = $running ?? ProductSourceAttempt::query()->where('telegram_update_id', $update->id)
            ->whereNotIn('phase', self::OWN_PHASES)->latest('id')->value('phase') ?? 'assistant';

        return self::STEP_LABELS[$stage] ?? 'обработка запроса';
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
