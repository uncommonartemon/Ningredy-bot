<?php

namespace Tests\Feature;

use App\Exceptions\UnreconciledDraftSpecificationsException;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\ProductDrafts\Pages\EditProductDraft;
use App\Filament\Resources\ProductGalleryRecipes\Pages\EditProductGalleryRecipe;
use App\Filament\Resources\ProductGalleryRecipes\Pages\ViewProductGalleryRecipe;
use App\Filament\Resources\ProductGalleryRecipeVersions\Pages\ListProductGalleryRecipeVersions;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductGalleryRecipeProof;
use App\Services\Products\ProductGalleryRecipeRestorer;
use App\Services\Products\ProductGalleryRecipeRouter;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentActionSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        config(['services.admin.name' => 'audit-admin']);
        $this->actingAs(User::factory()->create(['name' => 'audit-admin', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    private function product(): Product
    {
        return Product::create(['category_id' => Category::where('slug', 'laptops')->value('id'),
            'title' => 'Audit laptop', 'product_type' => 'laptop', 'status' => 'draft', 'is_active' => true]);
    }

    private function offer(array $extra = []): array
    {
        return [...['condition' => 'new', 'currency' => 'CZK', 'price' => 100, 'stock_status' => 'unknown',
            'quantity' => 0, 'is_default' => true, 'is_active' => true], ...$extra];
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => random_int(10000, 999999), 'telegram_user_id' => '1',
            'chat_id' => '1', 'text' => 'audit laptop', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'audit',
            'status' => 'completed', 'prompt' => 'audit', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'title' => 'Audit laptop', 'brand' => 'Example', 'model' => 'AUDIT-1', 'condition' => 'new',
            'product_type' => 'laptop', 'category' => 'laptops', 'status' => 'pending_review',
            'description' => 'An audit fixture laptop.', 'specifications' => [], 'sources' => [], 'image_urls' => [],
            'primary_source_url' => 'https://example.com/audit',
            'specifications_reconciled_source_url' => 'https://example.com/audit']);
        $draft->media()->create(['disk' => 'public', 'path' => 'audit.jpg', 'width' => 1600, 'height' => 1200,
            'mime_type' => 'image/jpeg', 'checksum' => hash('sha256', 'audit'), 'verification_status' => 'verified']);

        return $draft;
    }

    public function test_full_form_rejects_more_recent_commercial_change(): void
    {
        $product = $this->product();
        $variant = $product->variants()->create($this->offer());
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $variant->update(['price' => 250]);
        $page->fillForm(['title' => 'Only rename'])->call('save')->assertHasFormErrors(['title']);
        $this->assertSame('250.00', $variant->fresh()->price);
    }

    public function test_stale_full_form_preserves_newly_added_variant(): void
    {
        $product = $this->product();
        $product->variants()->create($this->offer());
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $new = $product->variants()->create($this->offer(['name' => 'Added by another operator', 'is_default' => false]));
        $page->fillForm(['title' => 'Only rename'])->call('save')->assertHasFormErrors(['title']);
        $this->assertNotNull($new->fresh());
    }

    public function test_rejected_recipe_version_cannot_be_promoted_by_restore(): void
    {
        $recipe = ProductGalleryRecipe::create(['domain' => 'example.com', 'path_pattern' => '*', 'status' => 'active',
            'recipe' => ['gallery_selector' => '.good']]);
        $version = ProductGalleryRecipeVersion::create(['product_gallery_recipe_id' => $recipe->id,
            'domain' => 'example.com', 'product_url' => 'https://example.com/item', 'trigger' => 'test',
            'status' => 'rejected', 'provider' => 'openai', 'model' => 'audit',
            'recipe' => ['gallery_selector' => '.bad'], 'result' => ['candidate_count' => 0]]);
        Livewire::test(ListProductGalleryRecipeVersions::class)
            ->assertActionDisabled(TestAction::make('rollback')->table($version));
        try {
            app(ProductGalleryRecipeRestorer::class)->restore($version);
            $this->fail('Rejected recipe must not be restored.');
        } catch (ValidationException) {
        }
        $this->assertSame('.good', $recipe->fresh()->recipe['gallery_selector']);
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertSame('rejected', ProductGalleryRecipeVersion::latest('id')->first()->status);
    }

    public function test_category_cannot_select_itself_as_parent(): void
    {
        $category = Category::where('slug', 'laptops')->sole();
        Livewire::test(EditCategory::class, ['record' => $category->id])
            ->fillForm(['parent_id' => $category->id])->call('save')->assertHasFormErrors(['parent_id']);
        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_reject_preserves_already_approved_draft_status(): void
    {
        $draft = $this->draft();
        $stale = $draft->fresh();
        $product = app(ProductDraftWorkflow::class)->approve($draft);
        try {
            app(ProductDraftWorkflow::class)->reject($stale, reason: 'Old modal submit');
            $this->fail('Already approved draft must not be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Черновик уже обработан.', $exception->getMessage());
        }
        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertSame($product->id, $draft->fresh()->approved_product_id);
        $this->assertSame('published', $product->fresh()->status);
    }

    public function test_brand_change_invalidates_reconciliation_stamp(): void
    {
        $draft = $this->draft();
        $draft->update(['brand' => 'Another manufacturer']);
        $this->assertTrue($draft->fresh()->reconciliationPending());
    }

    public function test_stale_approval_rejects_new_unreconciled_fields(): void
    {
        $draft = $this->draft();
        $stale = $draft->fresh();
        $draft->update(['model' => 'DIFFERENT-2']);
        $this->assertTrue($draft->fresh()->reconciliationPending());
        $this->expectException(UnreconciledDraftSpecificationsException::class);
        app(ProductDraftWorkflow::class)->approve($stale);
    }

    public function test_clearing_primary_source_blocks_unreconciled_draft_publish(): void
    {
        $draft = $this->draft();
        Livewire::test(EditProductDraft::class, ['record' => $draft->id])
            ->fillForm(['model' => 'CHANGED-MODEL', 'primary_source_url' => null])
            ->call('save')->assertHasNoFormErrors();
        $draft->refresh();
        $this->assertNull($draft->specifications_reconciled_source_url);
        $this->assertTrue($draft->reconciliationPending());
        $this->expectException(UnreconciledDraftSpecificationsException::class);
        app(ProductDraftWorkflow::class)->approve($draft);
    }

    public function test_editing_english_title_clears_old_czech_translation(): void
    {
        $product = $this->product();
        $product->update(['translations' => ['cs' => ['title' => 'Old Czech title']]]);
        $product->variants()->create($this->offer());
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['title' => 'Completely different English title'])->call('save')->assertHasNoFormErrors();
        $this->assertNull($product->fresh()->translations['cs']['title'] ?? null);
    }

    public function test_block_domain_button_turns_into_unblock_for_path_recipe(): void
    {
        $recipe = ProductGalleryRecipe::create(['domain' => 'example.com', 'path_pattern' => '/products/*',
            'status' => 'active', 'recipe' => ['gallery_selector' => '.good'], 'source_blocked' => false]);
        $page = Livewire::test(ViewProductGalleryRecipe::class, ['record' => $recipe->id]);
        $page->callAction('toggleBlocked', data: ['reason' => 'test'])->assertHasNoActionErrors();
        $this->assertFalse($recipe->fresh()->source_blocked);
        $this->assertTrue(ProductGalleryRecipe::where('domain', 'example.com')->where('path_pattern', '*')->sole()->source_blocked);
        $page->callAction('toggleBlocked', data: ['reason' => 'second click'])->assertHasNoActionErrors();
        $this->assertFalse(ProductGalleryRecipe::where('domain', 'example.com')->where('path_pattern', '*')->where('source_blocked', true)->exists());
    }

    public function test_quick_edit_labels_path_block_as_path_block(): void
    {
        $recipe = ProductGalleryRecipe::create(['domain' => 'example.com', 'path_pattern' => '/products/*',
            'status' => 'active', 'recipe' => ['gallery_selector' => '.good'], 'source_blocked' => false]);
        Livewire::test(EditProductGalleryRecipe::class, ['record' => $recipe->id])
            ->assertSee('Исключить область этого рецепта')
            ->fillForm(['source_blocked' => true])->call('save')->assertHasNoFormErrors();
        $router = app(ProductGalleryRecipeRouter::class);
        $this->assertTrue($router->domainIsBlocked('https://example.com/products/a'));
        $this->assertFalse($router->domainIsBlocked('https://example.com/other/a'));
    }

    public function test_current_form_can_save_twice_and_stale_draft_form_cannot_overwrite_new_work(): void
    {
        $product = $this->product();
        $product->variants()->create($this->offer());
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $page->fillForm(['title' => 'First'])->call('save', false)->assertHasNoFormErrors();
        $page->fillForm(['title' => 'Second'])->call('save', false)->assertHasNoFormErrors();
        $this->assertSame('Second', $product->fresh()->title);
        $draft = $this->draft();
        $edit = Livewire::test(EditProductDraft::class, ['record' => $draft->id]);
        $draft->update(['title' => 'New result']);
        $edit->fillForm(['description' => 'Old tab edit'])->call('save')->assertHasFormErrors(['title']);
        $this->assertSame('New result', $draft->fresh()->title);
    }

    public function test_new_media_and_attribute_changes_invalidate_whole_product_form(): void
    {
        $product = $this->product();
        $variant = $product->variants()->create($this->offer());
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $photo = $product->media()->create(['type' => 'image', 'url' => 'https://example.com/new.jpg', 'product_variant_id' => $variant->id]);
        $page->fillForm(['title' => 'Old tab'])->call('save')->assertHasFormErrors(['title']);
        $this->assertNotNull($photo->fresh());
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $attribute = $variant->attributes()->create(['attribute_definition_id' => AttributeDefinition::where('key', 'ram')->value('id'), 'value' => '32', 'unit' => 'GB']);
        $page->call('save')->assertHasFormErrors(['title']);
        $this->assertSame('32', $attribute->fresh()->value);
    }

    public function test_explicit_new_translation_is_kept_and_only_changed_base_fields_are_cleared(): void
    {
        $product = $this->product();
        $product->update(['description' => 'Original', 'translations' => ['cs' => ['title' => 'Old title', 'description' => 'Same description']]]);
        $product->update(['title' => 'New English', 'translations' => ['cs' => ['title' => 'New Czech', 'description' => 'Same description']]]);
        $this->assertSame('New Czech', $product->fresh()->translations['cs']['title']);
        $this->assertSame('Same description', $product->fresh()->translations['cs']['description']);
        $product->update(['description' => 'New English description']);
        $this->assertNull($product->fresh()->translations['cs']['description'] ?? null);
        $this->assertSame('New Czech', $product->fresh()->translations['cs']['title']);
        $variant = $product->variants()->create($this->offer(['name' => 'Old variant', 'translations' => ['cs' => ['title' => 'Old variant Czech']]]));
        $variant->update(['name' => 'New variant']);
        $this->assertNull($variant->fresh()->translations['cs']['title'] ?? null);
    }

    public function test_category_cycle_is_rejected_by_form_and_model_while_reparenting_stays_possible(): void
    {
        $parent = Category::where('slug', 'laptops')->sole();
        $child = Category::create(['name' => 'Child audit', 'product_type_affinity' => 'laptop', 'parent_id' => $parent->id]);
        $grandchild = Category::create(['name' => 'Grandchild audit', 'product_type_affinity' => 'laptop', 'parent_id' => $child->id]);
        $this->assertContains($grandchild->id, $parent->excludedParentIds());
        Livewire::test(EditCategory::class, ['record' => $parent->id])
            ->fillForm(['parent_id' => $grandchild->id])->call('save')->assertHasFormErrors(['parent_id']);
        try {
            $parent->update(['parent_id' => $grandchild->id]);
            $this->fail('Ancestor cycle must fail outside Filament too.');
        } catch (ValidationException) {
        }
        $this->assertNull($parent->fresh()->parent_id);
        $child->update(['parent_id' => null]);
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_proven_recipe_version_restores_transactionally_and_remains_restorable(): void
    {
        $recipe = ProductGalleryRecipe::create(['domain' => 'example.com', 'path_pattern' => '*', 'status' => 'disabled', 'recipe' => ['gallery_selector' => '.new']]);
        $version = ProductGalleryRecipeVersion::create(['product_gallery_recipe_id' => $recipe->id, 'domain' => 'example.com',
            'product_url' => 'https://example.com/item', 'trigger' => 'training', 'status' => 'promoted',
            'promoted_at' => now(), 'provider' => 'openai', 'model' => 'audit',
            'recipe' => ['gallery_selector' => '.proven'], 'result' => ['validation' => ['passed' => true]]]);
        Livewire::test(ListProductGalleryRecipeVersions::class)->callAction(TestAction::make('rollback')->table($version))->assertHasNoActionErrors();
        $this->assertSame('.proven', $recipe->fresh()->recipe['gallery_selector']);
        $restored = ProductGalleryRecipeVersion::latest('id')->first();
        $this->assertSame($version->id, $restored->result['restored_version_id']);
        $this->assertTrue(app(ProductGalleryRecipeRestorer::class)->canRestore($restored));
        $version->update(['result' => [], 'status' => 'promoted']);
        $this->assertFalse(app(ProductGalleryRecipeRestorer::class)->canRestore($version->fresh()));
    }

    public function test_unblocking_a_placeholder_redirects_and_does_not_unblock_independent_paths(): void
    {
        $path = ProductGalleryRecipe::create(['domain' => 'example.com', 'path_pattern' => '/special/*', 'status' => 'active', 'source_blocked' => true]);
        app(ProductGalleryRecipeRouter::class)->blockDomain('example.com');
        $domain = ProductGalleryRecipe::where('domain', 'example.com')->where('path_pattern', '*')->sole();
        Livewire::test(ViewProductGalleryRecipe::class, ['record' => $domain->id])->callAction('toggleBlocked')
            ->assertHasNoActionErrors()->assertRedirect();
        $this->assertNull($domain->fresh());
        $this->assertTrue($path->fresh()->source_blocked);
    }

    public function test_recipe_restore_accepts_only_journal_proof_for_the_exact_recipe(): void
    {
        $recipe = ProductGalleryRecipe::create(['domain' => 'example.com', 'path_pattern' => '*',
            'status' => 'active', 'recipe' => ['gallery_selector' => '.proven']]);
        $version = ProductGalleryRecipeVersion::create(['product_gallery_recipe_id' => $recipe->id,
            'domain' => 'example.com', 'product_url' => 'https://example.com/item', 'trigger' => 'training',
            'status' => 'promoted', 'promoted_at' => now(), 'provider' => 'test', 'model' => 'fake',
            'recipe' => $recipe->recipe, 'result' => []]);
        $restorer = app(ProductGalleryRecipeRestorer::class);
        $this->assertFalse($restorer->canRestore($version));
        app(ProductGalleryRecipeProof::class)->remember(
            $recipe, $version->product_url, ['passed' => true], versionId: $version->id,
        );
        $this->assertTrue($restorer->canRestore($version));
        $restorer->restore($version);
        $this->assertTrue($restorer->canRestore(ProductGalleryRecipeVersion::latest('id')->first()));
        $version->update(['recipe' => ['gallery_selector' => '.different']]);
        $this->assertFalse($restorer->canRestore($version->fresh()));
    }
}
