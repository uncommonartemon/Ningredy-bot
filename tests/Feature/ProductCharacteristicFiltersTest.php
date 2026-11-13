<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Support\ProductTablePreferences;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductAttributeFilters;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision of 2026-11-12: the admin's product list is filtered by characteristics as the
 * storefront is, in a block above the list, with the characteristics of the chosen category.
 */
class ProductCharacteristicFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_category_offers_its_own_characteristics_with_the_values_its_products_carry(): void
    {
        $this->catalog();
        $filters = app(ProductAttributeFilters::class);

        $laptops = $filters->forCategory($this->category('laptops'));
        $this->assertArrayHasKey('ram', $laptops);
        $this->assertArrayNotHasKey('interface', $laptops);
        $this->assertSame(['16 gb' => '16 GB', '32 gb' => '32 GB'], $laptops['ram']['options']);
        // A category with kinds under it offers theirs too: «Комплектующие» brings the SSD's interface.
        $this->assertSame(['nvme' => 'NVMe'], $filters->forCategory($this->category('components'))['interface']['options']);
        // Nothing is offered without a category: dozens of characteristics of every kind would make no list.
        $this->assertSame([], $filters->forCategory(null));
        $this->assertSame('16 GB', $filters->optionName('ram', '16 gb'));
    }

    public function test_the_list_is_filtered_by_a_characteristic_hidden_products_included_and_the_choice_is_remembered(): void
    {
        $user = $this->admin();
        ['big' => $big, 'small' => $small, 'hiddenBig' => $hiddenBig, 'ssd' => $ssd] = $this->catalog();

        $page = Livewire::test(ListProducts::class)
            ->set('tableDeferredFilters.category_id.value', (string) $this->category('laptops'))
            ->set('tableDeferredFilters.characteristics.ram', ['32 gb'])
            ->call('applyTableFilters');

        // The admin works with every product: one taken off sale is found as well.
        $page->assertCanSeeTableRecords([$big, $hiddenBig])->assertCanNotSeeTableRecords([$small, $ssd])
            ->assertSee('Оперативная память: 32 GB');
        $this->assertSame(['32 gb'], $user->fresh()->product_table_filters['characteristics']['ram']);

        // Another category: other fields, and nothing from the previous one stays chosen.
        $page->set('tableDeferredFilters.category_id.value', (string) $this->category('components'))
            ->assertSet('tableDeferredFilters.characteristics', [])
            ->call('applyTableFilters')
            ->assertCanSeeTableRecords([$ssd])->assertCanNotSeeTableRecords([$big, $small]);
        $this->assertArrayNotHasKey('characteristics', $user->fresh()->product_table_filters);
    }

    public function test_a_saved_characteristic_without_its_category_is_not_kept(): void
    {
        $this->catalog();
        $normalized = ProductTablePreferences::normalize(['characteristics' => ['ram' => ['32 gb']]]);

        $this->assertArrayNotHasKey('characteristics', $normalized);
    }

    /** @return array<string, Product> */
    private function catalog(): array
    {
        $ram = AttributeDefinition::query()->updateOrCreate(['key' => 'ram'], ['label' => 'Оперативная память', 'data_type' => 'text', 'is_filterable' => true]);
        $interface = AttributeDefinition::query()->updateOrCreate(['key' => 'interface'], ['label' => 'Интерфейс', 'data_type' => 'text', 'is_filterable' => true]);
        $product = function (string $title, string $category, ?AttributeDefinition $definition, ?string $value, bool $onSale = true): Product {
            $product = Product::query()->create(['title' => $title, 'category_id' => $this->category($category), 'product_type' => 'laptop',
                'status' => 'published', 'is_active' => $onSale, 'published_at' => now()]);
            $product->variants()->create(['condition' => 'new', 'price' => 1000, 'currency' => 'CZK', 'quantity' => 1,
                'stock_status' => 'in_stock', 'is_default' => true, 'is_active' => true]);
            if ($definition !== null) {
                AttributeValue::query()->create(['attribute_definition_id' => $definition->id, 'product_id' => $product->id, 'value' => $value]);
            }

            return $product;
        };

        return [
            'big' => $product('Laptop 32', 'laptops', $ram, '32 GB'),
            'small' => $product('Laptop 16', 'laptops', $ram, '16 GB'),
            'other' => $product('Laptop 16 again', 'laptops', $ram, '16 GB'),
            'hiddenBig' => $product('Laptop 32 asleep', 'laptops', $ram, '32 GB', onSale: false),
            'ssd' => $product('Drive', 'ssd', $interface, 'NVMe'),
        ];
    }

    private function category(string $slug): int
    {
        return (int) Category::query()->where('slug', $slug)->value('id');
    }

    private function admin(): User
    {
        config(['services.admin.name' => 'ningredy']);
        $user = User::factory()->create(['name' => 'ningredy', 'is_admin' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        app()->setLocale('ru');

        return $user;
    }
}
