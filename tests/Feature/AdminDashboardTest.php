<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Widgets\CatalogStats;
use App\Filament\Widgets\RecentSearchesWidget;
use App\Filament\Widgets\SearchesChart;
use App\Filament\Widgets\SpendChart;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Admin\DashboardNumbers;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator, 2026-11-11: the admin should look like Filament's own demo, built from its
 * native parts. The dashboard is cards with their small charts, two charts and a table.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        app()->setLocale('ru');
    }

    public function test_the_two_weeks_are_counted_day_by_day_from_the_same_rows_the_cards_show(): void
    {
        $this->travelTo('2026-11-11 15:00:00');
        $this->product('Published today', ['published_at' => now()]);
        $this->product('Published last week', ['published_at' => now()->subDays(6)]);
        $this->product('Published long ago', ['published_at' => now()->subDays(40)]);
        $this->search('With a photo', photos: 1, daysAgo: 0);
        $this->search('Approved, photos moved to the product', photos: 0, daysAgo: 2, status: 'approved');
        $this->search('Nothing found', photos: 0, daysAgo: 2);

        $numbers = app(DashboardNumbers::class)->daily();

        $this->assertCount(14, $numbers['labels']);
        $this->assertSame(['29.10', '11.11'], [$numbers['labels'][0], $numbers['labels'][13]]);
        $this->assertSame([1, 1, 2], [$numbers['published'][13], $numbers['published'][7], array_sum($numbers['published'])]);
        $this->assertSame([1, 2], [$numbers['searches'][13], $numbers['searches'][11]]);
        $this->assertSame([1, 1, 2], [$numbers['with_photos'][13], $numbers['with_photos'][11], array_sum($numbers['with_photos'])]);
        $this->assertSame(1, array_sum($numbers['without_photos']));
        $this->assertCount(14, $numbers['spend']);
    }

    public function test_the_dashboard_is_cards_charts_and_the_last_cards_without_the_account_card(): void
    {
        $this->search('With a photo', photos: 1, daysAgo: 0);
        $widgets = (new Dashboard)->getWidgets();

        $this->assertSame([CatalogStats::class, SearchesChart::class, SpendChart::class, RecentSearchesWidget::class],
            collect($widgets)->sortBy(fn (string $widget): int => $widget::getSort())->values()->all());
        $this->assertNotContains(AccountWidget::class, $widgets);
        Livewire::test(Dashboard::class)->assertOk();
        Livewire::test(CatalogStats::class)->assertOk()->assertSee('Поиски за 14 дней')->assertSee('С фото: 1 из 1 (100%)');
        Livewire::test(SearchesChart::class)->assertOk();
        Livewire::test(SpendChart::class)->assertOk();
    }

    public function test_a_title_is_capitalised_once_and_the_products_list_has_tabs_for_what_is_seen_and_in_stock(): void
    {
        $this->assertSame('Единицы склада', StockUnitResource::getTitleCasePluralModelLabel());
        $this->assertSame('Единица склада', StockUnitResource::getTitleCaseModelLabel());

        $visible = $this->product('Seen and in stock', ['published_at' => now()], quantity: 2);
        $hidden = $this->product('Hidden', ['is_active' => false], quantity: 1);
        $soldOut = $this->product('Seen and sold out', ['published_at' => now()], quantity: 0);

        $list = Livewire::test(ListProducts::class);
        $list->assertCanSeeTableRecords([$visible, $hidden, $soldOut]);
        $list->set('activeTab', 'visible')->assertCanSeeTableRecords([$visible, $soldOut])->assertCanNotSeeTableRecords([$hidden]);
        $list->set('activeTab', 'hidden')->assertCanSeeTableRecords([$hidden])->assertCanNotSeeTableRecords([$visible, $soldOut]);
        $list->set('activeTab', 'sold_out')->assertCanSeeTableRecords([$soldOut])->assertCanNotSeeTableRecords([$visible, $hidden]);
    }

    private function product(string $title, array $overrides = [], ?int $quantity = null): Product
    {
        $product = Product::query()->create(['title' => $title, 'category_id' => Category::query()->where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'published', 'is_active' => true, ...$overrides]);
        if ($quantity !== null) {
            $product->variants()->create(['condition' => 'new', 'price' => 1000, 'currency' => 'CZK', 'quantity' => $quantity,
                'stock_status' => $quantity > 0 ? 'in_stock' : 'out_of_stock', 'is_default' => true, 'is_active' => true]);
        }

        return $product;
    }

    private function search(string $title, int $photos, int $daysAgo, string $status = 'pending_review'): ProductDraft
    {
        $at = now()->subDays($daysAgo);
        $update = TelegramUpdate::query()->create(['update_id' => random_int(100000, 999999), 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => $title, 'payload' => [], 'status' => 'completed', 'processed_at' => $at]);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake', 'status' => 'completed',
            'prompt' => 'test', 'started_at' => $at, 'completed_at' => $at]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'title' => $title, 'product_type' => 'component', 'category' => 'components',
            'status' => $status, 'specifications' => [], 'sources' => [], 'image_urls' => []]);
        $draft->forceFill(['created_at' => $at])->saveQuietly();
        for ($photo = 0; $photo < $photos; $photo++) {
            $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/{$photo}.webp", 'source_url' => "https://img.example/{$photo}.jpg",
                'checksum' => "c{$photo}", 'verification_status' => 'verified', 'is_primary' => $photo === 0]);
        }

        return $draft;
    }
}
