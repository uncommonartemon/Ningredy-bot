<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogMaintenance;
use App\Filament\Resources\AttributeDefinitions\Pages\ListAttributeDefinitions;
use App\Filament\Resources\AttributeGroups\Pages\ListAttributeGroups;
use App\Filament\Resources\ProductDrafts\Pages\ListProductDrafts;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\TelegramUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogCleanupAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        config(['services.admin.name' => 'cleanup-admin']);
        $this->admin = User::factory()->create(['name' => 'cleanup-admin', 'is_admin' => true]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_admin_can_open_maintenance_page_with_safe_defaults_and_native_actions(): void
    {
        $this->get(CatalogMaintenance::getUrl())->assertOk()->assertSee('Очистка данных');
        Livewire::test(CatalogMaintenance::class)
            ->assertSet('data.dictionaries', false)
            ->assertSet('data.recipes_history', false)
            ->assertActionExists('clearCatalog')
            ->assertActionExists('clearDrafts')
            ->assertActionExists('clearTestData');
    }

    public function test_opening_and_cancelling_cleanup_only_previews_and_never_deletes(): void
    {
        $product = $this->product();
        $page = Livewire::test(ListProducts::class)->mountAction('clearCatalog')->assertActionMounted('clearCatalog')
            ->assertMountedActionModalSee(__('Будет удалено'))
            ->assertMountedActionModalSee(__('Сохраняется'))
            ->assertMountedActionModalSee(__('Черновики и их фото'));
        $token = $page->get('cleanupPlanToken');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertDatabaseHas('products', ['id' => $product->id]);

        $page->unmountAction();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('ai_operations', ['action' => 'admin_cleanup', 'status' => 'completed']);
    }

    public function test_catalog_confirmation_clears_records_outside_current_table_search_and_preserves_other_data(): void
    {
        $first = $this->product('Visible search target');
        $second = $this->product('Hidden by current search');
        $draft = $this->draft();
        $recipe = $this->recipe();
        AppSetting::put('cleanup-test-setting', 'preserved value');
        $definitions = AttributeDefinition::count();
        Livewire::test(ListProducts::class)->searchTable('Visible search target')
            ->assertCountTableRecords(1)->mountAction('clearCatalog')->callMountedAction()->assertHasNoActionErrors();

        $this->assertDatabaseMissing('products', ['id' => $first->id]);
        $this->assertDatabaseMissing('products', ['id' => $second->id]);
        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('product_gallery_recipes', ['id' => $recipe->id]);
        $this->assertSame($definitions, AttributeDefinition::count());
        $this->assertSame('preserved value', AppSetting::valueFor('cleanup-test-setting'));
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_draft_confirmation_clears_all_tabs_without_clearing_catalog(): void
    {
        $product = $this->product();
        $pending = $this->draft();
        $rejected = $this->draft('rejected');
        Livewire::test(ListProductDrafts::class)->set('activeTab', 'pending')
            ->mountAction('clearDrafts')->callMountedAction()->assertHasNoActionErrors();

        $this->assertDatabaseMissing('product_drafts', ['id' => $pending->id]);
        $this->assertDatabaseMissing('product_drafts', ['id' => $rejected->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_general_cleanup_defaults_preserve_dictionaries_and_recipes_even_if_flags_change_after_preview(): void
    {
        $product = $this->product();
        $draft = $this->draft();
        $group = AttributeGroup::create(['name' => 'Cleanup preserved RAM']);
        $recipe = $this->recipe();
        $categories = Category::count();
        $definitions = AttributeDefinition::count();
        $page = Livewire::test(CatalogMaintenance::class)->mountAction('clearTestData');
        $page->set('data.dictionaries', true)->set('data.recipes_history', true)
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('attribute_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('product_gallery_recipes', ['id' => $recipe->id]);
        $this->assertSame($categories, Category::count());
        $this->assertSame($definitions, AttributeDefinition::count());
    }

    public function test_explicit_general_cleanup_flags_are_part_of_the_confirmed_snapshot(): void
    {
        $this->product();
        $group = AttributeGroup::create(['name' => 'Cleanup explicit RAM']);
        $recipe = $this->recipe();
        $page = Livewire::test(CatalogMaintenance::class)->set('data.dictionaries', true)->set('data.recipes_history', true)
            ->mountAction('clearTestData');
        $page->set('data.dictionaries', false)->set('data.recipes_history', false)
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertDatabaseMissing('attribute_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('product_gallery_recipes', ['id' => $recipe->id]);
        $this->assertSame(0, Category::count());
        $this->assertSame(0, AttributeDefinition::count());
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_new_rows_after_preview_reject_confirmation_without_partial_deletion(): void
    {
        $first = $this->product();
        $page = Livewire::test(ListProducts::class)->mountAction('clearCatalog');
        $second = $this->product('Created after preview');

        $page->callMountedAction();

        $this->assertDatabaseHas('products', ['id' => $first->id]);
        $this->assertDatabaseHas('products', ['id' => $second->id]);
        $this->assertDatabaseMissing('ai_operations', ['action' => 'admin_cleanup', 'status' => 'completed']);
    }

    public function test_active_work_blocks_cleanup_when_confirming(): void
    {
        $product = $this->product();
        $page = Livewire::test(ListProducts::class)->mountAction('clearCatalog');
        TelegramUpdate::create(['update_id' => 990001, 'chat_id' => '12345', 'telegram_user_id' => '12345',
            'status' => 'processing', 'payload' => [], 'processed_at' => null]);

        $page->callMountedAction();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('ai_operations', ['action' => 'admin_cleanup', 'status' => 'completed']);
    }

    public function test_active_work_is_visible_in_preview_and_disables_native_confirmation(): void
    {
        $product = $this->product();
        TelegramUpdate::create(['update_id' => 990002, 'chat_id' => '12345', 'telegram_user_id' => '12345',
            'status' => 'queued', 'payload' => [], 'processed_at' => null]);

        $page = Livewire::test(ListProducts::class)->mountAction('clearCatalog')->assertActionMounted('clearCatalog')
            ->assertMountedActionModalSee(__('Сначала завершите работу'));

        $this->assertTrue($page->instance()->getMountedAction()->getModalSubmitAction()->isDisabled());
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_cleanup_cannot_be_invoked_as_an_unmounted_livewire_method(): void
    {
        $product = $this->product();
        try {
            Livewire::test(ListProducts::class)->call('catalogCleanupAction', 'catalog');
            $this->fail('Protected cleanup methods must not be exposed as Livewire endpoints.');
        } catch (MethodNotFoundException) {
        }

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('ai_operations', ['action' => 'admin_cleanup']);
    }

    public function test_cleanup_token_cannot_be_forged_from_the_livewire_client(): void
    {
        $product = $this->product();
        $page = Livewire::test(ListProducts::class)->mountAction('clearCatalog');
        try {
            $page->set('cleanupPlanToken', 'forged-plan-token');
            $this->fail('Cleanup token must be a locked server property.');
        } catch (CannotUpdateLockedPropertyException) {
        }

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_removing_admin_access_after_preview_prevents_cleanup(): void
    {
        $product = $this->product();
        $page = Livewire::test(ListProducts::class)->mountAction('clearCatalog');
        $this->admin->update(['is_admin' => false]);

        $page->callMountedAction();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('ai_operations', ['action' => 'admin_cleanup', 'status' => 'completed']);
    }

    public function test_non_admin_cannot_open_the_maintenance_page(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'outsider', 'is_admin' => true]));

        $this->get(CatalogMaintenance::getUrl())->assertForbidden();
    }

    public function test_attribute_and_group_lists_offer_native_cleanup_actions(): void
    {
        Livewire::test(ListAttributeDefinitions::class)->assertActionExists('clearAttributes');
        Livewire::test(ListAttributeGroups::class)->assertActionExists('clearAttributeGroups');
    }

    public function test_group_cleanup_detaches_attributes_without_deleting_definitions(): void
    {
        $group = AttributeGroup::create(['name' => 'Cleanup group only']);
        $definition = AttributeDefinition::create(['label' => 'Cleanup group frequency', 'key' => 'cleanup-frequency',
            'data_type' => 'number', 'attribute_group_id' => $group->id]);
        Livewire::test(ListAttributeGroups::class)->mountAction('clearAttributeGroups')->callMountedAction()->assertHasNoActionErrors();

        $this->assertDatabaseMissing('attribute_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('attribute_definitions', ['id' => $definition->id, 'attribute_group_id' => null]);
    }

    public function test_attribute_cleanup_removes_values_and_category_links_without_deleting_products_or_groups(): void
    {
        $product = $this->product();
        $group = AttributeGroup::create(['name' => 'Cleanup preserved group']);
        $definition = AttributeDefinition::create(['label' => 'Cleanup memory', 'key' => 'cleanup-memory',
            'data_type' => 'number', 'attribute_group_id' => $group->id, 'is_variant' => false]);
        $definition->categories()->attach($product->category_id);
        $value = AttributeValue::create(['attribute_definition_id' => $definition->id, 'product_id' => $product->id,
            'value' => '16', 'numeric_value' => 16, 'unit' => 'GB']);

        Livewire::test(ListAttributeDefinitions::class)->mountAction('clearAttributes')->callMountedAction()->assertHasNoActionErrors();

        $this->assertDatabaseMissing('attribute_definitions', ['id' => $definition->id]);
        $this->assertDatabaseMissing('attribute_values', ['id' => $value->id]);
        $this->assertDatabaseMissing('attribute_definition_category', ['attribute_definition_id' => $definition->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('attribute_groups', ['id' => $group->id]);
    }

    private function product(string $title = 'Cleanup product'): Product
    {
        return Product::create(['title' => $title, 'category_id' => Category::where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'published', 'is_active' => true]);
    }

    private function draft(string $status = 'pending_review'): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => random_int(100000, 900000), 'chat_id' => '12345',
            'telegram_user_id' => '12345', 'status' => 'completed', 'payload' => [], 'processed_at' => now()]);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture',
            'prompt' => 'Cleanup fixture', 'status' => 'completed', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'Cleanup draft', 'status' => $status,
            'specifications' => [], 'sources' => [], 'image_urls' => []]);
    }

    private function recipe(): ProductGalleryRecipe
    {
        return ProductGalleryRecipe::create(['domain' => 'cleanup.example', 'path_pattern' => '*',
            'status' => 'active', 'recipe' => ['collect_selectors' => ['.gallery img']], 'success_count' => 3]);
    }
}
