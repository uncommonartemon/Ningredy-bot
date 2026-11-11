<?php

namespace Tests\Feature;

use App\Ai\Tools\SearchCatalog;
use App\Ai\Tools\SetProductActive;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockUnit;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\CatalogTelegramNavigation;
use App\Services\Telegram\ProductCardPresenter;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator, 2026-11-11: «можно закинуть в сон или удалить, две разные кнопки… сделай как
 * правильно для бизнеса, повторы будут однозначно». A product taken off sale sleeps with
 * everything kept; the same product coming in again wakes its card; deleting is the other button.
 */
class ProductSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_taken_off_sale_leaves_the_storefront_and_keeps_everything(): void
    {
        $variant = $this->variant(quantity: 2);
        $product = $variant->product;
        $this->assertTrue($product->isOnSale());
        $this->get(route('products.show', $product))->assertOk();

        $product->takeOffSale();

        $this->assertFalse($product->fresh()->isOnSale());
        $this->assertSame(0, Product::query()->visibleInCatalog()->count());
        $this->get(route('products.show', $product))->assertNotFound();
        // Nothing of it is gone: the configuration, its price and its two units.
        $this->assertSame([2, '1000.00', 2], [$variant->fresh()->quantity, $variant->fresh()->price, StockUnit::query()->inStock()->count()]);

        $product->fresh()->putBackOnSale();
        $this->assertTrue($product->fresh()->isOnSale());
        $this->get(route('products.show', $product))->assertOk();

        // A card that was never published goes on sale published.
        $draft = $this->variant(quantity: 1, overrides: ['status' => 'draft', 'published_at' => null])->product;
        $this->assertFalse($draft->isOnSale());
        $draft->putBackOnSale();
        $this->assertSame(['published', true], [$draft->fresh()->status, $draft->fresh()->published_at !== null]);
    }

    public function test_the_products_list_has_both_buttons_and_a_repeat_goes_back_with_its_quantity_and_price(): void
    {
        $this->admin();
        $onSale = $this->variant(quantity: 1)->product;
        $asleep = $this->variant(quantity: 0, overrides: ['is_active' => false])->product;

        $list = Livewire::test(ListProducts::class);
        $list->assertTableActionVisible('takeOffSale', $onSale)->assertTableActionHidden('putBackOnSale', $onSale)
            ->assertTableActionVisible('putBackOnSale', $asleep)->assertTableActionHidden('takeOffSale', $asleep)
            ->assertTableActionVisible('delete', $onSale)->assertTableActionVisible('delete', $asleep);

        $list->callTableAction('takeOffSale', $onSale);
        $this->assertFalse($onSale->fresh()->isOnSale());
        $this->assertSame(1, $onSale->variants()->sum('quantity'));
        // What is asleep is under its own tab.
        $list->set('activeTab', 'hidden')->assertCanSeeTableRecords([$onSale, $asleep]);
        $list->set('activeTab', 'visible')->assertCanNotSeeTableRecords([$onSale, $asleep]);
        $list->set('activeTab', 'all');

        // The same product came in again: one window puts it back with how many there are and the price.
        $list->mountTableAction('putBackOnSale', $asleep)
            ->assertTableActionDataSet(['quantity' => 1, 'stock_status' => 'in_stock'])
            ->setTableActionData(['quantity' => 2, 'price' => 4500, 'purchase_price' => 3000, 'purchased_at' => '2026-11-10', 'supplier' => 'Petr'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $variant = $asleep->variants()->sole();
        $this->assertTrue($asleep->fresh()->isOnSale());
        $this->assertSame([2, '4500.00', 'in_stock', 2], [$variant->quantity, $variant->price, $variant->stock_status,
            $variant->stockUnits()->inStock()->count()]);
        // What the two new items were bought for is in their rows, each with a number of its own.
        $arrived = $variant->stockUnits()->inStock()->orderBy('id')->get();
        $this->assertSame([['3000.00', '2026-11-10', 'Petr'], ['3000.00', '2026-11-10', 'Petr']],
            $arrived->map(fn (StockUnit $unit): array => [$unit->purchase_price, $unit->purchased_at->toDateString(), $unit->supplier])->all());
        $this->assertCount(2, $arrived->pluck('code')->unique());
        // One more comes in later at another price: only its row gets that price.
        $list->mountTableAction('editCommercial', $asleep)->setTableActionData(['quantity' => 3, 'purchase_price' => 3100])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame(['3000.00', '3000.00', '3100.00'], $variant->stockUnits()->inStock()->orderBy('id')->pluck('purchase_price')->all());

        // A selection goes to sleep and comes back at once.
        $list->callTableBulkAction('takeOffSale', [$onSale, $asleep]);
        $this->assertSame([false, false], [$onSale->fresh()->isOnSale(), $asleep->fresh()->isOnSale()]);
        $list->callTableBulkAction('putBackOnSale', [$onSale, $asleep]);
        $this->assertSame([true, true], [$onSale->fresh()->isOnSale(), $asleep->fresh()->isOnSale()]);

        // Deleting is the other button, and it says what goes and what stays.
        $list->assertTableActionExists('delete', fn (DeleteAction $action): bool => str_contains((string) $action->getModalDescription(), 'Продажи останутся в учёте')
            && str_contains((string) $action->getModalDescription(), 'лучше снять его с продажи'), $onSale);
        $list->callTableAction('delete', $onSale);
        $this->assertNull($onSale->fresh());
        $this->assertNotNull($asleep->fresh());
    }

    public function test_the_card_page_has_both_buttons_and_the_open_form_can_still_be_saved(): void
    {
        $this->admin();
        $product = $this->variant(quantity: 1)->product;

        $page = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $page->assertActionVisible('takeOffSale')->assertActionHidden('putBackOnSale')->assertActionVisible('delete');
        $page->callAction('takeOffSale');
        $this->assertFalse($product->fresh()->isOnSale());
        // The form shows what the button did, and saving it neither fails nor undoes it.
        $page->assertFormSet(['is_active' => false])->assertActionVisible('putBackOnSale')->assertActionHidden('takeOffSale');
        $page->call('save')->assertHasNoFormErrors();
        $this->assertFalse($product->fresh()->isOnSale());

        $page->callAction('putBackOnSale');
        $page->assertFormSet(['is_active' => true]);
        $page->call('save')->assertHasNoFormErrors();
        $this->assertTrue($product->fresh()->isOnSale());
    }

    public function test_telegram_shows_a_sleeping_card_as_such_with_the_button_that_wakes_it(): void
    {
        config(['services.telegram.proxy_url' => 'https://shop.example']);
        $product = $this->variant(quantity: 0, overrides: ['is_active' => false])->product;
        $navigation = app(CatalogTelegramNavigation::class);
        $buttons = fn (Product $product): array => collect($navigation->product($product)['inline_keyboard'])->flatten(1)
            ->pluck('callback_data')->filter()->values()->all();

        $this->assertSame(["product:tgedit:{$product->id}", "product:wake:{$product->id}", "product:remove:{$product->id}"], $buttons($product));
        $caption = app(ProductCardPresenter::class)->caption($product);
        $this->assertStringContainsString('💤 Снят с продажи', $caption);
        // A sleeping product has no public page, so no dead link.
        $this->assertStringNotContainsString('https://shop.example/products/', $caption);

        // The assistant's tool is the same switch, and it tells what is in stock after the return.
        $update = TelegramUpdate::query()->create(['update_id' => 880001, 'telegram_user_id' => '7', 'chat_id' => '7',
            'text' => 'верни в продажу', 'payload' => [], 'status' => 'processing']);
        $answer = json_decode((string) (new SetProductActive($update))->handle(new Request(['product_id' => $product->id, 'active' => true])), true);
        $this->assertSame([true, true, 0], [$answer['ok'], $answer['on_sale'], $answer['in_stock']]);

        $product->refresh();
        $this->assertSame(["product:tgedit:{$product->id}", "product:sleep:{$product->id}", "product:remove:{$product->id}"], $buttons($product));
        $caption = app(ProductCardPresenter::class)->caption($product);
        $this->assertStringNotContainsString('Снят с продажи', $caption);
        $this->assertStringContainsString("https://shop.example/products/{$product->slug}", $caption);
        $this->assertStringContainsString('В наличии 0 шт.', $navigation->saleNote($product));
        $product->takeOffSale();
        $this->assertStringContainsString('снят с продажи', $navigation->saleNote($product));
    }

    public function test_the_catalog_search_tells_the_assistant_that_a_found_product_sleeps(): void
    {
        $variant = $this->variant(quantity: 0, overrides: ['is_active' => false]);
        $variant->update(['mpn' => 'MZVL2512HCJQ-00B00']);
        $update = TelegramUpdate::query()->create(['update_id' => 880002, 'telegram_user_id' => '7', 'chat_id' => '7',
            'text' => 'MZVL2512HCJQ-00B00', 'payload' => [], 'status' => 'processing']);

        $found = json_decode((string) (new SearchCatalog($update))->handle(new Request(['query' => 'MZVL2512HCJQ-00B00'])), true);

        $this->assertSame(1, $found['count']);
        $this->assertSame([true, false, 0], [$found['products'][0]['exact_code_match'], $found['products'][0]['on_sale'], $found['products'][0]['in_stock']]);
    }

    private function variant(int $quantity, array $overrides = []): ProductVariant
    {
        $product = Product::query()->create(['title' => 'Samsung PM9A1 512 GB', 'category_id' => Category::query()->where('slug', 'components')->value('id'),
            'product_type' => 'component', 'status' => 'published', 'is_active' => true, 'published_at' => now(), ...$overrides]);

        return $product->variants()->create(['condition' => 'used', 'price' => 1000, 'currency' => 'CZK', 'quantity' => $quantity,
            'stock_status' => $quantity > 0 ? 'in_stock' : 'out_of_stock', 'is_default' => true, 'is_active' => true]);
    }

    private function admin(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        app()->setLocale('ru');
    }
}
