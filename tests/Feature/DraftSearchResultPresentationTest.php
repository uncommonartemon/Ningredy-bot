<?php

namespace Tests\Feature;

use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\ProductDrafts\Pages\ListProductDrafts;
use App\Filament\Resources\TelegramUpdates\Pages\ListTelegramUpdates;
use App\Filament\Resources\TelegramUpdates\Pages\ViewTelegramUpdate;
use App\Filament\Support\DraftSearchResult;
use App\Filament\Widgets\RecentSearchesWidget;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class DraftSearchResultPresentationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_draft_lists_distinguish_partial_empty_unreconciled_and_unverified_cards(): void
    {
        $cases = [
            [$this->draft(['gallery_status' => 'complete'], photos: 3), 'complete'],
            [$this->draft(['gallery_status' => 'partial'], photos: 1), 'partial'],
            [$this->draft(['gallery_status' => 'complete'], photos: 0), 'no_photos'],
            [$this->draft(['gallery_status' => 'complete'], photos: 3, verification: 'pending'), 'pending_verification'],
            [$this->draft(['gallery_status' => 'complete', 'specifications_reconciled_source_url' => null], photos: 3), 'unreconciled'],
            [$this->draft(['gallery_status' => 'complete', 'specifications_reconciled_source_url' => null], photos: 0), 'unreconciled'],
            [$this->draft(['gallery_status' => 'ready'], photos: 2, manualStatus: 'needs_input'), 'needs_input'],
            [$this->draft(['gallery_status' => 'ready'], photos: 2, manualStatus: 'ready'), 'manual'],
            [$this->draft(['status' => 'approved'], photos: 0), 'approved'],
            [$this->draft(['status' => 'rejected'], photos: 0), 'rejected'],
        ];

        foreach ([ListProductDrafts::class, RecentSearchesWidget::class] as $component) {
            $page = Livewire::test($component);
            $column = $page->instance()->getTable()->getColumn('search_result');
            foreach ($cases as [$draft, $expected]) {
                $page->assertTableColumnStateSet('search_result', $expected, $draft);
                if (! in_array($expected, ['complete', 'approved'], true)) {
                    $this->assertNotSame('success', $column->getColor($expected));
                }
            }
            $this->assertStringContainsString('Не подтверждает доставку', $column->getTooltip());
        }
    }

    public function test_summary_uses_eager_loaded_counts_and_manual_metadata_without_row_queries(): void
    {
        $this->draft(['gallery_status' => 'partial'], photos: 1);
        $this->draft(['gallery_status' => 'ready'], photos: 1, manualStatus: 'needs_input');
        $this->draft(['gallery_status' => 'complete'], photos: 3, verification: 'pending');
        $drafts = DraftSearchResult::withSummary(ProductDraft::query())->orderBy('id')->get();

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame(['partial', 'needs_input', 'pending_verification'], $drafts->map(DraftSearchResult::state(...))->all());
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_completed_ai_call_is_neutral_and_does_not_claim_a_finished_search(): void
    {
        $draft = $this->draft(['specifications_reconciled_source_url' => null], photos: 0);
        $page = Livewire::test(ListAiRuns::class);
        $page->assertTableColumnStateSet('status', 'completed', $draft->aiRun);
        $column = $page->instance()->getTable()->getColumn('status');
        $column->record($draft->aiRun);
        $column->clearCachedState();
        $this->assertSame('gray', $column->getColor('completed'));
        $this->assertStringContainsString('не итог поиска', $column->getTooltip());
    }

    public function test_reply_delivery_has_a_neutral_label_and_its_own_telegram_update_filter(): void
    {
        $draft = $this->draft(photos: 0);
        $delivery = $draft->telegramUpdate;
        $delivery->update(['status' => 'delivering', 'processed_at' => null]);
        $processed = TelegramUpdate::create(['update_id' => 900001, 'telegram_user_id' => '42', 'chat_id' => '900',
            'message_id' => 900001, 'text' => 'Earlier reply', 'status' => 'processed', 'payload' => [], 'processed_at' => now()]);

        $page = Livewire::test(ListTelegramUpdates::class)->filterTable('status', 'delivering')
            ->assertCanSeeTableRecords([$delivery])->assertCanNotSeeTableRecords([$processed])
            ->assertTableColumnFormattedStateSet('status', __('Отправляется ответ'), $delivery);
        $this->assertSame('gray', $page->instance()->getTable()->getColumn('status')->getColor('delivering'));

        Livewire::test(ViewTelegramUpdate::class, ['record' => $delivery->id])
            ->assertSee(__('Отправляется ответ'));
    }

    private function draft(array $overrides = [], int $photos = 0, string $verification = 'verified', ?string $manualStatus = null): ProductDraft
    {
        $number = ++$this->sequence;
        $update = TelegramUpdate::create(['update_id' => 700000 + $number, 'telegram_user_id' => '42',
            'chat_id' => '900', 'message_id' => $number, 'text' => 'Card '.$number, 'status' => 'completed',
            'payload' => $manualStatus === null ? [] : [
                '_manual_product_upload' => true, '_manual_product_intake' => ['status' => $manualStatus],
            ]]);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test-fixture',
            'status' => 'completed', 'prompt' => 'Card '.$number, 'started_at' => now(), 'completed_at' => now()]);
        $url = 'https://source.example/product-'.$number;
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '42', 'title' => 'Card '.$number, 'status' => 'pending_review',
            'description' => 'Saved card', 'specifications' => [], 'sources' => [], 'image_urls' => [],
            'primary_source_url' => $url, 'specifications_reconciled_source_url' => $url, 'images_staged_at' => now(),
            ...$overrides]);
        for ($i = 0; $i < $photos; $i++) {
            $draft->media()->create(['disk' => 'public', 'path' => 'test-'.$number.'-'.$i.'.webp', 'source_url' => $url,
                'checksum' => hash('sha256', $number.':'.$i),
                'width' => 1200, 'height' => 900, 'verification_status' => $verification]);
        }

        return $draft;
    }
}
