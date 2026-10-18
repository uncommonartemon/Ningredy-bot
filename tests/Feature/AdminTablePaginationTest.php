<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeGroups\AttributeGroupResource;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\MediaRelationManager;
use App\Filament\Widgets\RecentSearchesWidget;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTablePaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_every_resource_list_uses_native_all_records_and_groups_stay_unpaginated(): void
    {
        $lists = 0;

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            foreach ($resource::getPages() as $registration) {
                $pageClass = $registration->getPage();

                if (! is_subclass_of($pageClass, ListRecords::class)) {
                    continue;
                }

                $page = Livewire::test($pageClass)->assertSuccessful();
                $table = $page->instance()->getTable();
                $this->assertSame('all', $table->getDefaultPaginationPageOption(), $pageClass);
                $this->assertSame([25, 50, 100, 'all'], $table->getPaginationPageOptions(), $pageClass);
                $this->assertSame($resource !== AttributeGroupResource::class, $table->isPaginated(), $pageClass);
                $lists++;
            }
        }

        $this->assertGreaterThan(10, $lists);
    }

    public function test_all_products_show_despite_old_page_size_while_saved_filters_keep_working(): void
    {
        $products = collect();
        $category = Category::where('slug', 'laptops')->sole();
        for ($i = 0; $i < 61; $i++) {
            $products->push(Product::create(['title' => 'Draft laptop '.$i, 'category_id' => $category->id,
                'status' => 'draft', 'product_type' => 'laptop']));
        }
        $published = Product::create(['title' => 'Published laptop', 'category_id' => $category->id,
            'status' => 'published', 'product_type' => 'laptop']);

        $page = Livewire::test(ListProducts::class)->filterTable('status', 'draft');
        $key = $page->instance()->getTablePerPageSessionKey();
        session()->put($key, 15);

        $page = Livewire::test(ListProducts::class)
            ->assertSet('tableRecordsPerPage', 'all')
            ->assertSet('tableFilters.status.value', 'draft')
            ->assertCanSeeTableRecords($products)
            ->assertCanNotSeeTableRecords([$published]);
        $this->assertCount(61, $page->instance()->getTableRecords());
        $this->assertSame(1, $page->instance()->getTableRecords()->lastPage());

        $page->set('tableRecordsPerPage', 25);
        $this->assertCount(25, $page->instance()->getTableRecords());

        Livewire::test(ListProducts::class)->assertSet('tableRecordsPerPage', 'all')
            ->assertSet('tableFilters.status.value', 'draft');
        $this->assertSame(['status' => ['value' => 'draft']], auth()->user()->fresh()->product_table_filters);
    }

    public function test_dashboard_and_relation_tables_also_show_their_complete_records(): void
    {
        $update = TelegramUpdate::create(['update_id' => 500001, 'telegram_user_id' => '42', 'chat_id' => '900',
            'message_id' => 1, 'text' => 'Pagination fixture', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai',
            'model' => 'test-fixture', 'status' => 'completed', 'prompt' => 'Pagination fixture',
            'started_at' => now(), 'completed_at' => now()]);
        for ($i = 0; $i < 31; $i++) {
            ProductDraft::create(['title' => 'Search '.$i, 'status' => 'pending_review',
                'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '42',
                'specifications' => [], 'sources' => [], 'image_urls' => []]);
        }

        $widget = Livewire::test(RecentSearchesWidget::class)->assertSet('tableRecordsPerPage', 'all');
        $this->assertCount(31, $widget->instance()->getTableRecords());

        $product = Product::create(['title' => 'Media owner', 'category_id' => Category::where('slug', 'laptops')->sole()->id,
            'status' => 'draft', 'product_type' => 'laptop']);
        for ($i = 0; $i < 31; $i++) {
            $product->media()->create(['url' => 'https://example.com/photo-'.$i.'.jpg', 'alt' => 'Photo '.$i]);
        }

        $relation = Livewire::test(MediaRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertSet('tableRecordsPerPage', 'all');
        $this->assertCount(31, $relation->instance()->getTableRecords());
    }
}
