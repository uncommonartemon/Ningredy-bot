<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageVisionAgent;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductGalleryRecoveryPreservationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PAGE = 'https://93.184.216.36/products/original';

    private const NEW_PAGE = 'https://93.184.216.34/products/recovery';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', 'false');
        AppSetting::put('ai.card_assembly_fallback_model', null);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1, 'exact_match' => true, 'color_match' => true,
                'publishable' => true, 'kind' => 'product', 'view' => 'front',
                'gallery_rank' => $index + 1, 'score' => 98, 'reason' => 'Exact product.',
            ])->all(),
        ])->preventStrayPrompts();
        Http::fake(function (Request $request) {
            if ($request->url() === self::NEW_PAGE) {
                return Http::response('<html><title>Laptop EXACT-1</title></html>', 200, ['Content-Type' => 'text/html']);
            }
            preg_match('/photo-(\d+)/', $request->url(), $match);

            return Http::response($this->jpeg((int) ($match[1] ?? 1)), 200, ['Content-Type' => 'image/jpeg']);
        });
    }

    #[DataProvider('failedRecoveryCases')]
    public function test_failed_recovery_keeps_seven_verified_photos_and_their_source(string $failure, int $candidateCount): void
    {
        $draft = $this->draft($candidateCount);
        $snapshot = $draft->fresh()->toArray();
        $mediaSnapshot = $draft->media()->get()->toArray();
        $budgetSpent = false;
        // Use a reference: exhaustion happens during assembly, not before the search.
        $this->partialMock(ProductSearchCostBudget::class)->shouldReceive('exceeded')
            ->andReturnUsing(function () use (&$budgetSpent): bool {
                return $budgetSpent;
            });
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(function (ProductDraft $current, array $source) use ($draft, $failure, &$budgetSpent) {
                $this->assertSame(self::NEW_PAGE, $source['url']);
                // The new gallery must never be mixed into existing records while AI works.
                $this->assertSame(7, $draft->media()->count());
                $this->assertSame(self::OLD_PAGE, $current->primary_source_url);
                $budgetSpent = true;
                if ($failure === 'exception') {
                    throw new \RuntimeException('assembly transport failed');
                }

                return SpecificationReconciliationOutcome::notReady('Codes remain unconfirmed.', repairContext: [
                    'recovery_action' => 'try_other_source',
                ]);
            });
        if ($candidateCount > 1) {
            $disk = Storage::disk('public');
            $replacement = \Mockery::mock($disk);
            $calls = 0;
            $replacement->shouldReceive('put')->andReturnUsing(function ($path, $contents) use ($disk, &$calls): bool {
                return ++$calls === 1 && $disk->put($path, $contents);
            });
            Storage::shouldReceive('disk')->with('public')->andReturn($replacement);
        }

        try {
            $stored = $this->recover($draft);
            $this->assertNotSame('exception', $failure);
            $this->assertSame(7, $stored);
        } catch (\RuntimeException $exception) {
            if ($failure !== 'exception') {
                throw $exception;
            }
            $this->assertSame('exception', $failure);
            $this->assertSame('assembly transport failed', $exception->getMessage());
        }

        $fresh = $draft->fresh();
        $this->assertSame($mediaSnapshot, $fresh->media()->get()->toArray());
        foreach (['primary_source_url', 'image_urls', 'gallery_status', 'gallery_confirmed_sufficient',
            'gallery_notes', 'specifications', 'specification_evidence', 'title', 'description',
            'specifications_reconciled_source_url', 'specifications_reconciliation_attempts'] as $field) {
            $this->assertSame($snapshot[$field], $fresh->toArray()[$field], $field);
        }
        $this->assertCount(7, Storage::disk('public')->allFiles('drafts/'.$draft->id));
        foreach ($fresh->media as $media) {
            Storage::disk('public')->assertExists($media->path);
        }
        if ($failure !== 'exception') {
            $this->assertSame('cost_budget', $fresh->gallery_search_stop_reason);
            $attempt = ProductSourceAttempt::where('action', 'retain_previous_gallery')->firstOrFail();
            $this->assertSame(7, $attempt->output['saved_file_count']);
            $this->assertSame(1, $attempt->output['candidate_file_count']);
        }
    }

    public static function failedRecoveryCases(): array
    {
        return [
            'one photo and failed card, request 387' => ['not_ready', 1],
            'eight candidates but only one written file' => ['not_ready', 8],
            'assembly throws before replacing the gallery' => ['exception', 1],
        ];
    }

    public function test_a_smaller_verified_gallery_can_replace_the_reserve_when_its_card_is_reconciled(): void
    {
        $draft = $this->draft(1);
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturn(SpecificationReconciliationOutcome::alreadyReconciled(self::NEW_PAGE));

        $this->assertSame(1, $this->recover($draft));
        $this->assertCount(1, $draft->fresh()->media);
        $this->assertSame(self::NEW_PAGE, $draft->fresh()->primary_source_url);
        $this->assertSame(self::NEW_PAGE, $draft->fresh()->specifications_reconciled_source_url);
        $this->assertSame('partial', $draft->fresh()->gallery_status);
        $this->assertFalse($draft->fresh()->reconciliationPending());
        $this->assertSame(0, ProductSourceAttempt::where('action', 'retain_previous_gallery')->count());
    }

    public function test_pending_or_rejected_or_missing_old_frames_are_not_a_verified_reserve(): void
    {
        $draft = $this->draft(1);
        $method = new \ReflectionMethod(ProductImageStorage::class, 'retainedGalleryOutranks');
        $storage = app(ProductImageStorage::class);
        $candidate = [['verification_status' => 'verified']];
        $this->assertTrue($method->invoke($storage, $draft, $draft->media()->get(), $candidate, true));
        $draft->update(['gallery_confirmed_sufficient' => false]);
        $this->assertTrue($method->invoke($storage, $draft, $draft->media()->get(), $candidate, false),
            'Seven checked partial frames outrank one complete frame while both cards remain unresolved.');
        $this->assertTrue($method->invoke($storage, $draft, $draft->media()->get(),
            array_fill(0, 9, ['verification_status' => 'pending']), false));

        $draft->media()->first()->update(['verification_status' => 'pending']);
        $this->assertFalse($method->invoke($storage, $draft, $draft->media()->get(), $candidate, true));
        $draft->media()->first()->update(['verification_status' => 'source_verified']);
        $draft->update(['excluded_gallery_source_urls' => [self::OLD_PAGE]]);
        $this->assertFalse($method->invoke($storage, $draft, $draft->media()->get(), $candidate, true));
        $draft->update(['excluded_gallery_source_urls' => []]);
        Storage::disk('public')->delete($draft->media()->first()->path);
        $this->assertFalse($method->invoke($storage, $draft, $draft->media()->get(), $candidate, true));
    }

    public function test_first_gallery_survives_assembly_exception_with_matching_source_metadata(): void
    {
        $draft = $this->draft(1);
        $draft->media()->delete();
        Storage::disk('public')->delete(Storage::disk('public')->allFiles('drafts/'.$draft->id));
        $draft->update(['image_urls' => [], 'gallery_confirmed_sufficient' => false]);
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andThrow(new \RuntimeException('assembly transport failed'));

        try {
            $this->recover($draft);
            $this->fail('The technical exception must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('assembly transport failed', $exception->getMessage());
        }
        $fresh = $draft->fresh();
        $this->assertCount(1, $fresh->media);
        $this->assertSame(self::NEW_PAGE, $fresh->primary_source_url);
        $this->assertSame($fresh->media->pluck('source_url')->all(), $fresh->image_urls);
        $this->assertNull($fresh->specifications_reconciled_source_url);
        $this->assertSame('partial', $fresh->gallery_status);
        $this->assertTrue($fresh->reconciliationPending());
        Storage::disk('public')->assertExists($fresh->media->first()->path);
    }

    public function test_media_and_source_roll_back_together_if_final_metadata_write_fails(): void
    {
        $draft = $this->draft(1);
        $mediaSnapshot = $draft->media()->get()->toArray();
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturn(SpecificationReconciliationOutcome::alreadyReconciled(self::NEW_PAGE));
        ProductDraft::updating(function (ProductDraft $record): void {
            if ($record->primary_source_url === self::NEW_PAGE) {
                throw new \RuntimeException('metadata write failed');
            }
        });

        try {
            $this->recover($draft);
            $this->fail('The write exception must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('metadata write failed', $exception->getMessage());
        }
        $this->assertSame($mediaSnapshot, $draft->fresh()->media()->get()->toArray());
        $this->assertSame(self::OLD_PAGE, $draft->fresh()->primary_source_url);
        $this->assertCount(7, Storage::disk('public')->allFiles('drafts/'.$draft->id));
    }

    public function test_new_evidence_invalidation_is_not_undone_when_the_old_gallery_is_kept(): void
    {
        $draft = $this->draft(1);
        ProductDraft::withReconciliationWrite(fn () => $draft->update([
            'specifications_reconciled_source_url' => self::OLD_PAGE,
            'gallery_search_stop_reason' => null,
        ]));
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(function (ProductDraft $current): SpecificationReconciliationOutcome {
                $current->fresh()->update(['specification_evidence' => ['new evidence requiring review']]);

                return SpecificationReconciliationOutcome::notReady('Needs review.', repairContext: [
                    'recovery_action' => 'try_other_source',
                ]);
            });

        $this->assertSame(7, $this->recover($draft));
        $this->assertSame(self::OLD_PAGE, $draft->fresh()->primary_source_url);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
        $this->assertTrue($draft->fresh()->reconciliationPending());
    }

    private function recover(ProductDraft $draft): int
    {
        // Enter the same second cycle as stage() after its first card assembly failed.
        return (new \ReflectionMethod(ProductImageStorage::class, 'stageCycle'))->invoke(
            app(ProductImageStorage::class), $draft->fresh(), null, $draft->telegram_update_id,
            [], [self::OLD_PAGE], true,
        );
    }

    private function draft(int $candidateCount): ProductDraft
    {
        $update = TelegramUpdate::create([
            'update_id' => 9900387, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'processing', 'text' => 'Laptop EXACT-1',
        ]);
        $run = AiRun::create([
            'telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now(),
        ]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'status' => 'pending_review', 'title' => 'Laptop EXACT-1', 'model' => 'EXACT-1',
            'product_type' => 'laptop', 'description' => 'Original researched description.',
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'EXACT-1']],
            'specification_evidence' => ['sku' => ['source_url' => self::OLD_PAGE]],
            'primary_source_url' => self::OLD_PAGE, 'gallery_status' => 'partial',
            'gallery_confirmed_sufficient' => true, 'gallery_notes' => 'Seven checked photos; codes need evidence.',
            'gallery_search_stop_reason' => 'specifications_unreconciled',
            'image_urls' => array_map(fn ($i) => 'https://93.184.216.36/old-'.$i.'.jpg', range(1, 7)),
            'sources' => [[
                'url' => self::NEW_PAGE, 'title' => 'Laptop EXACT-1', 'type' => 'retailer',
                'image_urls' => array_map(fn ($i) => 'https://93.184.216.34/photo-'.$i.'.jpg', range(1, $candidateCount)),
            ]],
        ]);
        foreach ($draft->image_urls as $index => $url) {
            $path = 'drafts/'.$draft->id.'/old-'.$index.'.webp';
            Storage::disk('public')->put($path, 'original file '.$index);
            $draft->media()->create([
                'disk' => 'public', 'path' => $path, 'source_url' => $url, 'checksum' => hash('sha256', 'old'.$index),
                'verification_status' => 'source_verified', 'verification_notes' => 'Confirmed source and pixels.',
                'gallery_traversal_complete' => true, 'sort_order' => $index, 'is_primary' => $index === 0,
            ]);
        }

        return $draft;
    }

    private function jpeg(int $seed): string
    {
        $image = imagecreatetruecolor(720, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        for ($index = 0; $index < 18; $index++) {
            $color = imagecolorallocate($image, ($index * 47 + $seed * 31) % 255,
                ($index * 83 + $seed * 17) % 255, ($index * 29 + $seed * 61) % 255);
            imagefilledrectangle($image, ($index * 113 + $seed * 57) % 640,
                ($index * 117 + $seed * 91) % 500, ($index * 113 + $seed * 57) % 640 + 80,
                ($index * 117 + $seed * 91) % 500 + 80, $color);
        }
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
