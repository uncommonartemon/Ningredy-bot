<?php

namespace App\Jobs;

use App\Models\AiOperation;
use App\Models\ProductDraft;
use App\Services\Ai\AiUsageReporter;
use App\Services\Products\ProductPhotoEnhancer;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EnhanceDraftPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    public array $backoff = [20, 90];

    public function __construct(
        public int $draftId,
        public int $mediaId,
        public int $telegramUpdateId,
        public string $chatId,
        public int $operationId,
    ) {
        $this->onQueue('media');
    }

    public function handle(
        ProductPhotoEnhancer $enhancer,
        DraftTelegramPresenter $presenter,
        TelegramClient $telegram,
    ): void {
        $lock = Cache::lock($this->lockKey(), 240);

        if (! $lock->get()) {
            throw new RuntimeException('This draft photo is already being enhanced.');
        }

        $startedAt = now();

        try {
            $draft = ProductDraft::query()->with('media')->find($this->draftId);
            throw_unless($draft, RuntimeException::class, 'Draft not found.');
            throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Draft is no longer awaiting review.');

            $media = $draft->media->firstWhere('id', $this->mediaId);
            throw_unless($media, RuntimeException::class, 'Draft photo not found.');
            $marker = "[AI-enhanced by Telegram update {$this->telegramUpdateId}]";

            if (! Str::contains((string) $media->verification_notes, $marker)) {
                $enhancer->enhance($media, $this->telegramUpdateId, $draft);
            }

            AiOperation::query()->whereKey($this->operationId)->update([
                'status' => 'completed',
                'result' => ['draft_id' => $draft->id, 'media_id' => $media->id],
                'executed_at' => now(),
            ]);
            Cache::forget($this->queuedKey());
            $presenter->sendReview(
                $telegram,
                $this->chatId,
                $draft->fresh('media'),
                $this->usageFootnote($startedAt),
            );
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget($this->queuedKey());
        AiOperation::query()->whereKey($this->operationId)->update([
            'status' => 'failed',
            'error' => mb_substr((string) $exception?->getMessage(), 0, 5000),
            'executed_at' => now(),
        ]);
        $draft = ProductDraft::query()->find($this->draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            return;
        }

        $telegram = app(TelegramClient::class);
        $message = 'Не удалось улучшить фото. Исходное изображение сохранено.'
            .($exception?->getMessage() ? "\nПричина: ".mb_substr($exception->getMessage(), 0, 500) : '');

        try {
            $telegram->sendMessage($this->chatId, $message);
            app(DraftTelegramPresenter::class)->sendControls($telegram, $this->chatId, $draft);
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }

    private function usageFootnote(\DateTimeInterface $since): string
    {
        $usage = app(AiUsageReporter::class)->forTelegramUpdate($this->telegramUpdateId, $since);
        $tokens = (int) ($usage['tokens']['total'] ?? 0);

        if ($tokens <= 0 && $usage['estimated_cost_usd'] === null) {
            return '';
        }

        $line = $tokens > 0 ? "\n\n🔢 Токены улучшения: ".number_format($tokens, 0, '.', ' ') : '';

        if ($usage['estimated_cost_usd'] !== null) {
            $line .= sprintf(' (~$%s)', number_format((float) $usage['estimated_cost_usd'], 4));
        }

        return $line;
    }

    private function queuedKey(): string
    {
        return "draft-photo-enhance:{$this->draftId}:queued";
    }

    private function lockKey(): string
    {
        return "draft-photo-enhance:{$this->draftId}:lock";
    }
}
