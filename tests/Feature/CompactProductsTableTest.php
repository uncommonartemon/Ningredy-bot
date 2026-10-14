<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompactProductsTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        app()->setLocale('en');
    }

    public function test_search_finds_a_non_default_sku_without_misrepresenting_the_displayed_offer(): void
    {
        $product = $this->product('Two configurations');
        $product->variants()->create($this->offer(['sku' => 'MAIN-16GB', 'price' => 100, 'currency' => 'CZK']));
        $product->variants()->create($this->offer(['sku' => 'SPECIAL-32GB', 'price' => 200, 'currency' => 'EUR', 'is_default' => false]));
        $other = $this->product('Unrelated configuration');
        $other->variants()->create($this->offer(['sku' => 'DIFFERENT-SKU']));

        Livewire::test(ListProducts::class)
            ->searchTable('SPECIAL-32GB')
            ->assertCanSeeTableRecords([$product])
            ->assertCanNotSeeTableRecords([$other])
            ->assertTableColumnStateSet('defaultVariant.sku', 'MAIN-16GB', $product)
            ->assertTableColumnStateSet('defaultVariant.price', '100.00', $product);
    }

    public function test_price_keeps_its_currency_and_distinguishes_zero_from_missing_price(): void
    {
        $paid = $this->product('Priced offer');
        $paid->variants()->create($this->offer(['price' => 1234.5, 'currency' => 'EUR']));
        $zero = $this->product('Zero price offer');
        $zero->variants()->create($this->offer(['price' => 0, 'currency' => 'CZK']));
        $unknown = $this->product('Unknown price offer');
        $unknown->variants()->create($this->offer(['price' => null, 'currency' => 'USD']));
        $legacy = $this->product('No configuration');
        $page = Livewire::test(ListProducts::class);

        foreach ([[$paid, 'EUR', '1234.50'], [$zero, 'CZK', '0.00']] as [$product, $currency, $amount]) {
            $column = $page->instance()->getTable()->getColumn('defaultVariant.price');
            $column->record($page->instance()->getTableRecord((string) $product->id));
            $column->clearCachedState();
            $formatted = (string) $column->formatState($column->getState());
            $this->assertStringContainsString($currency, $formatted);
            $this->assertStringContainsString($amount, str_replace([',', ' ', "\u{00A0}", "\u{202F}"], '', $formatted));
        }

        $page->assertTableColumnStateSet('defaultVariant.price', null, $unknown)
            ->assertTableColumnStateSet('defaultVariant.price', null, $legacy);
        $this->assertSame('—', $page->instance()->getTable()->getColumn('defaultVariant.price')->getPlaceholder());
    }

    public function test_actual_visibility_requires_both_publication_and_visibility_flag(): void
    {
        $cases = [
            ['published', true, 'visible'],
            ['published', false, 'hidden'],
            ['draft', true, 'draft'],
            ['draft', false, 'draft'],
            ['archived', true, 'archived'],
            ['archived', false, 'archived'],
        ];
        $products = [];
        foreach ($cases as [$status, $active, $visibility]) {
            $products[] = [$this->product($status.' '.($active ? 'enabled' : 'disabled'), [
                'status' => $status, 'is_active' => $active,
            ]), $visibility];
        }

        $page = Livewire::test(ListProducts::class);
        foreach ($products as [$product, $visibility]) {
            $page->assertTableColumnStateSet('visibility', $visibility, $product);
        }
    }

    public function test_compact_action_menu_keeps_view_edit_and_offer_updates_accessible(): void
    {
        $product = $this->product('Action menu product');
        $offer = $product->variants()->create($this->offer(['price' => 100, 'quantity' => 3]));

        Livewire::test(ListProducts::class)
            ->assertActionExists(TestAction::make('view')->table($product))
            ->assertActionExists(TestAction::make('edit')->table($product))
            ->assertActionExists(TestAction::make('editCommercial')->table($product))
            ->mountAction(TestAction::make('editCommercial')->table($product))
            ->fillForm(['price' => 150, 'quantity' => 4])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('150.00', $offer->fresh()->price);
        $this->assertSame(4, $offer->fresh()->quantity);
    }

    private function product(string $title, array $overrides = []): Product
    {
        return Product::create([
            'title' => $title,
            'category_id' => Category::where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'draft', 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function offer(array $overrides = []): array
    {
        return [
            'condition' => 'new', 'currency' => 'CZK', 'quantity' => 0,
            'stock_status' => 'unknown', 'is_default' => true, 'is_active' => true,
            ...$overrides,
        ];
    }
}
