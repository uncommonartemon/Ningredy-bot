<?php

namespace App\Services\Telegram;

use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Commit exactly the reviewed gallery of a catalog edit; other variants keep their files. */
class TelegramCatalogDraftMedia
{
    public function commit(Product $product, ProductVariant $variant, ProductDraft $draft): int
    {
        $copies = [];
        try {
            return DB::transaction(function () use ($product, $variant, $draft, &$copies): int {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);
                $variant = $product->variants()->lockForUpdate()->findOrFail($variant->id);
                $draft = $draft->fresh(['telegramUpdate', 'media']);
                throw_unless($draft->status === 'approved' && $draft->isManualUpload()
                    && (int) $draft->approved_product_id === (int) $product->id
                    && (int) $draft->approved_variant_id === (int) $variant->id
                    && (int) data_get($draft->manualIntake(), 'target_product_id') === (int) $product->id,
                    RuntimeException::class, 'Карточка не подтверждена для этого товара и комплектации.');
                $expected = (string) data_get($draft->manualIntake(), 'approved_catalog_fingerprint');
                if ($draft->media->isEmpty() && data_get($draft->manualIntake(), 'media_committed_at')) {
                    return 0;
                }
                throw_unless($expected !== '' && hash_equals($expected, app(TelegramCatalogEditor::class)->fingerprint($product, $variant)),
                    RuntimeException::class, 'Каталог изменился до сохранения фото. Исходные файлы сохранены; требуется повторный просмотр.');
                throw_if($draft->media->isEmpty(), RuntimeException::class, 'В подтверждённой галерее отсутствуют файлы.');
                $galleryProof = (string) data_get($draft->manualIntake(), 'approved_draft_media_fingerprint');
                throw_unless($galleryProof !== '' && hash_equals($galleryProof, $this->galleryFingerprint($draft)),
                    RuntimeException::class, 'Галерея изменена после подтверждения. Требуется повторный просмотр; фотографии каталога сохранены.');
                $checksums = [];
                foreach ($draft->media->sortBy('sort_order')->values() as $index => $frame) {
                    throw_unless($frame->disk && $frame->path && $frame->checksum && Storage::disk($frame->disk)->exists($frame->path),
                        RuntimeException::class, 'Файл галереи недоступен. Существующие фотографии сохранены.');
                    $checksums[] = $frame->checksum;
                    $media = $product->media()->where('product_variant_id', $variant->id)->where('type', 'image')
                        ->where('checksum', $frame->checksum)->first();
                    if (! $media) {
                        $path = 'products/'.$product->id.'/manual-'.Str::uuid().'.'.pathinfo($frame->path, PATHINFO_EXTENSION);
                        $copies[] = $path;
                        throw_unless(Storage::disk('public')->put($path, Storage::disk($frame->disk)->get($frame->path)),
                            RuntimeException::class, 'Не удалось сохранить файл галереи. Существующие фотографии сохранены.');
                        $media = $product->media()->create([
                            'product_variant_id' => $variant->id, 'type' => 'image', 'disk' => 'public', 'path' => $path,
                            ...$frame->only(['role', 'source_url', 'mime_type', 'width', 'height', 'file_size', 'checksum',
                                'verification_status', 'verification_score', 'verification_model', 'verification_notes', 'verified_at']),
                        ]);
                    }
                    $media->update(['sort_order' => $index, 'is_primary' => $index === 0]);
                }
                $product->media()->where('product_variant_id', $variant->id)->where('type', 'image')
                    ->whereNotIn('checksum', $checksums)->get()->each->delete();
                $draft->media->each->delete();
                $origin = $draft->telegramUpdate;
                $payload = $origin->payload;
                $payload['_manual_product_intake']['media_committed_at'] = now()->toIso8601String();
                $origin->update(['payload' => $payload]);

                return count($checksums);
            });
        } catch (Throwable $error) {
            foreach ($copies as $path) {
                if (! ProductMedia::where('disk', 'public')->where('path', $path)->exists()
                    && ! ProductDraftMedia::where('disk', 'public')->where('path', $path)->exists()) {
                    Storage::disk('public')->delete($path);
                }
            }
            throw $error;
        }
    }

    /** Bind the exact reviewed rows and actual file contents to approval. */
    public function galleryFingerprint(ProductDraft $draft): string
    {
        $frames = $draft->media()->orderBy('sort_order')->orderBy('id')->get()->map(function ($frame): array {
            throw_unless($frame->disk && $frame->path && Storage::disk($frame->disk)->exists($frame->path),
                RuntimeException::class, 'Подтверждаемая фотография недоступна.');

            return [...$frame->only(['id', 'disk', 'path', 'checksum', 'source_url', 'mime_type', 'width', 'height',
                'verification_status', 'verification_score', 'verification_model', 'verification_notes', 'sort_order', 'is_primary']),
                'file_sha256' => hash('sha256', Storage::disk($frame->disk)->get($frame->path))];
        })->all();

        return hash('sha256', json_encode($frames, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
