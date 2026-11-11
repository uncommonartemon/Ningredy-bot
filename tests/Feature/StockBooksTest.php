<?php

namespace Tests\Feature;

use App\Filament\Pages\Accounting;
use App\Filament\Resources\StockUnits\Pages\ListStockUnits;
use App\Filament\Widgets\Accounting\BooksChart;
use App\Filament\Widgets\Accounting\BooksStats;
use App\Filament\Widgets\Accounting\RecentSales;
use App\Filament\Widgets\CatalogStats;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Catalog\StockBooks;
use App\Services\Catalog\StockUnits;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator, 2026-11-11: an accountant's page with widgets. The numbers come from the units:
 * what is on the shelf and what it cost, what the last month bought, sold and earned.
 */
class StockBooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_books_count_the_shelf_the_month_and_the_profit_from_the_units(): void
    {
        $this->travelTo('2026-11-11 12:00:00');
        $drives = $this->variant(quantity: 3, price: 2000);
        [$priced, $unpriced, $sold] = $drives->stockUnits()->orderBy('id')->get()->all();
        $priced->update(['purchase_price' => 1200, 'purchased_at' => '2026-11-01']);
        $sold->update(['purchase_price' => 1000, 'purchased_at' => '2026-08-20']);
        app(StockUnits::class)->sell($sold, '1900', '2026-11-05 10:00:00');
        // Sold without a purchase price: money came in, but nobody knows what was earned.
        $loose = $this->variant(quantity: 1, price: 500)->stockUnits()->sole();
        app(StockUnits::class)->sell($loose, '450', '2026-11-06 10:00:00');
        // Another currency is another set of books.
        $this->variant(quantity: 1, price: 90, currency: 'EUR');

        $books = app(StockBooks::class)->summary();

        $this->assertSame('CZK', $books['currency']);
        $this->assertSame(1, $books['other_currencies']);
        $this->assertSame([2, 1], [$books['in_stock'], $books['unpriced']]);
        $this->assertSame(1200.0, $books['invested']);
        $this->assertSame(4000.0, $books['shelf_value']);
        $this->assertSame(800.0, $books['expected_profit']);
        $this->assertSame([1200.0, 1], [$books['bought'], $books['bought_count']]);
        $this->assertSame([2350.0, 2], [$books['sold'], $books['sold_count']]);
        $this->assertSame([900.0, 1], [$books['profit'], $books['sold_unpriced']]);

        $months = app(StockBooks::class)->monthly();
        $this->assertCount(12, $months['labels']);
        $this->assertSame('11.2026', end($months['labels']));
        $this->assertSame(1200.0, end($months['bought']));
        $this->assertSame(1000.0, $months['bought'][array_search('08.2026', $months['labels'], true)]);
        $this->assertSame(2350.0, end($months['sold']));
        $this->assertSame(900.0, end($months['profit']));
        $this->assertSame(0.0, $months['sold'][0]);
    }

    public function test_the_accounting_page_shows_the_books_and_the_units_table_corrects_them(): void
    {
        $this->admin();
        $variant = $this->variant(quantity: 2, price: 2000);
        [$first, $second] = $variant->stockUnits()->orderBy('id')->get()->all();

        Livewire::test(Accounting::class)->assertOk();
        Livewire::test(BooksStats::class)->assertOk()->assertSee('В наличии, шт.')->assertSee('Без цены закупки: 2');
        Livewire::test(BooksChart::class)->assertOk();
        // The books stay on their own page: the main dashboard keeps its own widgets.
        $this->assertSame([BooksStats::class, BooksChart::class, RecentSales::class], (new Accounting)->getWidgets());
        Livewire::test(RecentSales::class)->assertOk();
        $this->assertContains(CatalogStats::class, (new Dashboard)->getWidgets());
        $this->assertNotContains(BooksStats::class, (new Dashboard)->getWidgets());

        $table = Livewire::test(ListStockUnits::class)->assertSet('activeTab', 'in_stock')->assertCanSeeTableRecords([$first, $second]);
        $table->callTableAction('edit', $first, ['purchase_price' => 1200, 'purchased_at' => '2026-11-01', 'supplier' => 'Petr'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(['1200.00', 'Petr'], [$first->fresh()->purchase_price, $first->fresh()->supplier]);

        $table->callTableAction('sell', $first, ['sold_price' => 1900, 'sold_at' => now()->toDateTimeString()])->assertHasNoTableActionErrors();
        $this->assertSame([StockUnit::SOLD, '1900.00', 1], [$first->fresh()->status, $first->fresh()->sold_price, $variant->fresh()->quantity]);
        $table->callTableAction('writeOff', $second);
        $this->assertSame([StockUnit::WRITTEN_OFF, 0], [$second->fresh()->status, $variant->fresh()->quantity]);
        // A sold unit is brought back from the tab it went to.
        $table->set('activeTab', 'sold')->callTableAction('restore', $first);
        $table->set('activeTab', 'in_stock');
        $this->assertSame([StockUnit::IN_STOCK, 1], [$first->fresh()->status, $variant->fresh()->quantity]);
        // A written-off unit left the stock's tab and is under its own.
        $table->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        $table->set('activeTab', 'written_off')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        $table->set('activeTab', 'all')->filterTable('unpriced')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);

        $download = Livewire::test(ListStockUnits::class)->callAction('download');
        $download->assertFileDownloaded('stock-units-'.now()->format('Y-m-d').'.csv');
    }

    private function variant(int $quantity, int $price, string $currency = 'CZK'): ProductVariant
    {
        $product = Product::query()->create(['title' => 'Samsung PM9A1 512 GB', 'category_id' => Category::query()->where('slug', 'laptops')->value('id'),
            'product_type' => 'component', 'status' => 'published', 'is_active' => true, 'published_at' => now()]);

        return $product->variants()->create(['condition' => 'used', 'price' => $price, 'currency' => $currency, 'quantity' => $quantity,
            'stock_status' => 'in_stock', 'is_default' => true, 'is_active' => true]);
    }

    private function admin(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }
}
