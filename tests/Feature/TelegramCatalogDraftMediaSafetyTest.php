<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramCatalogDraftMedia;
use App\Services\Telegram\TelegramCatalogEditor;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelegramCatalogDraftMediaSafetyTest extends TestCase
{
    // The second regression must execute a real outer commit before its
    // simulated callback failure; RefreshDatabase's wrapping rollback hides it.
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true]);
        Storage::fake('public');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_failed_copy_rolls_back_without_deleting_reviewed_or_other_variant_files(): void
    {
        [$product, $variant, $draft, $original, $other, $global] = $this->fixtures();
        $disk = Storage::disk('public');
        $faultDisk = Mockery::mock($disk);
        $faultDisk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($faultDisk);
        $draftPath = $draft->media()->first()->path;

        try {
            app(TelegramCatalogDraftMedia::class)->commit($product, $variant, $draft);
            $this->fail('A failed filesystem write was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('сохранить', $exception->getMessage());
        }

        foreach ([$original, $other, $global] as $media) {
            $this->assertDatabaseHas('product_media', ['id' => $media->id, 'path' => $media->path]);
            $disk->assertExists($media->path);
        }
        $disk->assertExists($draftPath);
        $this->assertSame(1, $draft->media()->count());
        $this->assertSame(3, $product->media()->count());
        $this->assertNull(data_get($draft->telegramUpdate->fresh()->payload, '_manual_product_intake.media_committed_at'));
    }

    public function test_exception_after_database_commit_keeps_copies_referenced_by_committed_rows(): void
    {
        [$product, $variant, $draft, $original, $other, $global] = $this->fixtures();
        Event::listen('eloquent.deleted: '.ProductDraftMedia::class, function (): void {
            DB::afterCommit(function (): void {
                throw new RuntimeException('Simulated after-commit queue failure');
            });
        });

        try {
            app(TelegramCatalogDraftMedia::class)->commit($product, $variant, $draft);
            $this->fail('The simulated after-commit fault did not execute.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated after-commit queue failure', $exception->getMessage());
        }

        $committed = $product->media()->where('product_variant_id', $variant->id)->firstOrFail();
        $this->assertNotSame($original->id, $committed->id);
        Storage::disk('public')->assertExists($committed->path);
        $this->assertDatabaseMissing('product_media', ['id' => $original->id]);
        foreach ([$other, $global] as $media) {
            $this->assertDatabaseHas('product_media', ['id' => $media->id, 'path' => $media->path]);
            Storage::disk('public')->assertExists($media->path);
        }
        $this->assertSame(0, $draft->media()->count());
        $this->assertNotNull(data_get($draft->telegramUpdate->fresh()->payload, '_manual_product_intake.media_committed_at'));
    }

    public function test_same_checksum_document_is_not_adopted_as_the_reviewed_product_image(): void
    {
        [$product, $variant, $draft, $original, $other, $global] = $this->fixtures();
        $frame = $draft->media()->firstOrFail();
        $path = 'products/'.$product->id.'/attachment.jpg';
        Storage::disk('public')->put($path, Storage::disk($frame->disk)->get($frame->path));
        $document = $product->media()->create(['product_variant_id' => $variant->id, 'type' => 'document',
            'disk' => 'public', 'path' => $path, 'checksum' => $frame->checksum,
            'verification_status' => 'manual', 'sort_order' => 8, 'is_primary' => false]);

        $this->assertSame(1, app(TelegramCatalogDraftMedia::class)->commit($product, $variant, $draft));

        $this->assertDatabaseHas('product_media', ['id' => $document->id, 'type' => 'document', 'path' => $path,
            'sort_order' => 8, 'is_primary' => false]);
        $this->assertDatabaseHas('product_media', ['product_variant_id' => $variant->id, 'type' => 'image',
            'checksum' => $frame->checksum, 'is_primary' => true]);
        $this->assertDatabaseMissing('product_media', ['id' => $original->id]);
        foreach ([$document, $other, $global] as $media) {
            Storage::disk('public')->assertExists($media->path);
        }
    }

    public function test_changing_reviewed_file_bytes_after_approval_preserves_the_catalog_gallery(): void
    {
        [$product, $variant, $draft, $original, $other, $global] = $this->fixtures();
        $frame = $draft->media()->firstOrFail();
        Storage::disk($frame->disk)->put($frame->path, $this->jpeg(210));

        try {
            app(TelegramCatalogDraftMedia::class)->commit($product, $variant, $draft);
            $this->fail('An image replaced after approval was published.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('после подтверждения', $exception->getMessage());
        }

        foreach ([$original, $other, $global] as $media) {
            $this->assertDatabaseHas('product_media', ['id' => $media->id, 'path' => $media->path]);
            Storage::disk('public')->assertExists($media->path);
        }
        $this->assertSame(1, $draft->media()->count());
        Storage::disk('public')->assertExists($frame->path);
        $this->assertSame(3, $product->media()->count());
    }

    public function test_queued_media_commit_cannot_run_after_draft_approval_is_revoked(): void
    {
        [$product, $variant, $draft, $original] = $this->fixtures();
        $draft->update(['status' => 'rejected']);

        try {
            app(TelegramCatalogDraftMedia::class)->commit($product, $variant, $draft);
            $this->fail('A rejected draft was committed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('не подтверждена', $exception->getMessage());
        }
        $this->assertDatabaseHas('product_media', ['id' => $original->id]);
        $this->assertSame(3, $product->media()->count());
        $this->assertSame(1, $draft->media()->count());
    }

    public function test_gallery_cannot_be_committed_to_another_variant_of_the_same_product(): void
    {
        [$product, $variant, $draft, $original, $other] = $this->fixtures();

        try {
            app(TelegramCatalogDraftMedia::class)->commit($product, $other->variant, $draft);
            $this->fail('The reviewed gallery was committed to a different variant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('не подтверждена', $exception->getMessage());
        }
        foreach ([$original, $other] as $media) {
            $this->assertDatabaseHas('product_media', ['id' => $media->id, 'product_variant_id' => $media->product_variant_id]);
        }
        $this->assertSame(3, $product->media()->count());
        $this->assertSame(1, $draft->media()->count());
    }

    private function fixtures(): array
    {
        $product = Product::query()->create(['title' => 'Sample laptop', 'model' => 'Sample 14',
            'category_id' => Category::where('slug', 'laptops')->value('id'), 'product_type' => 'laptop',
            'status' => 'published', 'is_active' => true]);
        $variant = $product->variants()->create(['name' => 'Selected', 'condition' => 'used', 'is_default' => true, 'currency' => 'CZK']);
        $another = $product->variants()->create(['name' => 'Other', 'condition' => 'new', 'currency' => 'CZK']);
        $media = [];
        foreach ([[$variant->id, 20], [$another->id, 80], [null, 130]] as [$variantId, $shade]) {
            $bytes = $this->jpeg($shade);
            $path = 'products/'.$product->id.'/original-'.$shade.'.jpg';
            Storage::disk('public')->put($path, $bytes);
            $media[] = $product->media()->create(['product_variant_id' => $variantId, 'type' => 'image',
                'disk' => 'public', 'path' => $path, 'mime_type' => 'image/jpeg', 'width' => 320, 'height' => 240,
                'checksum' => hash('sha256', $bytes), 'verification_status' => 'manual', 'sort_order' => count($media),
                'is_primary' => count($media) === 0]);
        }
        $intake = ['origin' => 'catalog_edit', 'status' => 'ready', 'target_product_id' => $product->id,
            'target_variant_id' => $variant->id,
            'approved_catalog_fingerprint' => app(TelegramCatalogEditor::class)->fingerprint($product, $variant)];
        $update = TelegramUpdate::query()->create(['update_id' => 997001, 'chat_id' => 'chat-1',
            'telegram_user_id' => 'user-1', 'status' => 'completed', 'processed_at' => now(),
            'payload' => ['_manual_product_upload' => true, '_manual_product_intake' => $intake]]);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'local',
            'model' => 'test_fixture', 'status' => 'completed', 'prompt' => 'Local test fixture; no provider call.',
            'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => 'user-1', 'title' => $product->title, 'model' => $product->model,
            'condition' => 'used', 'category' => 'laptops', 'product_type' => 'laptop', 'status' => 'approved',
            'approved_product_id' => $product->id, 'approved_variant_id' => $variant->id,
            'specifications' => [], 'specification_evidence' => [], 'sources' => [], 'image_urls' => []]);
        $bytes = $this->jpeg(200);
        $path = 'product-drafts/'.$draft->id.'/reviewed.jpg';
        Storage::disk('public')->put($path, $bytes);
        $draft->media()->create(['disk' => 'public', 'path' => $path, 'mime_type' => 'image/jpeg',
            'width' => 320, 'height' => 240, 'checksum' => hash('sha256', $bytes), 'verification_status' => 'manual',
            'sort_order' => 0, 'is_primary' => true, 'role' => 'primary']);
        $payload = $update->payload;
        $payload['_manual_product_intake']['approved_draft_media_fingerprint'] = app(TelegramCatalogDraftMedia::class)->galleryFingerprint($draft);
        $update->update(['payload' => $payload]);

        return [$product, $variant, $draft, ...$media];
    }

    private function jpeg(int $shade): string
    {
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, $shade, 40, 50));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
