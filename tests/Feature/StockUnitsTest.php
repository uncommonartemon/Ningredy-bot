<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\StockUnits\Pages\ListStockUnits;
use App\Filament\Widgets\Accounting\RecentSales;
use App\Models\AiOperation;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockUnit;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Catalog\StockBooks;
use App\Services\Catalog\StockUnits;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Telegram\TelegramCatalogDeletion;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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
        (require database_path('migrations/2026_11_11_120000_stock_units_outlive_their_product.php'))->up();
        $this->assertSame(2, DB::table('stock_units')->where('product_variant_id', $variant->id)->where('status', 'in_stock')->count());
        // Units that were there before they carried a name get their product's.
        $this->assertSame(2, DB::table('stock_units')->where('product_title', 'Samsung PM9A1 512 GB')->where('currency', 'CZK')->count());
        $this->assertSame(2, DB::table('stock_units')->whereNotNull('code')->distinct()->count('code'));

        $unit = $variant->stockUnits()->orderBy('id')->firstOrFail();
        $operator = app(DatabaseOperatorService::class);
        $operator->execute($operator->prepare([['action' => 'update', 'table' => 'stock_units', 'selection' => ['ids' => [$unit->id]],
            'values' => ['purchase_price' => 900, 'supplier' => 'Petr', 'status' => 'sold', 'sold_price' => 1400]]]));
        $this->assertSame(['900.00', 'Petr', StockUnit::SOLD, '1400.00'],
            [$unit->fresh()->purchase_price, $unit->fresh()->supplier, $unit->fresh()->status, $unit->fresh()->sold_price]);
        $this->assertSame(1, $variant->fresh()->quantity);
        // A sale marked without a date is today's: without one the month's books would not count it
        // (seen with the real model, 2026-11-11: «отметь U-000002 проданной за 1400»).
        $this->assertSame(now()->toDateString(), $unit->fresh()->sold_at?->toDateString());
        // Sold with nothing said about the price is sold at the configuration's price.
        $other = $variant->stockUnits()->inStock()->firstOrFail();
        $operator->execute($operator->prepare([['action' => 'update', 'table' => 'stock_units', 'selection' => ['ids' => [$other->id]],
            'values' => ['status' => 'sold']]]));
        $this->assertSame(['1000.00', true], [$other->fresh()->sold_price, $other->fresh()->sold_at !== null]);
        // Back in stock from the chat, the sale is forgotten.
        $operator->execute($operator->prepare([['action' => 'update', 'table' => 'stock_units', 'selection' => ['ids' => [$other->id]],
            'values' => ['status' => 'in_stock']]]));
        $this->assertSame([StockUnit::IN_STOCK, null, null], [$other->fresh()->status, $other->fresh()->sold_at, $other->fresh()->sold_price]);
    }

    public function test_what_the_books_know_stays_when_its_product_is_renamed_and_then_deleted(): void
    {
        $this->travelTo('2026-11-11 12:00:00');
        $variant = $this->variant(quantity: 4, price: 2000);
        [$sold, $described, $blank, $writtenOff] = $variant->stockUnits()->orderBy('id')->get()->all();
        // A unit is called as its product from the first day.
        $this->assertSame(['Samsung PM9A1 512 GB', 'CZK'], [$sold->product_title, $sold->currency]);
        $sold->update(['purchase_price' => 1000, 'purchased_at' => '2026-10-01']);
        $books = app(StockUnits::class);
        $books->sell($sold, '1900', '2026-11-05 10:00:00');
        $described->update(['purchase_price' => 1100, 'supplier' => 'Petr']);
        $books->writeOff($writtenOff);

        $variant->product->update(['title' => 'Samsung PM9A1 512 GB NVMe']);
        $variant->update(['currency' => 'EUR']);
        $this->assertSame(['Samsung PM9A1 512 GB NVMe', 'EUR'], [$sold->fresh()->product_title, $sold->fresh()->currency]);

        $variant->product->delete();

        $this->assertNull(ProductVariant::query()->find($variant->id));
        // What was sold stays as it was, only without the link.
        $this->assertSame([StockUnit::SOLD, '1900.00', null, 'Samsung PM9A1 512 GB NVMe', 'EUR'], [$sold->fresh()->status,
            $sold->fresh()->sold_price, $sold->fresh()->product_variant_id, $sold->fresh()->product_title, $sold->fresh()->currency]);
        $this->assertSame([StockUnit::WRITTEN_OFF, null], [$writtenOff->fresh()->status, $writtenOff->fresh()->product_variant_id]);
        // A described item in stock left the catalog without a sale: written off. A row that only stood for the quantity is gone.
        $this->assertSame([StockUnit::WRITTEN_OFF, null, '1100.00'],
            [$described->fresh()->status, $described->fresh()->sold_price, $described->fresh()->purchase_price]);
        $this->assertNull($blank->fresh());

        // The month's sale is still in the books, in the unit's own currency.
        $summary = app(StockBooks::class)->summary();
        $this->assertSame(['EUR', 1900.0, 1, 900.0, 0], [$summary['currency'], $summary['sold'], $summary['sold_count'], $summary['profit'], $summary['in_stock']]);

        // Such a unit has no shelf to return to: neither a button nor the chat puts it back.
        $books->restore($sold->fresh());
        $this->assertSame(StockUnit::SOLD, $sold->fresh()->status);
        $operator = app(DatabaseOperatorService::class);
        try {
            $operator->execute($operator->prepare([['action' => 'update', 'table' => 'stock_units', 'selection' => ['ids' => [$sold->id]],
                'values' => ['status' => 'in_stock']]]));
            $this->fail('A unit without a product came back to stock.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('удалён из каталога', $exception->getMessage());
        }
        // What the chat may still do with it: correct the sale.
        $operator->execute($operator->prepare([['action' => 'update', 'table' => 'stock_units', 'selection' => ['ids' => [$sold->id]],
            'values' => ['sold_price' => 1850]]]));
        $this->assertSame('1850.00', $sold->fresh()->sold_price);
    }

    public function test_one_configuration_deleted_alone_leaves_its_sales_and_the_lists_show_them(): void
    {
        $this->admin();
        $kept = $this->variant(quantity: 1, price: 1000);
        $leaving = $kept->product->variants()->create(['condition' => 'new', 'price' => 3000, 'currency' => 'CZK', 'quantity' => 2,
            'stock_status' => 'in_stock', 'is_default' => false, 'is_active' => true]);
        [$sold, $blank] = $leaving->stockUnits()->orderBy('id')->get()->all();
        app(StockUnits::class)->sell($sold, '2900', now());

        $leaving->delete();

        $this->assertSame([StockUnit::SOLD, null], [$sold->fresh()->status, $sold->fresh()->product_variant_id]);
        $this->assertNull($blank->fresh());
        $this->assertSame(1, $kept->fresh()->quantity);
        $this->assertModelExists($kept->product);

        Livewire::test(RecentSales::class)->assertOk()->assertSee('Samsung PM9A1 512 GB')->assertSee($sold->code);
        $list = Livewire::test(ListStockUnits::class)->set('activeTab', 'sold');
        $list->assertCanSeeTableRecords([$sold->fresh()])->assertSee('Товар удалён из каталога')
            ->assertTableActionHidden('restore', $sold->fresh());
        // A unit of a product that is still there can come back.
        $staying = $kept->stockUnits()->sole();
        app(StockUnits::class)->sell($staying, '950', now());
        Livewire::test(ListStockUnits::class)->set('activeTab', 'sold')->assertTableActionVisible('restore', $staying->fresh());
    }

    public function test_deleting_a_product_in_telegram_says_what_stays_in_the_books(): void
    {
        $variant = $this->variant(quantity: 2, price: 1000);
        app(StockUnits::class)->sell($variant->stockUnits()->orderBy('id')->firstOrFail(), '950', now());
        $this->assertSame(1, app(StockUnits::class)->keptAfter($variant->product));

        $update = TelegramUpdate::query()->create(['update_id' => 770001, 'telegram_user_id' => '7', 'chat_id' => '7',
            'text' => 'delete', 'payload' => [], 'status' => 'processing']);
        $deletion = app(TelegramCatalogDeletion::class);
        $operation = AiOperation::query()->create(['idempotency_key' => 'delete-with-books', 'telegram_update_id' => $update->id,
            'telegram_user_id' => '7', 'tool' => 'PrepareProductDeletion', 'action' => 'delete_product', 'target_type' => Product::class,
            'target_id' => $variant->product_id, 'status' => 'awaiting_confirmation', 'payload' => [
                'catalog_fingerprint' => $deletion->fingerprint($variant->product), 'expires_at' => now()->addMinutes(10)->getTimestamp()]]);

        $answer = $deletion->resolve($operation->id, 'confirm', $update);

        $this->assertStringContainsString('удалён навсегда', $answer['message']);
        $this->assertStringContainsString('В учёте остались его единицы: 1', $answer['message']);
        $this->assertSame(1, StockUnit::query()->whereNull('product_variant_id')->where('status', StockUnit::SOLD)->count());
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
