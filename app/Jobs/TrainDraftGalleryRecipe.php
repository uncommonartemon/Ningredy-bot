<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Products\OperatorDomainHintRecorder;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductRequestContext;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDraftEditor;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TrainDraftGalleryRecipe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Same reasoning as ContinueDraftGallerySearch/RestageDraftGalleryPhotos:
    // must clear the search-cycle ceiling (1800s) with margin and stay under
    // the queue worker's --timeout (2160s).
    public int $timeout = 2040;

    public function __construct(
        public int $draftId,
        public int $telegramUpdateId,
        public string $chatId,
        public ?string $hint = null,
        public ?int $expectedDraftTelegramUpdateId = null,
    ) {
        $this->onQueue('media');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("draft-gallery-retrain:{$this->draftId}"))
            ->releaseAfter($this->timeout + 60)
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(
        ProductGalleryRecipeTrainer $trainer,
        TelegramClient $telegram,
        OperatorDomainHintRecorder $hints,
    ): void {
        $draft = ProductDraft::query()->with('media')->findOrFail($this->draftId);
        $update = TelegramUpdate::findOrFail($this->telegramUpdateId);
        $this->assertCurrent($draft, $update);
        throw_unless(
            $this->expectedDraftTelegramUpdateId !== null
                && (int) $draft->telegram_update_id === $this->expectedDraftTelegramUpdateId,
            RuntimeException::class,
            'Queued gallery recipe training belongs to a different draft generation.',
        );
        throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Черновик уже обработан.');
        $url = trim((string) $draft->primary_source_url);
        $fingerprint = app(TelegramDraftEditor::class)->fingerprint($draft);
        throw_if($url === '', RuntimeException::class, 'У черновика нет ссылки на товарную карточку.');
        $category = Category::query()
            ->where('slug', trim((string) $draft->category))
            ->first();

        // The hint is knowledge about this site, not about this one draft, so
        // it is persisted for the domain before training starts: every later
        // search on the domain then receives it as domain_hint, even though
        // this run's own recipe may be retrained or dropped many times over.
        $hints->remember($url, $this->hint);

        $progress = new TelegramProgressReporter($telegram, $this->chatId);
        $progress->step('1/2 · AI изучает DOM и проверяет новый Playwright-рецепт', 1080);
        $images = $trainer->train(
            $url,
            'manual_telegram',
            function (string $level, string $message) use ($progress): void {
                if ($level === 'error') {
                    $progress->failed('AI-тренер', $message);
                } elseif ($level === 'warning') {
                    $progress->warning($message);
                } elseif ($level === 'done') {
                    $progress->done($message);
                } else {
                    $progress->info($message);
                }
            },
            true,
            // The button press: budget, cancellation and audit of this run.
            $this->telegramUpdateId,
            context: [
                'minimum_verified_images' => $category?->minimumVerifiedImages() ?? 3,
                'minimum_image_width' => $category?->minimumImageWidth(),
                'minimum_image_height' => $category?->minimumImageHeight(),
                'category_slug' => $category?->slug,
                // The request itself is the draft's; the hint below is added
                // beside it, not in its place.
                ProductRequestContext::CONTEXT_KEY => $draft->id,
            ],
            userHint: $this->hint,
        );
        throw_if(count($images) < 2, RuntimeException::class, 'Новый рецепт не прошёл проверку; старый рецепт и фото сохранены.');

        DB::transaction(function () use ($images, $update, $fingerprint): void {
            $current = ProductDraft::query()->lockForUpdate()->findOrFail($this->draftId);
            $this->assertCurrent($current, $update);
            throw_unless(hash_equals($fingerprint, app(TelegramDraftEditor::class)->fingerprint($current)),
                RuntimeException::class, 'Черновик изменился во время обучения. Новый набор не применяется к изменённой карточке.');
            $current->update(['image_urls' => $images]);
        });
        $draft->refresh()->load('media');
        $actions = $draft->media->values()->map(fn ($media, int $index): array => [
            'action' => 'replace',
            'media_id' => $media->id,
            'position' => $index + 1,
        ])->all();

        if ($actions === []) {
            $telegram->sendMessage($this->chatId, '✅ Рецепт переобучен. В черновике пока нет фото для замены.');
            $this->clearQueuedMarker();

            return;
        }

        $progress->step('2/2 · рецепт готов; заменяю фотографии по новой галерее', 300);
        ProcessDraftPhotoActions::dispatch(
            $draft->id,
            $actions,
            $this->telegramUpdateId,
            $this->chatId,
            expectedDraftTelegramUpdateId: $this->expectedDraftTelegramUpdateId,
        );
        $this->clearQueuedMarker();
    }

    private function assertCurrent(ProductDraft $draft, TelegramUpdate $update): void
    {
        app(ProductSearchCancellation::class)->throwIfCancelled($update->id);
        throw_unless($this->expectedDraftTelegramUpdateId !== null
            && (int) $draft->telegram_update_id === $this->expectedDraftTelegramUpdateId
            && $draft->status === 'pending_review'
            && (string) $update->chat_id === $this->chatId
            && app(DraftAccess::class)->belongsToContext($draft, $update),
            RuntimeException::class, 'Черновик или контекст переобучения изменились.');
    }

    private function clearQueuedMarker(): void
    {
        $key = "draft-gallery-retrain:{$this->draftId}:queued";
        if (in_array(Cache::get($key), [true, $this->telegramUpdateId], true)) {
            Cache::forget($key);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->clearQueuedMarker();

        try {
            app(TelegramClient::class)->sendMessage(
                $this->chatId,
                '⚠️ Переобучение источника не завершено. Старый рецепт сохранён.'
                .($exception?->getMessage() ? "\nПричина: ".mb_substr($exception->getMessage(), 0, 500) : ''),
            );
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }
}
