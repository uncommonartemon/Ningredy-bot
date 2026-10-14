<?php

namespace App\Jobs;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProductUploadIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessTelegramProductUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 480;

    public array $backoff = [15, 60];

    public function __construct(public int $telegramUpdateId)
    {
        $this->onQueue('voice');
    }

    public function handle(TelegramProductUploadIntake $intake, TelegramClient $telegram,
        DraftTelegramPresenter $presenter, AiErrorPresenter $errors): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        if (! $update || $update->processed_at || $update->status === 'cancelled') {
            $intake->clearUploadMarkerIfIdle($update ? data_get($update->payload, '_manual_product_draft_id') : null);

            return;
        }
        $appendDraftId = data_get($update->payload, '_manual_product_draft_id');
        $lock = Cache::lock('telegram-product-upload:'.$intake->groupKey($update), 480);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }
        try {
            $updates = $intake->groupedUpdates($update);
            $appendDraftId = $updates->map(fn (TelegramUpdate $item) => data_get($item->payload, '_manual_product_draft_id'))
                ->filter()->first() ?? $appendDraftId;
            if ($appendDraftId) {
                Cache::put("draft-upload:{$appendDraftId}:queued", true, 540);
            }
            // Each album message is delivered separately; wait for a quiet window.
            $latest = $updates->max('created_at');
            if (count($updates) > 1 && $latest && $latest->greaterThan(now()->subSeconds(2))) {
                $this->release(3);

                return;
            }
            if ($updates->contains(fn (TelegramUpdate $item): bool => $item->processed_at !== null)) {
                $draft = ProductDraft::query()->whereIn('telegram_update_id', $updates->pluck('id'))->first();
                if ($draft) {
                    $appendDraftId = $draft->id;
                    $newMembers = $updates->filter(fn (TelegramUpdate $item): bool => $item->processed_at === null)->values();
                    if ($newMembers->isEmpty()) {
                        return;
                    }
                    if ($draft->status !== 'pending_review') {
                        foreach ($newMembers as $item) {
                            $item->update(['status' => 'ignored', 'processed_at' => now(),
                                'error' => 'Late album photo: draft already reviewed.']);
                        }
                        $telegram->sendMessage((string) $update->chat_id,
                            'Часть альбома пришла после обработки черновика. Отправьте поздние фото отдельной загрузкой.');

                        return;
                    }
                    foreach ($newMembers as $item) {
                        $payload = $item->payload ?? [];
                        $payload['_manual_product_draft_id'] = $draft->id;
                        $item->update(['payload' => $payload]);
                    }
                    Cache::put("draft-upload:{$appendDraftId}:queued", true, 540);
                    $updates = $newMembers;
                }
            }
            $draft = $intake->createFromUpdates($updates);
            if ($draft && $draft->status === 'pending_review') {
                $presenter->sendReview($telegram, (string) $update->chat_id, $draft);
            }
        } catch (Throwable $exception) {
            if ($update->refresh()->status === 'cancelled') {
                return;
            }
            $presented = $errors->present($exception);
            $update->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 5000)]);
            if ($presented['retryable']) {
                throw $exception;
            }
            $telegram->sendMessage((string) $update->chat_id,
                'Не удалось подготовить загруженный товар. Оригиналы сохранены; уточните описание. '.$presented['message']);
            $update->update(['processed_at' => now()]);
        } finally {
            $intake->clearUploadMarkerIfIdle($appendDraftId);
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        if ($update && ! $update->processed_at && $update->status !== 'cancelled') {
            $update->update(['status' => 'failed', 'processed_at' => now(),
                'error' => mb_substr($exception?->getMessage() ?? 'Product intake failed.', 0, 5000)]);
            app(TelegramClient::class)->sendMessage((string) $update->chat_id,
                'Не удалось завершить разбор товара. Загрузка сохранена; отправьте описание ещё раз или исправьте её в черновиках.');
        }
        app(TelegramProductUploadIntake::class)->clearUploadMarkerIfIdle($update ? data_get($update->payload, '_manual_product_draft_id') : null);
    }
}
