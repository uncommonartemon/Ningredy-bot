<?php

namespace Tests\Unit;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDraftReconciliationStampTest extends TestCase
{
    use RefreshDatabase;

    private function seedReconciledDraft(): ProductDraft
    {
        $updateId = TelegramUpdate::query()->create([
            'update_id' => random_int(1_000_000, 9_000_000),
            'telegram_user_id' => '12345',
            'chat_id' => '12345',
            'text' => 'test',
            'payload' => ['update_id' => 1],
            'status' => 'received',
        ])->id;
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'completed',
            'prompt' => 'test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return ProductDraft::query()->create([
            'telegram_update_id' => $updateId,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Test Laptop',
            'model' => 'Test Laptop',
            'description' => 'The 16 GB configuration.',
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
            'sources' => [],
            'image_urls' => [],
            'primary_source_url' => 'https://shop.example/product',
            'specifications_reconciled_source_url' => 'https://shop.example/product',
            'specifications_reconciliation_attempts' => 0,
        ]);
    }

    public function test_editing_a_confirmed_field_without_the_reconciliation_write_invalidates_the_stamp(): void
    {
        // Point 4 of the follow-up audit: a matching source_url alone does
        // not mean the values it was confirmed against are still the ones
        // on the draft - a manual edit (Filament, a hint) must not leave a
        // stale confirmation standing.
        $draft = $this->seedReconciledDraft();

        $draft->update(['description' => 'Now describing something else entirely.']);

        $draft->refresh();
        $this->assertNull($draft->specifications_reconciled_source_url);
        $this->assertSame(0, $draft->specifications_reconciliation_attempts);
    }

    public function test_editing_specifications_without_the_reconciliation_write_invalidates_the_stamp(): void
    {
        $draft = $this->seedReconciledDraft();

        $draft->update(['specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB']]]);

        $draft->refresh();
        $this->assertNull($draft->specifications_reconciled_source_url);
    }

    public function test_reconciling_against_a_new_source_in_the_same_call_does_not_invalidate_itself(): void
    {
        // reconciliationCardFields()'s own successful write sets the
        // confirmed fields AND specifications_reconciled_source_url
        // together - that specific save must not immediately undo itself.
        // The url genuinely changes here, so isDirty on that column alone
        // already tells the two apart, no wrapper needed.
        $draft = $this->seedReconciledDraft();

        $draft->update([
            'title' => 'Corrected Title',
            'model' => 'Corrected Model',
            'color' => null,
            'description' => 'Corrected description.',
            'specifications' => [],
            'specifications_reconciled_source_url' => 'https://shop.example/new-product',
            'specifications_reconciliation_attempts' => 0,
        ]);

        $draft->refresh();
        $this->assertSame('https://shop.example/new-product', $draft->specifications_reconciled_source_url);
    }

    public function test_reconfirming_the_same_source_relies_on_the_reconciliation_write_marker(): void
    {
        // Re-confirming the SAME url leaves that column's value unchanged,
        // so isDirty on it alone cannot tell this apart from an unrelated
        // edit - only ProductImageStorage's own withReconciliationWrite()
        // marker can, and must actually be used at those call sites.
        $draft = $this->seedReconciledDraft();

        ProductDraft::withReconciliationWrite(fn () => $draft->update([
            'title' => 'Corrected Title',
            'specifications' => [],
            'specifications_reconciled_source_url' => 'https://shop.example/product',
            'specifications_reconciliation_attempts' => 0,
        ]));

        $draft->refresh();
        $this->assertSame('https://shop.example/product', $draft->specifications_reconciled_source_url);
        $this->assertSame('Corrected Title', $draft->title);
    }

    public function test_updating_an_unrelated_field_does_not_touch_the_stamp(): void
    {
        $draft = $this->seedReconciledDraft();

        $draft->update(['gallery_notes' => 'Просто заметка.']);

        $draft->refresh();
        $this->assertSame('https://shop.example/product', $draft->specifications_reconciled_source_url);
    }
}
