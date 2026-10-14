<?php

namespace App\Jobs;

use App\Models\AiOperation;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\DraftPhotoManager;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPhotoEnhancer;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ProcessDraftPhotoActions implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public array $backoff = [20, 90];

    /** @param array<int, array{action: string, media_id: int, position: int}> $actions */
    public function __construct(
        public int $draftId,
        public array $actions,
        public int $telegramUpdateId,
        public string $chatId,
        public ?int $operationId = null,
        public ?int $expectedDraftTelegramUpdateId = null,
    ) {
        $this->onQueue('media');
    }

    public function handle(
        ProductPhotoEnhancer $enhancer,
        ProductImageStorage $images,
        DraftPhotoManager $manager,
        DraftTelegramPresenter $presenter,
        TelegramClient $telegram,
    ): void {
        $lock = Cache::lock("draft-photo-actions:{$this->draftId}", 620);
        throw_unless($lock->get(), RuntimeException::class, 'Фото этого черновика уже обрабатываются.');
        $progress = new TelegramProgressReporter($telegram, $this->chatId);

        try {
            $update = TelegramUpdate::findOrFail($this->telegramUpdateId);
            $operation = $this->operation($update);
            if (! $operation || in_array($operation->status, ['completed', 'cancelled', 'ignored'], true)) {
                return;
            }
            $draft = ProductDraft::query()->with('media')->find($this->draftId);
            if (! $this->canContinue($draft, $update)) {
                $this->updateOperation($update->fresh()->cancel_requested_at ? 'cancelled' : 'ignored', $operation->result);

                return;
            }
            $completed = (array) data_get($operation->result, 'completed_actions', []);
            Cache::add("draft-photo-actions:{$this->draftId}:queued", $this->telegramUpdateId, 620);
            $progress->withCancelButton($this->telegramUpdateId, 'Отменить обработку');
            $progress->info("Черновик #{$draft->id}: принято операций — ".count($this->actions).'.');

            foreach ($this->actions as $index => $action) {
                if (in_array($index, $completed, true)) {
                    continue;
                }
                if (! $this->canContinue($draft, $update)) {
                    $this->updateOperation($update->fresh()->cancel_requested_at ? 'cancelled' : 'ignored',
                        ['draft_id' => $draft->id, 'actions' => $this->actions, 'completed_actions' => $completed]);

                    return;
                }
                $media = $draft->media()->find($action['media_id']);
                throw_unless($media, RuntimeException::class, "Фото {$action['position']} не найдено.");
                $beforeSave = function () use ($draft, $update, $media): void {
                    $current = $media->fresh();
                    throw_unless($this->canContinue($draft, $update) && $current
                        && $current->only(['disk', 'path', 'checksum']) === $media->only(['disk', 'path', 'checksum']),
                        RuntimeException::class, 'Фото или контекст изменились во время обработки. Исходный файл сохранён.');
                };
                $number = $index + 1;

                if ($action['action'] === 'enhance') {
                    $progress->step("{$number}/".count($this->actions)." · улучшение фото {$action['position']}", 180);
                    $enhancer->enhance($media, $this->telegramUpdateId, $draft, $beforeSave);
                } elseif ($action['action'] === 'replace') {
                    $progress->step("{$number}/".count($this->actions)." · поиск замены фото {$action['position']}", 180);
                    $images->replaceDraftMedia($draft, $media, fn (string $message) => $progress->info($message), $this->telegramUpdateId, $beforeSave);
                } elseif ($action['action'] === 'delete') {
                    $progress->step("{$number}/".count($this->actions)." · удаление фото {$action['position']}", 10);
                    $manager->delete($draft, $media);
                } else {
                    throw new RuntimeException("Неизвестная операция: {$action['action']}.");
                }
                $completed[] = $index;
                $this->updateOperation('running', ['draft_id' => $draft->id, 'actions' => $this->actions, 'completed_actions' => $completed]);
            }

            $this->updateOperation('completed', ['draft_id' => $draft->id, 'actions' => $this->actions, 'completed_actions' => $completed]);
            $progress->clearCancelButton();
            $progress->done('Все изменения фотографий выполнены');
            $presenter->sendReview($telegram, $this->chatId, $draft->fresh('media'));
        } catch (Throwable $exception) {
            if (isset($draft, $update) && ! $this->canContinue($draft, $update)) {
                $this->updateOperation($update->fresh()->cancel_requested_at ? 'cancelled' : 'ignored',
                    ['draft_id' => $this->draftId, 'actions' => $this->actions, 'completed_actions' => $completed ?? []]);

                return;
            }
            $this->updateOperation('failed', isset($completed)
                ? ['draft_id' => $this->draftId, 'actions' => $this->actions, 'completed_actions' => $completed] : null, $exception);
            $progress->failed('Обработка фотографий остановлена', $exception);

            // A failed action (no match found, provider error, ...) used to
            // leave the operator stuck with no way back into the draft - the
            // photo-selection menu was already deleted when the action was
            // queued, and nothing replaced it. Bring the control panel back
            // whenever the draft is still there to act on.
            if (isset($draft)) {
                $refreshedDraft = $draft->fresh('media');

                if (isset($update) && $this->canContinue($refreshedDraft, $update)) {
                    try {
                        $presenter->sendControls($telegram, $this->chatId, $refreshedDraft);
                    } catch (Throwable $controlsException) {
                        report($controlsException);
                    }
                }
            }

            throw $exception;
        } finally {
            $this->clearQueuedMarker();
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->clearQueuedMarker();
        $this->updateOperation('failed', null, $exception);
    }

    private function operation(TelegramUpdate $update): ?AiOperation
    {
        if ($this->operationId) {
            return AiOperation::query()->whereKey($this->operationId)
                ->where('telegram_update_id', $update->id)->where('telegram_user_id', $update->telegram_user_id)
                ->whereIn('action', ['enhance_draft_photo', 'replace_draft_photo', 'delete_draft_photo', 'process_draft_photos'])
                ->where('payload->draft_id', $this->draftId)->first();
        }
        // Legacy callers and trained-gallery replacements also need a persistent retry receipt.
        $operation = AiOperation::firstOrCreate(['idempotency_key' => 'draft-photo-actions:'.hash('sha256', json_encode([
            $this->telegramUpdateId, $this->draftId, $this->expectedDraftTelegramUpdateId, $this->actions,
        ], JSON_THROW_ON_ERROR))], ['telegram_update_id' => $update->id, 'telegram_user_id' => $update->telegram_user_id,
            'tool' => class_basename(self::class), 'action' => 'process_draft_photos', 'target_type' => ProductDraft::class,
            'target_id' => $this->draftId, 'payload' => ['draft_id' => $this->draftId, 'actions' => $this->actions], 'status' => 'running']);
        $this->operationId = $operation->id;

        return $operation;
    }

    private function canContinue(?ProductDraft $draft, TelegramUpdate $update): bool
    {
        $draft = $draft?->fresh();
        $update = $update->fresh();
        $marker = Cache::get("draft-photo-actions:{$this->draftId}:queued");

        return $draft && $update && ! $update->cancel_requested_at && $update->status !== 'cancelled'
            && in_array($marker, [null, true, $this->telegramUpdateId], true)
            && hash_equals((string) $update->chat_id, $this->chatId)
            && $this->expectedDraftTelegramUpdateId !== null
            && (int) $draft->telegram_update_id === $this->expectedDraftTelegramUpdateId
            && $draft->status === 'pending_review'
            && app(DraftAccess::class)->belongsToContext($draft, $update);
    }

    private function clearQueuedMarker(): void
    {
        $key = "draft-photo-actions:{$this->draftId}:queued";
        if (in_array(Cache::get($key), [true, $this->telegramUpdateId], true)) {
            Cache::forget($key);
        }
    }

    private function updateOperation(string $status, ?array $result = null, ?Throwable $error = null): void
    {
        if (! $this->operationId) {
            return;
        }

        AiOperation::query()->whereKey($this->operationId)
            ->where('telegram_update_id', $this->telegramUpdateId)
            ->whereNotIn('status', ['completed', 'cancelled', 'ignored'])->update([
                'status' => $status,
                ...($result !== null ? ['result' => $result] : []),
                'error' => $error ? mb_substr($error->getMessage(), 0, 5000) : null,
                'executed_at' => now(),
            ]);
    }
}
