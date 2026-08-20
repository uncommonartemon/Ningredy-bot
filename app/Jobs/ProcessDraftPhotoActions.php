<?php

namespace App\Jobs;

use App\Models\AiOperation;
use App\Models\ProductDraft;
use App\Services\Products\DraftPhotoManager;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPhotoEnhancer;
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
            $draft = ProductDraft::query()->with('media')->findOrFail($this->draftId);
            throw_unless(
                $this->expectedDraftTelegramUpdateId !== null
                    && (int) $draft->telegram_update_id === $this->expectedDraftTelegramUpdateId,
                RuntimeException::class,
                'Queued photo actions belong to a different draft generation.',
            );
            throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Черновик уже обработан.');
            $progress->info("Черновик #{$draft->id}: принято операций — ".count($this->actions).'.');

            foreach ($this->actions as $index => $action) {
                $media = $draft->media()->find($action['media_id']);
                throw_unless($media, RuntimeException::class, "Фото {$action['position']} не найдено.");
                $number = $index + 1;

                if ($action['action'] === 'enhance') {
                    $progress->step("{$number}/".count($this->actions)." · улучшение фото {$action['position']}", 180);
                    $enhancer->enhance($media, $this->telegramUpdateId, $draft);
                } elseif ($action['action'] === 'replace') {
                    $progress->step("{$number}/".count($this->actions)." · поиск замены фото {$action['position']}", 180);
                    $images->replaceDraftMedia($draft, $media, fn (string $message) => $progress->info($message), $this->telegramUpdateId);
                } elseif ($action['action'] === 'delete') {
                    $progress->step("{$number}/".count($this->actions)." · удаление фото {$action['position']}", 10);
                    $manager->delete($draft, $media);
                } else {
                    throw new RuntimeException("Неизвестная операция: {$action['action']}.");
                }
            }

            $this->updateOperation('completed', ['draft_id' => $draft->id, 'actions' => $this->actions]);
            $progress->done('Все изменения фотографий выполнены');
            $presenter->sendReview($telegram, $this->chatId, $draft->fresh('media'));
        } catch (Throwable $exception) {
            $this->updateOperation('failed', null, $exception);
            $progress->failed('Обработка фотографий остановлена', $exception);

            // A failed action (no match found, provider error, ...) used to
            // leave the operator stuck with no way back into the draft - the
            // photo-selection menu was already deleted when the action was
            // queued, and nothing replaced it. Bring the control panel back
            // whenever the draft is still there to act on.
            if (isset($draft)) {
                $refreshedDraft = $draft->fresh('media');

                if ($refreshedDraft?->status === 'pending_review') {
                    try {
                        $presenter->sendControls($telegram, $this->chatId, $refreshedDraft);
                    } catch (Throwable $controlsException) {
                        report($controlsException);
                    }
                }
            }

            throw $exception;
        } finally {
            Cache::forget("draft-photo-actions:{$this->draftId}:queued");
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget("draft-photo-actions:{$this->draftId}:queued");
        $this->updateOperation('failed', null, $exception);
    }

    private function updateOperation(string $status, ?array $result = null, ?Throwable $error = null): void
    {
        if (! $this->operationId) {
            return;
        }

        AiOperation::query()->whereKey($this->operationId)->update([
            'status' => $status,
            'result' => $result,
            'error' => $error ? mb_substr($error->getMessage(), 0, 5000) : null,
            'executed_at' => now(),
        ]);
    }
}
