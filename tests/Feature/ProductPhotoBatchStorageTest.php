<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageVisionAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductPhotoBatch;
use App\Models\ProductPhotoFrame;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPhotoBatchStorage;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class ProductPhotoBatchStorageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://93.184.216.34/laptop-target-32';

    public function test_frames_are_on_disk_before_vision_and_each_verdict_is_committed(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(function (): array {
            // Vision is asked only about frames that already survive a dead worker.
            $this->assertSame(2, ProductPhotoFrame::query()->where('status', 'pending')->count());
            ProductPhotoFrame::query()->each(fn (ProductPhotoFrame $frame) => $this->assertTrue(Storage::disk('public')->exists($frame->path)));

            return ['images' => [$this->verdict(1, true), $this->verdict(2, false)]];
        })->preventStrayPrompts();

        $kept = $this->durablyReviewed($this->frames(2), $draft);

        $this->assertCount(1, $kept);
        $this->assertSame(self::PAGE.'/image-1.jpg', $kept[0]['source_url']);
        $this->assertSame(['accepted', 'rejected'], ProductPhotoFrame::query()->orderBy('position')->pluck('status')->all());
        $this->assertSame('reviewed', ProductPhotoBatch::query()->sole()->status);
    }

    public function test_frames_of_an_interrupted_check_resume_as_pending_draft_photos_without_another_download(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Worker lost the provider connection.'))->preventStrayPrompts();

        $this->assertNull($this->durablyReviewed($this->frames(2), $draft));
        $batch = ProductPhotoBatch::query()->sole();
        $this->assertSame('pending', $batch->status);
        $paths = $batch->frames()->pluck('path')->all();

        $storage = app(ProductPhotoBatchStorage::class);
        $this->assertSame(2, $storage->materializePending($draft->fresh(), $draft->telegram_update_id));
        $media = $draft->media()->orderBy('sort_order')->get();
        $this->assertSame($paths, $media->pluck('path')->all());
        $this->assertSame(['pending', 'pending'], $media->pluck('verification_status')->all());
        $this->assertTrue($media->first()->is_primary);
        $this->assertSame('materialized', $batch->fresh()->status);
        // A consumed batch is not handed over twice.
        $this->assertSame(0, $storage->materializePending($draft->fresh(), $draft->telegram_update_id));

        $storage->purgeStale($draft->fresh());
        $this->assertSame(0, ProductPhotoFrame::query()->count());
        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk('public')->exists($path), 'a file the draft gallery uses survives the batch cleanup');
        }
    }

    public function test_the_next_stage_finishes_a_check_lost_mid_vision_without_downloading_again(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Worker died mid-check.'))->preventStrayPrompts();
        $this->assertNull($this->durablyReviewed($this->frames(3), $draft));

        ProductImageVisionAgent::fake(fn (): array => ['images' => array_map(fn (int $i): array => $this->verdict($i, true), [1, 2, 3])])
            ->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $resolver->shouldReceive('specificationTextForPage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('sourceContextForPage')->andReturn(null)->byDefault();
        $resolver->shouldReceive('sourceContextForImage')->andReturn(null)->byDefault();
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturn(SpecificationReconciliationOutcome::reconciled(self::PAGE, 'Laptop TARGET-32', 'TARGET-32', 'black',
                'A laptop.', [['key' => 'sku', 'name' => 'SKU', 'value' => 'TARGET-32']], 'Built.'));

        $stored = app(ProductImageStorage::class)->stage($draft->fresh());

        $this->assertSame(3, $stored);
        $this->assertSame(['source_verified', 'source_verified', 'source_verified'],
            $draft->media()->orderBy('sort_order')->pluck('verification_status')->all());
        $this->assertSame('materialized', ProductPhotoBatch::query()->sole()->status);
        $this->assertSame(self::PAGE, $draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_a_batch_superseded_by_a_changed_gallery_is_purged_with_its_files(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Interrupted.'))->preventStrayPrompts();
        $this->durablyReviewed($this->frames(2), $draft);
        $paths = ProductPhotoFrame::query()->pluck('path')->all();
        Storage::disk('public')->put("drafts/{$draft->id}/primary-new.webp", 'new');
        $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/primary-new.webp",
            'source_url' => 'https://93.184.216.34/other.jpg', 'role' => 'primary', 'mime_type' => 'image/webp',
            'width' => 800, 'height' => 600, 'file_size' => 3, 'checksum' => hash('sha256', 'new'),
            'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

        app(ProductPhotoBatchStorage::class)->purgeStale($draft->fresh());

        $this->assertSame(0, ProductPhotoFrame::query()->count());
        $this->assertSame('superseded', ProductPhotoBatch::query()->sole()->status);
        foreach ($paths as $path) {
            $this->assertFalse(Storage::disk('public')->exists($path));
        }
        $this->assertSame(0, app(ProductPhotoBatchStorage::class)->materializePending($draft->fresh(), $draft->telegram_update_id));
    }

    public function test_deleting_a_draft_removes_its_saved_frame_files(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Interrupted.'))->preventStrayPrompts();
        $this->durablyReviewed($this->frames(1), $draft);
        $path = ProductPhotoFrame::query()->value('path');

        $draft->delete();

        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_already_pending_draft_photos_keep_the_ordinary_review(): void
    {
        $draft = $this->draft();
        $frames = array_map(fn (array $frame): array => [...$frame, 'media_id' => 1], $this->frames(1));

        $this->assertFalse($this->durablyReviewed($frames, $draft));
        $this->assertSame(0, ProductPhotoBatch::query()->count());
    }

    private function durablyReviewed(array $frames, ProductDraft $draft): array|null|false
    {
        return (new ReflectionMethod(ProductImageStorage::class, 'durablyReviewedFrames'))
            ->invoke(app(ProductImageStorage::class), $frames, $draft, $draft->telegram_update_id);
    }

    private function verdict(int $index, bool $publishable): array
    {
        return ['index' => $index, 'exact_match' => true, 'color_match' => true, 'publishable' => $publishable,
            'kind' => 'product', 'view' => 'front', 'gallery_rank' => $index, 'score' => 95,
            'reason' => $publishable ? 'Clean product photo.' : 'Not a publishable catalog photo.'];
    }

    private function frames(int $count): array
    {
        return array_map(fn (int $i): array => ['image' => $this->image($i), 'source_url' => self::PAGE."/image-{$i}.jpg",
            'confirmed_gallery' => true, 'source_identity_confirmed' => true,
            'page_source_context' => ['url' => self::PAGE, 'title' => 'Laptop TARGET-32']], range(1, $count));
    }

    private function image(int $seed): \GdImage
    {
        $image = imagecreatetruecolor(900, 700);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 230, 230));
        imagefilledrectangle($image, 50 + $seed * 10, 100, 800, 600, imagecolorallocate($image, 40, 40, 40 + $seed));

        return $image;
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 881910, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => 'Laptop TARGET-32 black', 'payload' => [], 'status' => 'processing']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review', 'title' => 'Laptop TARGET-32',
            'model' => 'TARGET-32', 'color' => 'black', 'product_type' => 'laptop',
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'TARGET-32']],
            'sources' => [['url' => self::PAGE, 'title' => 'Laptop TARGET-32', 'type' => 'retailer', 'image_urls' => []]],
            'primary_source_url' => self::PAGE, 'image_urls' => []]);
    }
}
