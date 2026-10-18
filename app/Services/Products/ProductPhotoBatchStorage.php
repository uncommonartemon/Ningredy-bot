<?php

namespace App\Services\Products;

use App\Exceptions\ProductSearchCancelled;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductMedia;
use App\Models\ProductPhotoBatch;
use App\Models\ProductPhotoFrame;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use GdImage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Durable, source-local work queue. These rows do not grant catalog approval. */
class ProductPhotoBatchStorage
{
    public function __construct(private readonly ProductImageEncoder $encoder) {}

    public function prepare(ProductDraft $draft, array &$candidates, array $source, ?int $updateId): ProductPhotoBatch
    {
        $files = [];
        try {
            return DB::transaction(function () use ($draft, &$candidates, $source, $updateId, &$files): ProductPhotoBatch {
                app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
                $fresh = ProductDraft::query()->lockForUpdate()->findOrFail($draft->id);
                $target = ProductPhotoApplicability::bind($draft, $source, []);
                if ($fresh->status !== $draft->status || $fresh->telegram_update_id !== $draft->telegram_update_id
                    || ! ProductPhotoApplicability::matchesTarget($fresh, $target)) {
                    throw new ProductSearchCancelled;
                }
                $batch = ProductPhotoBatch::query()->create([
                    'product_draft_id' => $draft->id, 'generation' => $draft->telegram_update_id,
                    'source_url' => $source['url'], 'source_context' => [...$source, '_batch_target' => $target],
                    'target_fingerprint' => $target['target_fingerprint'],
                    'policy_fingerprint' => $this->policyFingerprint($fresh),
                    'owner_gallery_signature' => $this->gallerySignature($fresh),
                    'gallery_traversal_complete' => collect($candidates)->every(fn (array $frame): bool => ($frame['confirmed_gallery'] ?? false) && ! ($frame['partial_gallery'] ?? false)),
                    'status' => 'pending',
                ]);
                foreach ($candidates as $index => &$candidate) {
                    $encoded = $this->encoder->toWebp($candidate['image']);
                    $checksum = hash('sha256', $encoded['bytes']);
                    $path = "drafts/{$draft->id}/photo-batches/{$batch->id}/{$index}-{$checksum}.webp";
                    $temporary = $path.'.writing';
                    $files[] = $temporary;
                    $files[] = $path;
                    if (! Storage::disk('public')->put($temporary, $encoded['bytes'])
                        || ! Storage::disk('public')->move($temporary, $path)) {
                        throw new RuntimeException('Could not persist pending gallery frame.');
                    }
                    $frame = $batch->frames()->create([
                        'source_url' => $candidate['source_url'], 'disk' => 'public', 'path' => $path,
                        'checksum' => $checksum, 'width' => $encoded['width'], 'height' => $encoded['height'],
                        'file_size' => strlen($encoded['bytes']), 'position' => $index, 'status' => 'pending',
                        'candidate_context' => Arr::only($candidate, ['source_url', 'original_url', 'product_page_url',
                            'page_source_url', 'page_source_context', 'confirmed_gallery', 'partial_gallery',
                            'source_identity_confirmed', 'media_id']),
                    ]);
                    $candidate['photo_batch_id'] = $batch->id;
                    $candidate['photo_frame_id'] = $frame->id;
                }

                return $batch;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($files);
            throw $exception;
        }
    }

    /** Persist each returned batch before asking Vision about the next one. */
    public function review(ProductPhotoBatch $batch, ProductDraft $draft, array $candidates, ProductImageVisionVerifier $verifier, ?int $updateId): GalleryBatchReview
    {
        $this->assertCurrent($batch, $draft, $updateId);
        $byId = collect($candidates)->keyBy('photo_frame_id');
        $pending = $batch->frames()->where('status', 'pending')->get();
        foreach ($pending->chunk(40) as $rows) {
            app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
            if (! app(ProductSearchTimeBudget::class)->canStart($updateId, 15)
                || app(ProductSearchCostBudget::class)->exceeded($updateId)) {
                $batch->update(['interruption_reason' => 'Remaining search budget does not permit another Vision batch.']);
                break;
            }
            // A frame whose in-memory candidate is gone stays pending: it is
            // not evidence against the photo and must not crash the batch.
            $rows = $rows->filter(fn ($frame): bool => is_array($byId->get($frame->id)))->values();
            if ($rows->isEmpty()) {
                $batch->update(['interruption_reason' => 'Pending frames have no loaded candidate image to review.']);
                break;
            }
            $frames = $rows->map(fn ($frame): array => $byId->get($frame->id))->all();
            $review = $verifier->reviewFrameBatch($frames, $draft, $updateId);
            DB::transaction(function () use ($batch, $draft, $updateId, $review, $rows): void {
                $this->assertCurrent($batch, $draft, $updateId);
                $eligibleIds = $rows->modelKeys();
                foreach (['accepted' => $review->accepted, 'rejected' => $review->rejected] as $status => $verdicts) {
                    foreach ($verdicts as $candidate) {
                        if (! in_array($candidate['photo_frame_id'] ?? null, $eligibleIds, true)) {
                            throw new RuntimeException('Vision verdict does not belong to the current source batch.');
                        }
                        // Model update, not a query-builder update: the review array
                        // must go through the JSON cast.
                        $batch->frames()->whereKey($candidate['photo_frame_id'])->where('status', 'pending')
                            ->lockForUpdate()->first()?->update([
                                'status' => $status, 'reviewed_at' => now(),
                                'review' => Arr::only($candidate, ['vision_kind', 'vision_score', 'vision_reason',
                                    'vision_model', 'photo_visual_review', 'frame_review']),
                            ]);
                    }
                }
                $batch->update(['status' => $batch->frames()->where('status', 'pending')->exists() ? 'pending' : 'reviewed',
                    'interruption_reason' => $review->interruptionReason, 'reviewed_at' => now()]);
            });
            if (! $review->isComplete()) {
                break;
            }
        }

        return $this->result($batch->refresh(), $candidates);
    }

    public function result(ProductPhotoBatch $batch, array $candidates): GalleryBatchReview
    {
        $byId = collect($candidates)->keyBy('photo_frame_id');
        $groups = ['accepted' => [], 'rejected' => [], 'pending' => []];
        foreach ($batch->frames()->get() as $frame) {
            $candidate = $byId->get($frame->id, [...$frame->candidate_context,
                'photo_batch_id' => $batch->id, 'photo_frame_id' => $frame->id]);
            $groups[$frame->status][] = [...$candidate, ...($frame->review ?? [])];
        }
        $incomplete = $groups['pending'] !== [];
        $groups['accepted'] = array_map(fn (array $candidate): array => [...$candidate,
            'review_incomplete' => $incomplete], $groups['accepted']);

        return new GalleryBatchReview($groups['accepted'], $groups['rejected'], $groups['pending'], $batch->interruption_reason);
    }

    /** No HTTP/recipe here: only the unchanged local artifacts of one eligible source. */
    public function resumable(ProductDraft $draft, ?int $updateId): ?array
    {
        $batches = ProductPhotoBatch::query()->where('product_draft_id', $draft->id)
            ->where('generation', $draft->telegram_update_id)
            ->where(fn ($query) => $query->where('status', 'pending')->orWhereNull('selected_at'))
            ->latest('id')->get();
        foreach ($batches as $batch) {
            if (! $this->isCurrent($batch, $draft)) {
                continue;
            }
            $candidates = [];
            try {
                foreach ($batch->frames()->whereIn('status', ['accepted', 'pending'])->get() as $frame) {
                    $encoded = $this->encodedFrame($frame);
                    $image = @imagecreatefromstring($encoded['bytes']);
                    if (! $image instanceof GdImage) {
                        throw new RuntimeException('Stored gallery frame cannot be decoded.');
                    }
                    $candidates[] = [...$frame->candidate_context, 'image' => $image,
                        'width' => $frame->width, 'height' => $frame->height,
                        'photo_batch_id' => $batch->id, 'photo_frame_id' => $frame->id];
                }
                $this->assertCurrent($batch, $draft, $updateId);

                return ['batch' => $batch, 'candidates' => $candidates];
            } catch (ProductSearchCancelled $exception) {
                foreach ($candidates as $candidate) {
                    imagedestroy($candidate['image']);
                }
                throw $exception;
            } catch (RuntimeException $exception) {
                foreach ($candidates as $candidate) {
                    imagedestroy($candidate['image']);
                }
                $batch->update(['status' => 'unavailable', 'interruption_reason' => $exception->getMessage(), 'selected_at' => now()]);
            }
        }

        return null;
    }

    /**
     * Hands an unfinished batch (a worker died mid-Vision, or the stage ended
     * before saving) to the ordinary pending-photo resume: draft media rows
     * reference the stored frame files, so nothing is downloaded or trained
     * again. Returns how many frames became pending draft photos.
     */
    public function materializePending(ProductDraft $draft, ?int $updateId): int
    {
        if ($draft->media()->where('verification_status', 'pending')->exists()) {
            return 0;
        }
        $resumable = $this->resumable($draft, $updateId);
        if ($resumable === null) {
            return 0;
        }
        foreach ($resumable['candidates'] as $candidate) {
            imagedestroy($candidate['image']);
        }
        $batch = $resumable['batch'];

        return DB::transaction(function () use ($draft, $batch, $updateId): int {
            $this->assertCurrent($batch, $draft, $updateId);
            $existing = $draft->media()->pluck('checksum')->all();
            $hasMedia = $existing !== [];
            $sort = $hasMedia ? (int) $draft->media()->max('sort_order') + 1 : 0;
            $created = 0;
            foreach ($batch->frames()->whereIn('status', ['accepted', 'pending'])->get() as $frame) {
                if (in_array($frame->checksum, $existing, true)) {
                    continue;
                }
                $context = $frame->candidate_context;
                $draft->media()->create([
                    'disk' => $frame->disk, 'path' => $frame->path, 'source_url' => $frame->source_url,
                    'role' => ! $hasMedia && $created === 0 ? 'primary' : 'detail', 'mime_type' => 'image/webp',
                    'width' => $frame->width, 'height' => $frame->height, 'file_size' => $frame->file_size,
                    'checksum' => $frame->checksum, 'verification_status' => 'pending',
                    'verification_notes' => 'Кадр сохранён до проверки Vision; проверка продолжается.',
                    'sort_order' => $sort + $created, 'is_primary' => ! $hasMedia && $created === 0,
                    'gallery_traversal_complete' => $batch->gallery_traversal_complete,
                    'photo_source_context' => is_array($context['page_source_context'] ?? null)
                        ? $context['page_source_context'] : ($batch->source_context ?? []),
                ]);
                $existing[] = $frame->checksum;
                $created++;
            }
            $batch->update(['status' => 'materialized', 'selected_at' => now()]);

            return $created;
        });
    }

    /**
     * Frames of a batch that can no longer resume (consumed, unreadable, or
     * superseded by a changed gallery, generation or policy) are deleted with
     * their files; the batch row stays as the audit of what happened.
     */
    public function purgeStale(ProductDraft $draft): void
    {
        $batches = ProductPhotoBatch::query()->where('product_draft_id', $draft->id)
            ->whereHas('frames')->get();
        foreach ($batches as $batch) {
            if ($batch->selected_at === null && $batch->status !== 'unavailable' && $this->isCurrent($batch, $draft)) {
                continue;
            }
            $batch->frames()->get()->each->delete();
            if ($batch->status === 'pending') {
                $batch->update(['status' => 'superseded']);
            }
        }
    }

    /**
     * A cancelled or superseded search keeps nothing it saved only for
     * resumption. Files a draft or product photo also uses stay.
     */
    public function discard(ProductDraft $draft): void
    {
        $batches = ProductPhotoBatch::query()->where('product_draft_id', $draft->id)
            ->where('status', '!=', 'materialized')->whereHas('frames')->get();
        foreach ($batches as $batch) {
            foreach ($batch->frames()->get() as $frame) {
                $shared = ProductDraftMedia::query()->where('disk', $frame->disk)->where('path', $frame->path)->exists()
                    || ProductMedia::query()->where('disk', $frame->disk)->where('path', $frame->path)->exists();
                if (! $shared) {
                    Storage::disk($frame->disk)->delete($frame->path);
                }
                $frame->deleteQuietly();
            }
            $batch->update(['status' => 'cancelled']);
        }
    }

    /** Frames of a deleted draft: Eloquent deletes so every file is cleaned up. */
    public static function deleteForDraft(int $draftId): void
    {
        ProductPhotoFrame::query()->whereIn('product_photo_batch_id',
            ProductPhotoBatch::query()->where('product_draft_id', $draftId)->select('id'))->get()->each->delete();
    }

    public function encodedCandidate(array $candidate): ?array
    {
        if (! isset($candidate['photo_frame_id'])) {
            return null;
        }

        return $this->encodedFrame(ProductPhotoFrame::query()->findOrFail($candidate['photo_frame_id']));
    }

    private function encodedFrame(ProductPhotoFrame $frame): array
    {
        $bytes = Storage::disk($frame->disk)->get($frame->path);
        if (! is_string($bytes) || ! hash_equals($frame->checksum, hash('sha256', $bytes))
            || ! $this->encoder->isSafeToDecode($frame->width, $frame->height)) {
            throw new RuntimeException('Stored gallery frame is missing or has changed.');
        }

        return ['bytes' => $bytes, 'width' => $frame->width, 'height' => $frame->height];
    }

    /** Must be called under the same transaction as selecting the draft gallery. */
    public function assertSelectionCurrent(ProductDraft $draft, ?array $source, ?int $updateId): void
    {
        if (isset($source['_photo_batch_id'])) {
            $this->assertCurrent(ProductPhotoBatch::query()->findOrFail($source['_photo_batch_id']), $draft, $updateId);
        }
    }

    public function selected(ProductDraft $draft, ?array $source): void
    {
        if (isset($source['_photo_batch_id'])) {
            ProductPhotoBatch::query()->whereKey($source['_photo_batch_id'])->where('product_draft_id', $draft->id)
                ->update(['owner_gallery_signature' => $this->gallerySignature($draft), 'selected_at' => now()]);
        }
    }

    private function assertCurrent(ProductPhotoBatch $batch, ProductDraft $draft, ?int $updateId): void
    {
        app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
        $fresh = ProductDraft::query()->lockForUpdate()->find($draft->id);
        if (! $fresh || ! $this->isCurrent($batch, $fresh)) {
            throw new ProductSearchCancelled;
        }
    }

    private function isCurrent(ProductPhotoBatch $batch, ProductDraft $draft): bool
    {
        return $batch->product_draft_id === $draft->id && $draft->status === 'pending_review'
            && $batch->generation === $draft->telegram_update_id
            && ProductPhotoApplicability::matchesTarget($draft, $batch->source_context['_batch_target'] ?? [])
            && $batch->policy_fingerprint === $this->policyFingerprint($draft)
            && $batch->owner_gallery_signature === $this->gallerySignature($draft);
    }

    private function policyFingerprint(ProductDraft $draft): string
    {
        return hash('sha256', json_encode(['version' => 1, 'category' => $draft->category,
            'product_type' => $draft->product_type, 'condition' => $draft->condition,
            'category_hint' => Category::query()->where('slug', $draft->category)->value('product_search_hint'),
            'photo_requirement' => ProductPhotoKeyboardContext::photoRequirement(ProductInputContext::forDraft($draft))], JSON_THROW_ON_ERROR));
    }

    private function gallerySignature(ProductDraft $draft): string
    {
        return hash('sha256', json_encode(['media' => $draft->media()->orderBy('id')->get([
            'id', 'disk', 'path', 'checksum', 'source_url', 'verification_status',
        ])->toArray(), 'excluded_sources' => $draft->excluded_gallery_source_urls,
            'excluded_images' => $draft->excluded_gallery_image_urls, 'excluded_hashes' => $draft->excluded_gallery_hashes], JSON_THROW_ON_ERROR));
    }
}
