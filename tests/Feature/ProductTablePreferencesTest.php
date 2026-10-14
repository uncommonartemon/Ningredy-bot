<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTablePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_applied_filters_survive_logout_and_a_new_session_and_filter_the_records(): void
    {
        $user = $this->admin();
        $published = $this->product('Published laptop', 'published');
        $draft = $this->product('Draft laptop', 'draft');

        Livewire::test(ListProducts::class)
            ->set('tableDeferredFilters.status.value', 'draft')
            ->call('applyTableFilters')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$published]);
        $this->assertSame(['status' => ['value' => 'draft']], $user->fresh()->product_table_filters);

        $this->app['auth']->guard()->logout();
        session()->invalidate();
        $this->actingAs($user->fresh());

        Livewire::test(ListProducts::class)
            ->assertSet('tableFilters.status.value', 'draft')
            ->assertSet('tableDeferredFilters.status.value', 'draft')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$published]);
    }

    public function test_unapplied_changes_are_not_saved_or_used_on_return(): void
    {
        $user = $this->admin();
        Livewire::test(ListProducts::class)->filterTable('status', 'published');

        Livewire::test(ListProducts::class)->set('tableDeferredFilters.status.value', 'archived')
            ->assertSet('tableFilters.status.value', 'published');

        $this->assertSame(['status' => ['value' => 'published']], $user->fresh()->product_table_filters);
        Livewire::test(ListProducts::class)->assertSet('tableDeferredFilters.status.value', 'published');
    }

    public function test_preferences_belong_to_the_authenticated_user_even_in_the_same_session(): void
    {
        $first = $this->admin();
        $page = Livewire::test(ListProducts::class)->filterTable('status', 'draft');
        session()->put($page->instance()->getTableFiltersSessionKey(), ['status' => ['value' => 'archived']]);
        session()->put('other-table-filters', ['keep' => true]);

        $second = $this->admin('second-operator');
        Livewire::test(ListProducts::class)->assertSet('tableFilters.status.value', null)
            ->filterTable('status', 'published');

        $this->assertSame(['status' => ['value' => 'draft']], $first->fresh()->product_table_filters);
        $this->assertSame(['status' => ['value' => 'published']], $second->fresh()->product_table_filters);
        $this->assertSame(['keep' => true], session()->get('other-table-filters'));

        config(['services.admin.name' => $first->name]);
        $this->actingAs($first->fresh());
        Livewire::test(ListProducts::class)->assertSet('tableFilters.status.value', 'draft');
    }

    public function test_explicit_url_filters_override_saved_filters_without_merging_them(): void
    {
        $user = $this->admin();
        Livewire::test(ListProducts::class)->filterTable('status', 'draft')->filterTable('product_type', 'laptop');

        Livewire::withQueryParams(['filters' => ['status' => ['value' => 'archived']]])
            ->test(ListProducts::class)
            ->assertSet('tableFilters.status.value', 'archived')
            ->assertSet('tableFilters.product_type.value', null);

        $this->assertSame(['status' => ['value' => 'archived']], $user->fresh()->product_table_filters);
    }

    public function test_explicit_empty_url_filters_clear_preferences(): void
    {
        $user = $this->admin();
        Livewire::test(ListProducts::class)->filterTable('status', 'draft');

        Livewire::withQueryParams(['filters' => []])->test(ListProducts::class)
            ->assertSet('tableFilters.status.value', null);
        $this->assertSame([], $user->fresh()->product_table_filters);
    }

    public function test_removing_one_chip_preserves_the_others_and_reset_stays_reset(): void
    {
        $user = $this->admin();
        $page = Livewire::test(ListProducts::class)
            ->filterTable('status', 'draft')->filterTable('is_active', false)
            ->removeTableFilter('status');

        $this->assertSame(['is_active' => ['value' => false]], $user->fresh()->product_table_filters);
        Livewire::test(ListProducts::class)->assertSet('tableFilters.is_active.value', false);

        $page->resetTableFilters();
        $this->assertSame([], $user->fresh()->product_table_filters);
        session()->flush();
        Livewire::test(ListProducts::class)->assertSet('tableFilters.status.value', null)
            ->assertSet('tableFilters.is_active.value', null);
    }

    public function test_all_filters_restore_and_untrusted_or_stale_values_are_discarded(): void
    {
        $user = $this->admin();
        $other = User::factory()->create();
        $category = Category::where('slug', 'laptops')->sole();
        $brand = Brand::create(['name' => 'Filter brand']);
        $filters = [
            'status' => ['value' => 'published'], 'product_type' => ['value' => 'laptop'],
            'category_id' => ['value' => (string) $category->id], 'brand_id' => ['value' => (string) $brand->id],
            'is_active' => ['value' => true], 'is_featured' => ['value' => false],
        ];
        Livewire::test(ListProducts::class)->set('tableDeferredFilters', $filters + [
            'user_id' => $other->id, 'is_admin' => ['value' => true],
        ])->call('applyTableFilters');
        $this->assertSame($filters, $user->fresh()->product_table_filters);
        $this->assertNull($other->fresh()->product_table_filters);
        $this->assertFalse($other->fresh()->is_admin);

        session()->flush();
        $page = Livewire::test(ListProducts::class);
        foreach ($filters as $name => $state) {
            $page->assertSet("tableFilters.{$name}.value", $state['value']);
        }

        $brand->delete();
        Livewire::withQueryParams(['filters' => [
            'status' => ['value' => 'not-a-status'], 'product_type' => ['value' => ['laptop']],
            'category_id' => ['value' => '-1'], 'brand_id' => ['value' => $brand->id],
            'is_active' => ['value' => 'false'], 'is_featured' => ['value' => 'invalid'],
        ]])->test(ListProducts::class)->assertSet('tableFilters.brand_id.value', null)
            ->assertSet('tableFilters.status.value', null)->assertSet('tableFilters.is_active.value', false);
        $this->assertSame(['is_active' => ['value' => false]], $user->fresh()->product_table_filters);
    }

    public function test_explicit_url_changes_do_not_reset_search_or_permissions(): void
    {
        $this->admin();
        Livewire::withQueryParams(['search' => 'Needle', 'filters' => ['status' => ['value' => 'draft']]])
            ->test(ListProducts::class)->assertSet('tableSearch', 'Needle')
            ->assertSet('tableFilters.status.value', 'draft');

        $regular = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regular)->get('/admin/products')->assertForbidden();
        $this->assertNull($regular->fresh()->product_table_filters);
    }

    private function admin(string $name = 'ningredy'): User
    {
        config(['services.admin.name' => $name]);
        $user = User::factory()->create(['name' => $name, 'is_admin' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        return $user;
    }

    private function product(string $title, string $status): Product
    {
        return Product::create([
            'title' => $title, 'status' => $status, 'product_type' => 'laptop',
            'category_id' => Category::where('slug', 'laptops')->sole()->id,
        ]);
    }
}
