<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Catalog\StockUnits;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator, 2026-11-11: three near-identical used drives are three units, each with a number
 * of its own, a purchase price and date and a note; the rows follow the quantity.
 */
class StockUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_quantity_and_the_units_in_stock_are_always_the_same_number(): void
    {
        $variant = $this->variant(quantity: 3);
        $units = $variant->stockUnits()->orderBy('id')->get();
        $this->assertCount(3, $units);
        $this->assertSame(['U-'.str_pad((string) $units[0]->id, 6, '0', STR_PAD_LEFT), StockUnit::IN_STOCK], [$units[0]->code, $units[0]->status]);
        $this->assertCount(3, $units->pluck('code')->unique());

        $variant->update(['quantity' => 5]);
        $this->assertSame(5, $variant->stockUnits()->inStock()->count());

        // Fewer items: the ones nobody described go first, and go without a trace.
        $described = $units[1];
        $described->update(['purchase_price' => 900, 'purchased_at' => '2026-05-12', 'supplier' => 'Petr']);
        $variant->update(['quantity' => 1]);
        $this->assertSame([$described->id], $variant->stockUnits()->pluck('id')->all());

        // A described item that leaves is taken as sold, at the price of that moment.
        $variant->update(['quantity' => 0, 'price' => 1500]);
        $sold = $described->fresh();
        $this->assertSame([StockUnit::SOLD, '1500.00'], [$sold->status, $sold->sold_price]);
        $this->assertNotNull($sold->sold_at);
        $this->assertSame(0, $variant->stockUnits()->inStock()->count());
        // A change that is not about the quantity leaves the units alone.
        $variant->update(['price' => 1600]);
        $this->assertSame('1500.00', $sold->fresh()->sold_price);
    }

    public function test_a_unit_that_leaves_or_returns_moves_the_quantity(): void
    {
        $variant = $this->variant(quantity: 3, price: 2000);
        [$first, $second, $third] = $variant->stockUnits()->orderBy('id')->get()->all();
        $books = app(StockUnits::class);

        $books->sell($first, '1800', '2026-11-10 12:00:00');
        $this->assertSame(2, $variant->fresh()->quantity);
        $this->assertSame(['1800.00', '2026-11-10'], [$first->fresh()->sold_price, $first->fresh()->sold_at->toDateString()]);
        $books->sell($second);
        $this->assertSame('2000.00', $second->fresh()->sold_price);
        $books->writeOff($third);
        $this->assertSame(0, $variant->fresh()->quantity);
        $this->assertNull($third->fresh()->sold_price);

        $books->restore($first);
        $this->assertSame(1, $variant->fresh()->quantity);
        $this->assertSame([StockUnit::IN_STOCK, null, null], [$first->fresh()->status, $first->fresh()->sold_at, $first->fresh()->sold_price]);
        $first->delete();
        $this->assertSame(0, $variant->fresh()->quantity);
    }

    public function test_the_product_form_has_a_row_per_unit_and_the_rows_follow_the_quantity(): void
    {
        $this->admin();
        $variant = $this->variant(quantity: 2, price: 1000);
        [$first, $second] = $variant->stockUnits()->orderBy('id')->get()->all();

        $page = Livewire::test(EditProduct::class, ['record' => $variant->product_id]);
        $key = array_key_first($page->get('data.variants'));
        $this->assertSame([$first->id, $second->id], array_column($page->get("data.variants.$key.stock_units"), 'id'));
        // The form gives its rows keys of its own; a row is found by the unit it carries.
        $rowOf = fn ($page, StockUnit $unit): string => (string) collect($page->get("data.variants.$key.stock_units"))
            ->search(fn (array $row): bool => ($row['id'] ?? null) === $unit->id);
        $this->assertSame($first->code, $page->get("data.variants.$key.stock_units.".$rowOf($page, $first).'.code'));

        // One more item: one more row, a new unit once saved, with what was written in it.
        $page->set("data.variants.$key.quantity", 3);
        $rows = $page->get("data.variants.$key.stock_units");
        $this->assertCount(3, $rows);
        $new = array_key_last($rows);
        $page->set("data.variants.$key.stock_units.".$rowOf($page, $first).'.purchase_price', '900')
            ->set("data.variants.$key.stock_units.".$rowOf($page, $first).'.purchased_at', '2026-05-12')
            ->set("data.variants.$key.stock_units.$new.serial_number", 'S02PA406523469D9BA')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(3, $variant->fresh()->quantity);
        $this->assertSame(['900.00', '2026-05-12'], [$first->fresh()->purchase_price, $first->fresh()->purchased_at->toDateString()]);
        $added = $variant->stockUnits()->where('serial_number', 'S02PA406523469D9BA')->sole();
        $this->assertNotNull($added->code);

        // A row taken away is that item out of the stock: the quantity follows, the described one is sold.
        $page = Livewire::test(EditProduct::class, ['record' => $variant->product_id]);
        $key = array_key_first($page->get('data.variants'));
        $page->callAction(TestAction::make('delete')->schemaComponent("variants.$key.stock_units")->arguments(['item' => $rowOf($page, $first)]));
        $this->assertSame(2, $page->get("data.variants.$key.quantity"));
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $variant->fresh()->quantity);
        $this->assertSame([StockUnit::SOLD, '1000.00'], [$first->fresh()->status, $first->fresh()->sold_price]);
        $this->assertSame(2, $variant->stockUnits()->inStock()->count());
    }

    public function test_what_was_in_stock_before_the_books_becomes_units_and_the_chat_can_write_them(): void
    {
        $variant = $this->variant(quantity: 2);
        Schema::drop('stock_units');
        (require database_path('migrations/2026_11_11_110000_create_stock_units.php'))->up();
        $this->assertSame(2, DB::table('stock_units')->where('product_variant_id', $variant->id)->where('status', 'in_stock')->count());
        $this->assertSame(2, DB::table('stock_units')->whereNotNull('code')->distinct()->count('code'));

        $unit = $variant->stockUnits()->orderBy('id')->firstOrFail();
        $operator = app(DatabaseOperatorService::class);
        $operator->execute($operator->prepare([['action' => 'update', 'table' => 'stock_units', 'selection' => ['ids' => [$unit->id]],
            'values' => ['purchase_price' => 900, 'supplier' => 'Petr', 'status' => 'sold', 'sold_price' => 1400]]]));
        $this->assertSame(['900.00', 'Petr', StockUnit::SOLD, '1400.00'],
            [$unit->fresh()->purchase_price, $unit->fresh()->supplier, $unit->fresh()->status, $unit->fresh()->sold_price]);
        $this->assertSame(1, $variant->fresh()->quantity);
    }

    private function variant(int $quantity, int $price = 1000): ProductVariant
    {
        $product = Product::query()->create(['title' => 'Samsung PM9A1 512 GB', 'category_id' => Category::query()->where('slug', 'laptops')->value('id'),
            'product_type' => 'component', 'status' => 'published', 'is_active' => true, 'published_at' => now()]);

        return $product->variants()->create(['condition' => 'used', 'price' => $price, 'currency' => 'CZK', 'quantity' => $quantity,
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
