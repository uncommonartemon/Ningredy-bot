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
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProcessTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public array $backoff = [10, 60, 300];

    public function __construct(public int $telegramUpdateId)
    {
        $this->onQueue('assistant');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('telegram-update:'.$this->telegramUpdateId))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(TelegramClient $telegram, AiErrorPresenter $errors): void
    {
        $update = TelegramUpdate::query()->findOrFail($this->telegramUpdateId);
        $provider = (string) config('services.server_assistant.provider', 'openai');
        $model = (string) config('services.server_assistant.model', 'gpt-5.4');
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

            if ($user && $state->conversation_id) {
                $agent->continue($state->conversation_id, as: $user);
            } elseif ($user) {
                $agent->forUser($user);
            }

            $response = $agent->prompt((string) $update->text, provider: $provider, model: $model, timeout: 210);
            $data = Validator::make($response->toArray(), [
                'response_type' => ['required', 'string'],
                'message' => ['required', 'string', 'max:12000'],
                'draft_id' => ['nullable', 'integer'],
                'product_ids' => ['present', 'array'],
                'product_ids.*' => ['integer'],
                'operation_ids' => ['present', 'array'],
                'operation_ids.*' => ['integer'],
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

            $draft = empty($data['draft_id']) ? null : ProductDraft::query()->find($data['draft_id']);
            $deletion = AiOperation::query()
                ->where('telegram_update_id', $update->id)
                ->where('action', 'delete_product')
                ->where('status', 'awaiting_confirmation')
                ->latest('id')
                ->first();

            if ($draft) {
                $telegram->sendMessage($update->chat_id, $this->draftSummary($draft), [
                    'inline_keyboard' => [[
                        ['text' => '➕ Добавить товар в каталог', 'callback_data' => "draft:add:{$draft->id}"],
                        ['text' => '✖ Не добавлять', 'callback_data' => "draft:reject:{$draft->id}"],
                    ]],
                ]);
            } elseif ($deletion) {
                $productTitle = (string) data_get($deletion->payload, 'title', 'Товар');
                $productId = (int) $deletion->target_id;
                $telegram->sendMessage(
                    $update->chat_id,
                    "⚠️ Подтвердите безвозвратное удаление:\n\n#{$productId} · {$productTitle}\n\nБудут удалены товар, варианты, характеристики и локальные фотографии.",
                    [
                        'inline_keyboard' => [[
                            [
                                'text' => '🗑 Удалить навсегда',
                                'callback_data' => "product:delete:confirm:{$deletion->id}",
                            ],
                            [
                                'text' => 'Отмена',
                                'callback_data' => "product:delete:cancel:{$deletion->id}",
                            ],
                        ]],
                    ],
                );
            } else {
                $telegram->sendMessage($update->chat_id, $data['message']);
            }
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            $update->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 5000)]);
            $presented = $errors->present($exception, $run->id);

            if ($presented['retryable']) {
                if ($this->attempts() < $this->tries) {
                    $telegram->sendMessage($update->chat_id, $presented['message']);
                }

                throw $exception;
            }

            $telegram->sendMessage($update->chat_id, $presented['message']);
            $update->update(['processed_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        if (! $update?->chat_id || $update->processed_at) {
            return;
        }

        try {
            $run = $update->aiRuns()->latest('id')->first();
            $message = app(AiErrorPresenter::class)->present($exception ?: $run?->error, $run?->id)['message'];
            app(TelegramClient::class)->sendMessage($update->chat_id, $message);
            $update->update(['processed_at' => now()]);
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
    }

    private function draftSummary(ProductDraft $draft): string
    {
        $specifications = collect($draft->specifications)->take(12)
            ->map(fn (array $item): string => "• {$item['name']}: {$item['value']}")->implode("\n");
        $sources = collect($draft->sources)->take(5)
            ->map(fn (array $source): string => "• {$source['url']}")->implode("\n");

        return mb_substr(implode("\n\n", array_filter([
            "Найден товар — черновик #{$draft->id}",
            $draft->title,
            implode(' · ', array_filter([$draft->brand, $draft->model, $draft->color])),
            $draft->description,
            $specifications ? "Характеристики:\n{$specifications}" : null,
            $sources ? "Источники:\n{$sources}" : null,
            'Товар ещё не опубликован. Нажмите кнопку ниже, чтобы добавить его в каталог.',
        ])), 0, 4096);
    }
}
