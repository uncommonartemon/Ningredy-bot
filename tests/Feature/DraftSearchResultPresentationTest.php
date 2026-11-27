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
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductImageStorage;
use App\Services\Support\DiagnosticReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

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

    public function test_an_approved_draft_shows_the_photos_its_product_received_and_a_neutral_stop_reason(): void
    {
        // Live report 2026-11-27, draft 160 (request 687): nine G.SKILL photos from the maker's
        // page, approved with draft:add. Approval moved the files to the product, so the drafts
        // list, the start page and the report all said 0 photos, next to «Источники исчерпаны».
        Storage::fake('public');
        Queue::fake();
        $source = 'https://www.gskill.com/product/165/396/1765260340/F5-6000J3038F16GH2-FX5';
        $draft = $this->draft(['title' => 'G.SKILL Flare X5 32GB (2 × 16GB) DDR5-6000 CL30 Memory Kit — F5-6000J3038F16GH2-FX5',
            'brand' => 'G.SKILL', 'model' => 'F5-6000J3038F16GH2-FX5', 'color' => 'Black', 'product_type' => 'component', 'category' => 'ram',
            'condition' => 'new', 'confidence' => 0.9, 'gallery_status' => 'partial', 'gallery_search_stop_reason' => 'exhausted',
            'primary_source_url' => $source, 'specifications_reconciled_source_url' => $source]);
        foreach (range(1, 9) as $n) {
            $image = imagecreatetruecolor(800, 800);
            imagefill($image, 0, 0, imagecolorallocate($image, $n * 20, 40, 60));
            ob_start();
            imagewebp($image);
            $bytes = (string) ob_get_clean();
            Storage::disk('public')->put("drafts/{$draft->id}/{$n}.webp", $bytes);
            $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/{$n}.webp", 'source_url' => $source.'/'.$n.'.webp',
                'role' => $n === 1 ? 'primary' : 'detail', 'mime_type' => 'image/webp', 'width' => 800, 'height' => 800, 'file_size' => strlen($bytes),
                'checksum' => hash('sha256', $bytes), 'verification_status' => 'verified', 'sort_order' => $n - 1, 'is_primary' => $n === 1]);
        }
        $this->assertSame('Источники исчерпаны', DraftSearchResult::stopReason($this->draft(['gallery_search_stop_reason' => 'exhausted'])),
            'with no photo at all the stop reason still reads as the end of the sources');

        $product = app(ProductDraftWorkflow::class)->approve($draft->fresh());
        $draft->refresh();
        // What the queued StoreProductImages does for a draft whose photos were staged.
        app(ProductImageStorage::class)->adoptStaged($product, ProductVariant::query()->findOrFail($draft->approved_variant_id), $draft);
        $this->assertSame(0, $draft->media()->count(), 'approval moves the files to the product');

        foreach ([ListProductDrafts::class, RecentSearchesWidget::class] as $component) {
            Livewire::test($component)->assertTableColumnStateSet('photos', 'в товаре #'.$product->id.': 9', $draft);
        }
        Livewire::test(ListProductDrafts::class)
            ->assertTableColumnFormattedStateSet('gallery_search_stop_reason', 'Все найденные источники просмотрены', $draft);

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $path = app(DiagnosticReport::class)->build(days: 7);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertStringContainsString('photos 9 (moved to product #'.$product->id.' on approval)',
            (string) $zip->getFromName("requests/{$draft->telegram_update_id}.txt"));
        $row = collect(explode("\n", (string) $zip->getFromName('drafts.jsonl')))->filter()->map(fn (string $line): array => json_decode($line, true))
            ->firstWhere('id', $draft->id);
        $this->assertSame('product #'.$product->id, $row['photos_in']);
        $this->assertCount(9, $row['photos']);
        $zip->close();
        @unlink($path);
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
