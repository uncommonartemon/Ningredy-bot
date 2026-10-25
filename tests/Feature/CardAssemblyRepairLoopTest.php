<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardAssemblyRepairLoopTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://shop.example/nitro-16';

    public function test_the_same_errors_again_end_the_repairs_even_when_the_attempt_read_new_pages(): void
    {
        // Live #180: the card failed four times running on the same color_gamut quotation;
        // each attempt had re-read the page, and that reading counted as progress.
        $draft = $this->draft();
        $failure = fn (): SpecificationReconciliationOutcome => SpecificationReconciliationOutcome::unavailable(
            'invalid_evidence', true, 'Invalid specification evidence: color_gamut: source excerpt was not observed at '.self::SOURCE,
            ['failure_kind' => 'invalid_evidence', 'validation_errors' => ['color_gamut: source excerpt was not observed at '.self::SOURCE]]);
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->times(2)->andReturnUsing($failure);

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);

        $this->assertFalse($outcome->isReconciled());
        $this->assertSame(2, ProductSourceAttempt::query()->where('product_draft_id', $draft->id)->where('action', 'reconcile_card')->count());
    }

    public function test_repairs_go_on_while_each_attempt_changes_what_is_wrong(): void
    {
        $draft = $this->draft();
        $errors = [['cpu: source excerpt was not observed at '.self::SOURCE], ['display: confirmed but changed; account for the correction explicitly.'],
            ['cpu: source excerpt was not observed at '.self::SOURCE]];
        $attempt = 0;
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->times(3)
            ->andReturnUsing(function () use (&$attempt, $errors): SpecificationReconciliationOutcome {
                $current = $errors[$attempt++];

                return SpecificationReconciliationOutcome::unavailable('invalid_evidence', true, 'Invalid specification evidence: '.$current[0],
                    ['failure_kind' => 'invalid_evidence', 'validation_errors' => $current]);
            });

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);

        // A, B, then A again: the third attempt repeats the first and ends the loop.
        $this->assertFalse($outcome->isReconciled());
        $this->assertSame(3, $attempt);
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 985, 'payload' => [], 'text' => 'Acer Nitro 16 AN16-43 black', 'telegram_user_id' => '1', 'chat_id' => '1', 'status' => 'processing']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'research', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'Acer Nitro 16 AN16-43', 'model' => 'AN16-43', 'brand' => 'Acer', 'condition' => 'new',
            'description' => 'Research description.', 'primary_source_url' => self::SOURCE, 'image_urls' => [],
            'specifications' => [['key' => 'color_gamut', 'name' => 'Colour gamut', 'value' => '100% sRGB']], 'sources' => [],
        ]);
    }
}
