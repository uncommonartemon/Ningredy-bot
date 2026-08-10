<?php

namespace App\Jobs;

use App\Ai\Agents\ServerAssistantAgent;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramChatState;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageReporter;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProcessTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // Must stay comfortably above AiSettings::searchMaxSeconds() (the whole
    // research+gallery+images+vision budget, 1800s by default) plus room to
    // persist the draft and reply to Telegram - otherwise the queue worker
    // kills the job mid-flight before that internal budget/reserve logic
    // ever gets a chance to wrap up gracefully.
    public int $timeout = 2100;

    public array $backoff = [30, 180];

    public function __construct(public int $telegramUpdateId)
    {
        $this->onQueue('assistant');
    }

    public function middleware(): array
    {
        // expireAfter must stay >= $timeout - a shorter lock can expire while
        // the job is still legitimately running, letting a duplicate job
        // start processing the same update concurrently.
        return [(new WithoutOverlapping('telegram-update:'.$this->telegramUpdateId))->releaseAfter(30)->expireAfter(2160)];
    }

    public function handle(TelegramClient $telegram, AiErrorPresenter $errors, ?ProductCardPresenter $productCards = null): void
    {
        $productCards ??= app(ProductCardPresenter::class);
        $update = TelegramUpdate::query()->findOrFail($this->telegramUpdateId);

        if ($update->processed_at) {
            return;
        }

        $aiSettings = app(AiSettings::class);
        $provider = $aiSettings->providerFor('server_assistant');
        $model = $aiSettings->modelFor('server_assistant');
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $update->text,
            'started_at' => now(),
        ]);
        $update->update(['status' => 'processing', 'error' => null]);

        try {
            $agent = ServerAssistantAgent::make($update);
            $state = TelegramChatState::query()->firstOrCreate(
                ['chat_id' => $update->chat_id],
                ['telegram_user_id' => $update->telegram_user_id],
            );
            $user = User::query()->first();
            $bootId = (string) config('app.boot_id');

            // A conversation continued from before this worker process last
            // (re)started carries the model's memory of everything that
            // happened in that prior run - including drafts/state that may
            // no longer be relevant (see ProcessTelegramMessage's draft_id
            // handling below). Starting the conversation fresh on every new
            // server boot avoids resurfacing stale context into an otherwise
            // unrelated new message.
            if ($bootId !== '' && $state->boot_id !== $bootId) {
                $state->update(['conversation_id' => null, 'boot_id' => $bootId]);
            }

            if ($user && $state->conversation_id) {
                $agent->continue($state->conversation_id, as: $user);
            } elseif ($user) {
                $agent->forUser($user);
            }

            $prompt = $update->reply_to_text
                ? "[Пользователь ответил (Reply) на сообщение бота: \"{$update->reply_to_text}\"]\n{$update->text}"
                : (string) $update->text;
            $response = $agent->prompt($prompt, provider: $provider, model: $model, timeout: 1440);
            $normalizedResponse = $response->toArray();

            if (is_string($normalizedResponse['message'] ?? null)) {
                $normalizedResponse['message'] = mb_substr($normalizedResponse['message'], 0, 12000);
            }

            foreach (['product_ids', 'operation_ids'] as $idField) {
                $normalizedResponse[$idField] = collect($normalizedResponse[$idField] ?? [])
                    ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                    ->map(fn (int|string $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->take(100)
                    ->values()
                    ->all();
            }

            $data = Validator::make($normalizedResponse, [
                'response_type' => ['required', 'string'],
                'message' => ['required', 'string', 'max:12000'],
                'draft_id' => ['nullable', 'integer'],
                'product_ids' => ['present', 'array', 'max:100'],
                'product_ids.*' => ['integer', 'distinct'],
                'operation_ids' => ['present', 'array', 'max:100'],
                'operation_ids.*' => ['integer', 'distinct'],
            ])->validate();

            if ($response->conversationId) {
                $state->update([
                    'telegram_user_id' => $update->telegram_user_id,
                    'conversation_id' => $response->conversationId,
                ]);
            }

            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $data,
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);
            $update->update(['status' => 'completed', 'processed_at' => now()]);

            // The AI can echo a draft_id from earlier in the same live
            // conversation (its own memory of "the current draft", not a
            // fresh tool result) even after that draft was already approved
            // or rejected in a prior turn. Without this check the bot
            // re-shows a finalized draft as "ready to add" with whatever
            // media happens to remain on it (often none - approval moves
            // photos onto the published product).
            $draft = empty($data['draft_id']) ? null : ProductDraft::query()->find($data['draft_id']);
            $draft = $draft?->status === 'pending_review' ? $draft : null;
            $failedGalleryDraft = ProductDraft::query()
                ->where('telegram_update_id', $update->id)
                ->where('status', 'rejected')
                ->where('rejection_reason', 'like', '%галере%')
                ->latest('id')
                ->first();
            $deletion = AiOperation::query()
                ->where('telegram_update_id', $update->id)
                ->where('action', 'delete_product')
                ->where('status', 'awaiting_confirmation')
                ->latest('id')
                ->first();

            $usageFootnote = $this->usageFootnote($update->id);

            if ($draft) {
                $this->sendDraftApproval($telegram, $update->chat_id, $draft, $usageFootnote);
            } elseif ($deletion) {
                $productTitle = (string) data_get($deletion->payload, 'title', 'Товар');
                $productId = (int) $deletion->target_id;
                $telegram->sendMessage(
                    $update->chat_id,
                    "Подтвердите безвозвратное удаление:\n\n#{$productId} · {$productTitle}\n\nБудут удалены товар, варианты, характеристики и локальные фотографии.".$usageFootnote,
                    [
                        'inline_keyboard' => [[
                            [
                                'text' => 'Удалить навсегда',
                                'callback_data' => "product:delete:confirm:{$deletion->id}",
                            ],
                            [
                                'text' => 'Отмена',
                                'callback_data' => "product:delete:cancel:{$deletion->id}",
                            ],
                        ]],
                    ],
                );
            } elseif ($failedGalleryDraft) {
                $telegram->sendMessage(
                    $update->chat_id,
                    "❌ Не удалось собрать проверенную галерею для «{$failedGalleryDraft->title}». Все найденные карточки и резервный поиск уже проверены; черновик без фото не создаю.".$usageFootnote,
                );
            } elseif ($data['response_type'] === 'catalog_results' && ! empty($data['product_ids'])) {
                $telegram->sendMessage($update->chat_id, $data['message'].$usageFootnote);
                $productCards->sendMany($telegram, $update->chat_id, $data['product_ids']);
            } else {
                $telegram->sendMessage($update->chat_id, $data['message'].$usageFootnote);
            }
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            $update->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 5000)]);
            $presented = $errors->present($exception, $run->id);
            $retriesExhausted = $this->attempts() >= $this->tries;

            // When the provider says exactly when the limit resets ("Please
            // try again in 20s"), wait precisely that instead of the blind
            // fixed backoff: firing earlier just buys another guaranteed 429
            // (and burns another full search), waiting longer is dead time.
            $retryAfter = $presented['retryable'] && ! $retriesExhausted
                ? $errors->retryAfterSeconds($exception)
                : null;

            if ($presented['retryable'] && ! $retriesExhausted) {
                // Transient provider failures stay internal. The same user
                // request is retried; Telegram receives no separate error.
                if ($retryAfter !== null && $this->job !== null) {
                    $this->release($retryAfter);

                    return;
                }

                throw $exception;
            }

            // Only a non-retryable error or the exhausted final attempt is
            // user-facing. A first retry remains an internal queue detail;
            // the existing progress message remains visible while the same
            // Telegram update is retried. On the last allowed attempt the
            // error is sent once with final wording;
            // it never promises another automatic attempt
            // after the retry budget is exhausted.
            $message = $presented['retryable'] && $retriesExhausted
                ? str_replace(
                    ['Повторю запрос автоматически.', 'Повторю автоматически.'],
                    'Автоматические попытки исчерпаны — повторите запрос вручную.',
                    $presented['message'],
                )
                : $presented['message'];

            try {
                $telegram->sendMessage($update->chat_id, $message);
            } catch (Throwable $notifyException) {
                report($notifyException);
            }

            if ($presented['retryable']) {
                $update->update(['processed_at' => now()]);

                throw $exception;
            }

            $update->update(['processed_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        if (! $update?->chat_id || $update->processed_at) {
            return;
        }

        $run = $update->aiRuns()->latest('id')->first();

        try {
            $presented = app(AiErrorPresenter::class)->present($exception ?: $run?->error, $run?->id);
            $message = $presented['retryable']
                ? str_replace(
                    ['Повторю запрос автоматически.', 'Повторю автоматически.'],
                    'Автоматические попытки исчерпаны — повторите запрос вручную.',
                    $presented['message'],
                )
                : $presented['message'];
            app(TelegramClient::class)->sendMessage($update->chat_id, $message);
            $update->update(['processed_at' => now()]);
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
    }

    private function usageFootnote(int $telegramUpdateId): string
    {
        $usage = app(AiUsageReporter::class)->forTelegramUpdate($telegramUpdateId);
        $tokens = (int) ($usage['tokens']['total'] ?? 0);

        if ($tokens <= 0) {
            return '';
        }

        $line = "\n\n🔢 Примерно токенов потрачено: ".number_format($tokens, 0, '.', ' ');

        if ($usage['estimated_cost_usd'] !== null) {
            $line .= sprintf(' (~$%s)', number_format((float) $usage['estimated_cost_usd'], 4));
        }

        if (($usage['usage_unknown_failures'] ?? 0) > 0) {
            $line .= sprintf(
                ' · %d попытка(и) без usage: итоговая стоимость может быть выше',
                $usage['usage_unknown_failures'],
            );
        }

        return $line;
    }

    private function sendDraftApproval(
        TelegramClient $telegram,
        string $chatId,
        ProductDraft $draft,
        string $usageFootnote,
    ): void {
        app(DraftTelegramPresenter::class)->sendReview($telegram, $chatId, $draft, $usageFootnote);
    }
}
