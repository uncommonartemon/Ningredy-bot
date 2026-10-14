<?php

namespace App\Jobs;

use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductMedia;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Products\CatalogPhotoSelection;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPhotoManager;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** A new paid operation with its own caller; never writes a gallery while discovery is running. */
class RefindCatalogPhotos implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 2040;

    public function __construct(public int $productId, public int $variantId, public int $sourceDraftId,
        public int $telegramUpdateId, public string $fingerprint, public array $replaceMediaIds = [])
    {
        $this->onQueue('media');
    }

    public function handle(ProductImageStorage $storage, TelegramClient $telegram, CatalogPhotoSelection $selection): void
    {
        $lock = Cache::lock('catalog-photos:'.$this->productId.':'.$this->variantId, 2100);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        $paths = [];
        try {
            $update = TelegramUpdate::findOrFail($this->telegramUpdateId);
            $operation = AiOperation::firstOrCreate(['idempotency_key' => 'refind-catalog:'.hash('sha256', json_encode([
                $this->telegramUpdateId, $this->productId, $this->variantId, $this->replaceMediaIds]))], [
                    'telegram_update_id' => $update->id, 'telegram_user_id' => $update->telegram_user_id,
                    'tool' => self::class, 'action' => 'refind_catalog_photos', 'target_type' => Product::class,
                    'target_id' => $this->productId, 'status' => 'running', 'payload' => ['variant_id' => $this->variantId],
                ]);
            if (in_array($operation->status, ['completed', 'cancelled', 'ignored'], true)) {
                return;
            }
            $product = Product::findOrFail($this->productId);
            $variant = $selection->variant($product, $this->variantId);
            $selection->assertCurrent($product, $variant, $this->fingerprint, $update->id);
            $source = ProductDraft::findOrFail($this->sourceDraftId);
            throw_unless((int) $source->approved_product_id === $product->id && (int) $source->approved_variant_id === $variant->id,
                RuntimeException::class, 'Исходный черновик относится к другой комплектации.');
            $draft = ProductDraft::find(data_get($operation->payload, 'working_draft_id'));
            if (! $draft) {
                $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'photo_refresh_snapshot',
                    'prompt' => 'Photo refresh for product '.$product->id.' variant '.$variant->id, 'status' => 'completed',
                    'started_at' => now(), 'completed_at' => now(), 'usage' => []]);
                $draft = $source->replicate(['telegram_review_chat_id', 'telegram_review_message_ids', 'telegram_control_message_ids',
                    'telegram_review_caption', 'telegram_review_finalized_at', 'images_staged_at', 'reviewed_at']);
                $draft->fill($selection->verificationDraft($product, $variant)->attributesToArray());
                $draft->fill(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
                    'requested_by_telegram_user_id' => $update->telegram_user_id, 'status' => 'photo_refresh',
                    'gallery_status' => 'pending', 'gallery_confirmed_sufficient' => false,
                    'specifications_reconciled_source_url' => null, 'specifications_reconciliation_attempts' => 0,
                    'gallery_search_stop_reason' => null]);
                $draft->save();
                $operation->update(['payload' => [...$operation->payload, 'working_draft_id' => $draft->id]]);
            }
            $progress = new TelegramProgressReporter($telegram, $update->chat_id);
            $progress->withCancelButton($update->id, 'Отменить поиск фото');
            if (! $draft->images_staged_at) {
                $storage->stage($draft, fn ($message) => $progress->info($message), $update->id);
            }
            $selection->assertCurrent($product, $variant, $this->fingerprint, $update->id);
            $draft->refresh();
            throw_if($draft->reconciliationPending(), RuntimeException::class, 'Новая галерея пока не согласована с товаром. Старые фото сохранены.');
            $stored = DB::transaction(function () use ($selection, $update, $draft, $operation, &$paths): int {
                $product = Product::query()->lockForUpdate()->findOrFail($this->productId);
                $variant = $selection->variant($product, $this->variantId);
                $product->variants()->lockForUpdate()->findOrFail($variant->id);
                $selection->assertCurrent($product, $variant, $this->fingerprint, $update->id);
                $gallery = $selection->gallery($product, $variant);
                throw_unless(collect($this->replaceMediaIds)->diff($gallery->pluck('id'))->isEmpty(), RuntimeException::class,
                    'Выбранные фотографии больше не относятся к этой галерее.');
                $target = (int) config('product-images.max_images_by_type.'.$product->product_type, config('product-images.max_images', 3));
                $limit = $this->replaceMediaIds !== [] ? count($this->replaceMediaIds) : max(0, $target - $gallery->count());
                $checksums = $gallery->pluck('checksum')->filter();
                $staged = $draft->media()->whereIn('verification_status', ['verified', 'source_verified'])
                    ->orderByDesc('is_primary')->orderBy('sort_order')->get()->filter(fn ($m) => ! $checksums->contains($m->checksum))->take($limit);
                $stored = 0;
                foreach ($staged as $media) {
                    throw_unless($media->disk && $media->path && Storage::disk($media->disk)->exists($media->path), RuntimeException::class, 'Новый файл недоступен.');
                    $path = 'products/'.$product->id.'/refind-'.Str::uuid().'.webp';
                    throw_unless(Storage::disk('public')->put($path, Storage::disk($media->disk)->get($media->path)), RuntimeException::class, 'Фото не сохранено.');
                    $paths[] = $path;
                    $product->media()->create([...$media->only(['role', 'source_url', 'mime_type', 'width', 'height', 'file_size',
                        'checksum', 'verification_status', 'verification_score', 'verification_model', 'verification_notes']),
                        'type' => 'image', 'product_variant_id' => $variant->id, 'disk' => 'public', 'path' => $path,
                        'sort_order' => $gallery->count() + $stored, 'is_primary' => $gallery->isEmpty() && $stored === 0, 'verified_at' => now()]);
                    $stored++;
                }
                app(ProductSearchCancellation::class)->throwIfCancelled($update->id);
                app(ProductPhotoManager::class)->completeRefind($product, $this->replaceMediaIds, $stored, $variant->id);
                $draft->update(['status' => 'approved', 'approved_product_id' => $product->id, 'approved_variant_id' => $variant->id]);
                $operation->update(['status' => 'completed', 'result' => ['stored' => $stored], 'executed_at' => now()]);

                return $stored;
            });
            $paths = [];
            $progress->clearCancelButton();
            $telegram->sendMessage($update->chat_id, $stored > 0 ? 'Галерея выбранной комплектации обновлена: '.$stored.' фото.' : 'Новых проверенных кадров нет. Прежние фотографии сохранены.');
            app(ProductCardPresenter::class)->send($telegram, $update->chat_id, $product->fresh(), variantId: $variant->id,
                telegramUserId: $update->telegram_user_id);
        } catch (Throwable $error) {
            foreach ($paths as $path) {
                if (! ProductMedia::where('disk', 'public')->where('path', $path)->exists()
                    && ! ProductDraftMedia::where('disk', 'public')->where('path', $path)->exists()) {
                    Storage::disk('public')->delete($path);
                }
            }
            if (isset($operation) && $operation->fresh()->status !== 'completed') {
                $operation->update(['status' => app(ProductSearchCancellation::class)->isCancelled($this->telegramUpdateId) ? 'cancelled' : 'failed',
                    'error' => mb_substr($error->getMessage(), 0, 1000)]);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! app(ProductSearchCancellation::class)->isCancelled($this->telegramUpdateId)) {
            $update = TelegramUpdate::find($this->telegramUpdateId);
            if ($update) {
                app(TelegramClient::class)->sendMessage($update->chat_id, 'Обновление фото не завершено. Старые фото сохранены. '.mb_substr($exception?->getMessage() ?? '', 0, 500));
            }
        }
    }
}
