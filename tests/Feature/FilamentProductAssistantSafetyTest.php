<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductFormAssistantAgent;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Jobs\RunProductFormAssistant;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AiSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentProductAssistantSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.admin.name' => 'assistant-safety-admin']);
        $this->admin = User::factory()->create(['name' => 'assistant-safety-admin', 'is_admin' => true]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    #[DataProvider('editorOperations')]
    public function test_native_assistant_drawer_preserves_the_editing_session_when_closed_and_reopened(string $operation): void
    {
        $product = $operation === 'edit' ? $this->savedProduct()[0] : null;
        $page = $product
            ? Livewire::test(EditProduct::class, ['record' => $product->id])
            : $this->createPage();
        $page->mountAction('productAiAssistant')->assertActionMounted('productAiAssistant');
        $action = $page->instance()->getMountedAction();
        $this->assertTrue($action->isModalSlideOver());
        $this->assertTrue($action->isModalClickThrough());
        $this->assertNull($action->getModalSubmitAction());
        $this->assertNull($action->getModalCancelAction());

        $run = $this->send($page);
        $session = $page->get('productAiSession');
        $page->set('data.description', 'Typed while the assistant is open')
            ->set('productAiMessage', 'Unsent follow-up')->unmountAction();

        $this->assertSame('queued', $run->fresh()->status);
        $this->complete($run, [
            ['path' => 'title', 'value' => 'Prepared title'],
            ['path' => 'description', 'value' => 'AI description'],
        ]);
        $page->call('pollProductAi');
        $this->assertSame([], $page->get('mountedActions'));
        $this->assertSame('Prepared title', $page->get('data.title'));
        $this->assertSame('Typed while the assistant is open', $page->get('data.description'));
        $page->mountAction('productAiAssistant')->assertActionMounted('productAiAssistant');

        $this->assertSame($session, $page->get('productAiSession'));
        $this->assertSame($run->id, $page->get('productAiRunId'));
        $this->assertSame('Unsent follow-up', $page->get('productAiMessage'));
        $this->assertSame('Prepared title', $page->get('data.title'));
        $this->assertSame('Typed while the assistant is open', $page->get('data.description'));
        $this->assertCount(2, $page->get('productAiMessages'));
        $this->assertSame($product ? 1 : 0, Product::count());
        if ($product) {
            $this->assertSame('Lenovo Z50-70', $product->fresh()->title);
        }
        Queue::assertPushed(RunProductFormAssistant::class, 1);
    }

    public function test_repeated_send_queues_one_request_and_uses_the_admin_selected_model(): void
    {
        app(AiSettings::class)->saveModel('gpt-6-luna');
        $page = $this->createPage();
        $run = $this->send($page);
        $page->set('productAiMessage', 'Repeated click')->call('sendProductAiMessage');

        $this->assertSame($run->id, $page->get('productAiRunId'));
        $this->assertSame('gpt-6-luna', $run->model);
        $this->assertSame(1, AiRun::count());
        Queue::assertPushed(RunProductFormAssistant::class, 1);
        Queue::assertPushedOn('assistant', RunProductFormAssistant::class);
        $this->assertSame(0, Product::count());
    }

    public function test_browser_cannot_replace_the_active_run_id(): void
    {
        $page = $this->createPage();
        $this->send($page);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('productAiRunId', 987654321);
    }

    #[DataProvider('invalidRunContexts')]
    public function test_poll_rejects_a_run_that_no_longer_belongs_to_this_form(string $field, mixed $value): void
    {
        $page = $this->createPage();
        $run = $this->send($page);
        $activity = $run->activity;
        $activity[$field] = $value;
        $run->update(['activity' => $activity]);
        $this->complete($run, [['path' => 'title', 'value' => 'Unrelated secret result']]);

        $page->call('pollProductAi')->assertForbidden();
        $this->assertSame(0, Product::count());
    }

    public static function invalidRunContexts(): array
    {
        return [
            'another operator' => ['user_id', 999999],
            'another tab' => ['session_id', 'another-editor-session'],
            'another product' => ['product_id', 999999],
            'telegram task' => ['kind', 'telegram_product_search'],
        ];
    }

    public function test_revoked_panel_access_prevents_a_new_ai_request(): void
    {
        $page = $this->createPage();
        $this->admin->update(['is_admin' => false]);
        $this->actingAs($this->admin->fresh());
        $page->set('productAiMessage', 'Fill this product')->call('sendProductAiMessage')->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, AiRun::count());
    }

    #[DataProvider('editorOperations')]
    public function test_ai_entry_points_respect_the_current_product_policy(string $operation): void
    {
        [$product] = $this->savedProduct();
        $page = $operation === 'create'
            ? $this->createPage()
            : Livewire::test(EditProduct::class, ['record' => $product->id]);
        Gate::policy(Product::class, ProductAssistantDeniedPolicy::class);

        // Filament denies the component during hydration before the action runs.
        $page->set('productAiMessage', 'Fill the product')->call('sendProductAiMessage')->assertNotFound();
        Queue::assertNothingPushed();
    }

    public static function editorOperations(): array
    {
        return ['create' => ['create'], 'edit' => ['edit']];
    }

    public function test_cancelled_request_cannot_apply_a_late_response(): void
    {
        $page = $this->createPage();
        $run = $this->send($page);
        $page->call('cancelProductAi');
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->complete($run, [['path' => 'title', 'value' => 'Late response']]);
        $page->call('pollProductAi');

        $this->assertSame('Lenovo Z50-70', $page->get('data.title'));
        $this->assertSame(0, Product::count());
    }

    #[DataProvider('unavailableRequestContexts')]
    public function test_worker_does_not_call_ai_for_cancelled_or_no_longer_authorized_requests(string $reason): void
    {
        ProductFormAssistantAgent::fake()->preventStrayPrompts();
        [$product] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $this->send($page);

        match ($reason) {
            'cancelled' => $page->call('cancelProductAi'),
            'access_revoked' => $this->admin->update(['is_admin' => false]),
            'admin_name_changed' => config(['services.admin.name' => 'another-admin']),
            'product_deleted' => $product->delete(),
        };

        $job = Queue::pushed(RunProductFormAssistant::class)->sole();
        app()->call([$job, 'handle']);
        ProductFormAssistantAgent::assertNeverPrompted();
    }

    public static function unavailableRequestContexts(): array
    {
        return array_combine(
            ['cancelled', 'access_revoked', 'admin_name_changed', 'product_deleted'],
            [['cancelled'], ['access_revoked'], ['admin_name_changed'], ['product_deleted']],
        );
    }

    public function test_failed_and_unstarted_worker_requests_preserve_the_form_and_allow_retry(): void
    {
        $page = $this->createPage();
        $run = $this->send($page);
        $page->set('data.description', 'Typed while waiting')->call('pollProductAi');
        $this->assertSame('Typed while waiting', $page->get('data.description'));
        $this->assertSame(0, Product::count());
        $run->update(['status' => 'failed', 'error' => 'Fixture worker failure', 'completed_at' => now()]);
        $page->call('pollProductAi');
        $this->assertSame('failed', $page->get('productAiStatus'));
        $retry = $this->send($page, 'Try again');

        $this->assertNotSame($run->id, $retry->id);
        $this->assertSame('Typed while waiting', $page->get('data.description'));
        Queue::assertPushed(RunProductFormAssistant::class, 2);
    }

    public function test_answer_preserves_manual_edits_and_only_changes_the_target_variant(): void
    {
        [$product, $first, $second] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $keys = [$this->variantKey($page, 'FIRST-SKU'), $this->variantKey($page, 'SECOND-SKU')];
        $run = $this->send($page);
        $page->set('data.description', 'Operator description');
        $this->complete($run, [
            ['path' => 'title', 'value' => 'AI title'],
            ['path' => 'description', 'value' => 'AI description'],
            ['path' => "variants.{$keys[0]}.color", 'value' => 'Black'],
        ]);
        $beforeSibling = $page->get("data.variants.{$keys[1]}");
        $page->call('pollProductAi');

        $this->assertSame('AI title', $page->get('data.title'));
        $this->assertSame('Operator description', $page->get('data.description'));
        $this->assertSame('Black', $page->get("data.variants.{$keys[0]}.color"));
        $this->assertSame($beforeSibling, $page->get("data.variants.{$keys[1]}"));
        $this->assertSame('Lenovo Z50-70', $product->fresh()->title);
        $this->assertSame('Blue', $first->fresh()->color);
        $this->assertSame('Silver', $second->fresh()->color);

        $page->call('pollProductAi');
        $this->assertSame($beforeSibling, $page->get("data.variants.{$keys[1]}"));
    }

    public function test_removed_variant_is_not_resurrected_by_a_delayed_answer(): void
    {
        [$product] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $variants = $page->get('data.variants');
        $key = array_key_last($variants);
        $run = $this->send($page);
        unset($variants[$key]);
        $page->set('data.variants', $variants);
        $this->complete($run, [['path' => "variants.$key.color", 'value' => 'Black']]);
        $page->call('pollProductAi');

        $this->assertArrayNotHasKey($key, $page->get('data.variants'));
        $this->assertSame(2, $product->variants()->count());
    }

    public function test_chat_can_correct_a_saved_attribute_without_creating_a_duplicate_or_saving_early(): void
    {
        [$product, $variant] = $this->savedProduct();
        $definition = AttributeDefinition::where('key', 'ram')->sole();
        $attribute = $variant->attributes()->create(['attribute_definition_id' => $definition->id,
            'product_id' => $product->id, 'value' => '16', 'unit' => 'GB', 'translations' => ['cs' => ['value' => '16 GB']]]);
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $key = $this->variantKey($page, 'FIRST-SKU');
        $rowKey = array_key_first($page->get("data.variants.$key.attributes"));
        $run = $this->send($page, 'Correct memory to 32 GB');
        $this->complete($run, attributes: [[
            'variant_key' => $key, 'row_key' => $rowKey, 'definition_id' => $definition->id,
            'key' => 'ram', 'label' => $definition->label, 'value' => '32', 'unit' => 'GB',
            'data_type' => 'number', 'group' => null,
        ]]);
        $page->call('pollProductAi');

        $this->assertSame('32', $page->get("data.variants.$key.attributes.$rowKey.value"));
        $this->assertCount(1, $page->get("data.variants.$key.attributes"));
        $this->assertSame('16', $attribute->fresh()->value);
        $page->call('save', false)->assertHasNoFormErrors();
        $this->assertSame('32', $attribute->fresh()->value);
        $this->assertEmpty($attribute->fresh()->translations);
        $this->assertSame(1, $variant->attributes()->count());
    }

    public function test_attribute_correction_and_translation_from_one_answer_stay_consistent_before_and_after_save(): void
    {
        [$product, $variant] = $this->savedProduct();
        $definition = AttributeDefinition::where('key', 'ram')->sole();
        $attribute = $variant->attributes()->create(['attribute_definition_id' => $definition->id,
            'product_id' => $product->id, 'value' => '16', 'unit' => 'GB',
            'translations' => ['cs' => ['name' => 'Operační paměť', 'value' => '16 GB']]]);
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $key = $this->variantKey($page, 'FIRST-SKU');
        $rowKey = array_key_first($page->get("data.variants.$key.attributes"));
        $run = $this->send($page, 'Correct installed RAM to 24 GB and update the Czech translation');
        $this->complete($run,
            changes: [['path' => "variants.$key.attributes.$rowKey.translations.cs.value", 'value' => '24 GB']],
            attributes: [[
                'variant_key' => $key, 'row_key' => $rowKey, 'definition_id' => $definition->id,
                'key' => 'ram', 'label' => $definition->label, 'value' => '24', 'unit' => 'GB',
                'data_type' => 'number', 'group' => null,
            ]]);
        $page->call('pollProductAi');

        $this->assertSame('24', $page->get("data.variants.$key.attributes.$rowKey.value"));
        $this->assertSame('24 GB', $page->get("data.variants.$key.attributes.$rowKey.translations.cs.value"));
        $this->assertSame('16', $attribute->fresh()->value);
        $this->assertSame('16 GB', $attribute->fresh()->translations['cs']['value']);

        $page->call('save', false)->assertHasNoFormErrors();
        $this->assertSame('24', $attribute->fresh()->value);
        $this->assertSame('24 GB', $attribute->fresh()->translations['cs']['value']);
        $this->assertSame('Operační paměť', $attribute->fresh()->translations['cs']['name']);
        $this->assertSame(1, $variant->attributes()->count());
    }

    public function test_changing_variant_identity_while_waiting_does_not_mix_in_the_old_sku_specs(): void
    {
        [$product] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $key = $this->variantKey($page, 'FIRST-SKU');
        $run = $this->send($page);
        $page->set("data.variants.$key.sku", 'DIFFERENT-SKU');
        $this->complete($run, [['path' => "variants.$key.color", 'value' => 'Black']]);
        $page->call('pollProductAi');

        $this->assertSame('DIFFERENT-SKU', $page->get("data.variants.$key.sku"));
        $this->assertSame('Blue', $page->get("data.variants.$key.color"));
    }

    public function test_translation_for_a_changed_source_is_not_applied_to_the_new_text(): void
    {
        $page = $this->createPage()->set('data.description', 'Original source text');
        $page->call('translateProductWithAi');
        $run = AiRun::findOrFail($page->get('productAiRunId'));
        $page->set('data.description', 'A different product description');
        $this->complete($run, [
            ['path' => 'translations.cs.title', 'value' => 'Notebook Lenovo Z50-70'],
            ['path' => 'translations.cs.description', 'value' => 'Překlad původního popisu'],
        ]);
        $page->call('pollProductAi');

        $this->assertSame('Lenovo Z50-70', $page->get('data.title'));
        $this->assertSame('A different product description', $page->get('data.description'));
        $this->assertSame('Notebook Lenovo Z50-70', $page->get('data.translations.cs.title'));
        $this->assertEmpty($page->get('data.translations.cs.description'));
        $this->assertSame(0, Product::count());
    }

    #[DataProvider('changeOrderings')]
    public function test_enrichment_can_fill_a_source_and_its_translation_in_either_response_order(bool $translationFirst): void
    {
        $page = $this->createPage();
        $page->call('enrichProductWithAi');
        $run = AiRun::findOrFail($page->get('productAiRunId'));
        $changes = [
            ['path' => 'description', 'value' => 'Verified product description'],
            ['path' => 'translations.cs.description', 'value' => 'Ověřený popis produktu'],
        ];
        $this->complete($run, $translationFirst ? array_reverse($changes) : $changes,
            sources: [['title' => 'Product source', 'url' => 'https://example.com/lenovo-z50-70']]);
        $page->call('pollProductAi');

        $this->assertSame('Verified product description', $page->get('data.description'));
        $this->assertSame('Ověřený popis produktu', $page->get('data.translations.cs.description'));
        $this->assertSame(0, Product::count());
        $page->call('create')->assertHasNoFormErrors();
        $this->assertSame('Ověřený popis produktu', Product::sole()->translations['cs']['description']);
    }

    public static function changeOrderings(): array
    {
        return ['source first' => [false], 'translation first' => [true]];
    }

    public function test_variant_selector_prevents_ai_changes_to_a_different_configuration(): void
    {
        [$product, $first, $second] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $firstKey = $this->variantKey($page, 'FIRST-SKU');
        $secondKey = $this->variantKey($page, 'SECOND-SKU');
        $page->set('productAiVariantKey', $secondKey);
        $run = $this->send($page, 'Correct the color of the selected configuration');
        $this->assertSame($secondKey, $run->activity['variant_key']);
        $this->complete($run, [
            ['path' => "variants.$firstKey.color", 'value' => 'Red'],
            ['path' => "variants.$secondKey.color", 'value' => 'Black'],
        ]);
        $page->call('pollProductAi');

        $this->assertSame('Blue', $page->get("data.variants.$firstKey.color"));
        $this->assertSame('Black', $page->get("data.variants.$secondKey.color"));
        $this->assertSame('Blue', $first->fresh()->color);
        $this->assertSame('Silver', $second->fresh()->color);
    }

    public function test_absent_worker_expires_the_request_without_touching_unsaved_fields(): void
    {
        $page = $this->createPage()->set('data.description', 'My unsaved text');
        $run = $this->send($page);
        $run->update(['created_at' => now()->subHour(), 'started_at' => now()->subHour()]);
        $page->call('pollProductAi');

        $this->assertSame('failed', $page->get('productAiStatus'));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('My unsaved text', $page->get('data.description'));
        $this->assertSame(0, Product::count());
        $page->call('create')->assertHasNoFormErrors();
        $this->assertSame('My unsaved text', Product::sole()->description);
    }

    public function test_ai_dictionary_proposals_are_not_saved_until_the_user_saves_the_product(): void
    {
        $page = $this->createPage();
        $key = array_key_first($page->get('data.variants'));
        $before = $this->catalogCounts();
        $run = $this->send($page);
        $this->complete($run, attributes: [$this->newAttribute($key)], catalog: [
            ['kind' => 'brand', 'name' => 'Assistant proposed brand', 'product_type' => null],
        ]);
        $page->call('pollProductAi');

        $this->assertSame($before, $this->catalogCounts());
        $this->assertNotEmpty($page->get('productAiPendingCatalog'));
        $page->call('create')->assertHasNoFormErrors();
        $product = Product::where('title', 'Lenovo Z50-70')->sole();
        $attribute = $product->variants()->sole()->attributes()->whereHas('definition', fn ($query) => $query->where('key', 'assistant_storage_interface'))->sole();
        $this->assertSame('PCIe 4.0', $attribute->value);
        $this->assertSame('Assistant Storage', $attribute->definition->group->name);
        $this->assertSame('Assistant proposed brand', $product->brand->name);
        $this->assertFalse($attribute->definition->is_variant);
        $catalog = $this->get('/catalog')->assertOk()->viewData('page')['props'];
        $this->assertContains('assistant_storage_interface', array_column($catalog['facets']['attributes'], 'key'));

        $attribute->definition->update(['is_filterable' => false]);
        $catalog = $this->get('/catalog')->assertOk()->viewData('page')['props'];
        $this->assertNotContains('assistant_storage_interface', array_column($catalog['facets']['attributes'], 'key'));
    }

    public function test_repeated_attribute_proposals_reuse_the_pending_row_before_save(): void
    {
        $page = $this->createPage();
        $key = array_key_first($page->get('data.variants'));
        foreach (['PCIe 4.0', 'PCIe 5.0'] as $value) {
            $run = $this->send($page);
            $attribute = [...$this->newAttribute($key), 'value' => $value];
            $this->complete($run, attributes: [$attribute]);
            $page->call('pollProductAi');
        }

        $pendingRows = array_filter($page->get("data.variants.$key.attributes"),
            fn (array $row): bool => str_starts_with((string) ($row['attribute_definition_id'] ?? ''), 'ai:'));
        $this->assertCount(1, $pendingRows);
        $this->assertSame('PCIe 5.0', array_values($pendingRows)[0]['value']);
        $this->assertSame(0, AttributeDefinition::where('key', 'assistant_storage_interface')->count());

        $page->call('create')->assertHasNoFormErrors();
        $product = Product::where('title', 'Lenovo Z50-70')->sole();
        $this->assertSame(1, $product->variants()->sole()->attributes()->where('value', 'PCIe 5.0')->count());
        $this->assertSame(1, AttributeDefinition::where('key', 'assistant_storage_interface')->count());
    }

    public function test_malformed_ai_response_leaves_the_form_usable_and_does_not_partially_apply(): void
    {
        $page = $this->createPage();
        $before = $page->get('data');
        $run = $this->send($page);
        $run->update(['status' => 'completed', 'response' => [
            'message' => 'Malformed response',
            'changes' => [['path' => 'description', 'value' => 'Must not partially apply']],
            'attributes' => [['variant_key' => ['not', 'a', 'string']]], 'catalog' => [], 'sources' => [],
        ]]);
        $page->call('pollProductAi');

        $this->assertSame('failed', $page->get('productAiStatus'));
        $this->assertSame($before, $page->get('data'));
        $this->assertSame(0, Product::count());
        $page->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, Product::count());
    }

    public function test_failed_validation_restores_pending_proposals_and_retry_does_not_bind_reused_ids(): void
    {
        $page = $this->createPage();
        $key = array_key_first($page->get('data.variants'));
        $run = $this->send($page);
        $this->complete($run, attributes: [$this->newAttribute($key)]);
        $page->call('pollProductAi');
        $pending = $page->get('productAiPendingCatalog');
        $before = $this->catalogCounts();
        $page->set('data.title', '')->call('create')->assertHasFormErrors(['title']);
        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame($pending, $page->get('productAiPendingCatalog'));

        $unrelated = AttributeDefinition::create(['label' => 'Unrelated intervening definition', 'data_type' => 'text']);
        $page->set('data.title', 'Recovered product')->call('create')->assertHasNoFormErrors();
        $product = Product::where('title', 'Recovered product')->sole();
        $attribute = $product->variants()->sole()->attributes()->where('value', 'PCIe 4.0')->sole();
        $this->assertNotSame($unrelated->id, $attribute->attribute_definition_id);
        $this->assertSame('assistant_storage_interface', $attribute->definition->key);
        $this->assertSame('Assistant Storage', $attribute->definition->group->name);
        $this->assertSame(1, AttributeDefinition::where('key', 'assistant_storage_interface')->count());
    }

    public function test_stale_edit_cannot_materialize_ai_dictionary_proposals(): void
    {
        [$product, $variant] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $key = $this->variantKey($page, 'FIRST-SKU');
        $run = $this->send($page);
        $this->complete($run, attributes: [$this->newAttribute($key)]);
        $page->call('pollProductAi');
        $before = $this->catalogCounts();
        $variant->update(['price' => 12345]);
        $page->call('save')->assertHasFormErrors(['title']);

        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame('12345.00', $variant->fresh()->price);
        $this->assertNotEmpty($page->get('productAiPendingCatalog'));
    }

    public function test_create_another_does_not_apply_the_previous_products_late_response(): void
    {
        $page = $this->createPage();
        $run = $this->send($page);
        $page->call('create', true)->assertHasNoFormErrors();
        $this->assertSame(1, Product::where('title', 'Lenovo Z50-70')->count());
        $page->set('data.title', 'Another product');
        $this->complete($run, [['path' => 'description', 'value' => 'Old product description']]);
        $page->call('pollProductAi');

        $this->assertSame('Another product', $page->get('data.title'));
        $this->assertEmpty($page->get('data.description'));
        $this->assertEmpty($page->get('productAiPendingCatalog'));
    }

    public function test_malicious_result_cannot_change_publication_inventory_ids_or_executable_sources(): void
    {
        [$product] = $this->savedProduct();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $key = $this->variantKey($page, 'FIRST-SKU');
        $before = $page->get('data');
        $run = $this->send($page);
        $this->complete($run, [
            ['path' => 'id', 'value' => '999999'],
            ['path' => 'status', 'value' => 'published'],
            ['path' => 'is_active', 'value' => '0'],
            ['path' => 'category_id', 'value' => '999999'],
            ['path' => 'brand_id', 'value' => '999999'],
            ['path' => "variants.$key.product_id", 'value' => '999999'],
            ['path' => "variants.$key.price", 'value' => '1'],
            ['path' => "variants.$key.quantity", 'value' => '9999'],
            ['path' => 'sources.attacker.url', 'value' => 'javascript:alert(1)'],
            ['path' => 'new_photos.0', 'value' => '../../.env'],
        ], sources: [
            ['title' => 'Executable', 'url' => 'javascript:alert(1)'],
            ['title' => 'Local', 'url' => 'http://127.0.0.1/admin'],
            ['title' => 'Credentials', 'url' => 'https://user:secret@example.com/product'],
        ]);
        $page->call('pollProductAi');

        foreach (['id', 'status', 'is_active', 'category_id', 'brand_id', 'sources', 'new_photos', 'variants'] as $field) {
            $this->assertEquals($before[$field] ?? null, $page->get("data.$field"), "Unsafe mutation: $field");
        }
        $this->assertSame('draft', $product->fresh()->status);
        $this->assertSame('100.00', $product->variants()->orderBy('id')->first()->price);
    }

    private function createPage(): Testable
    {
        return Livewire::test(CreateProduct::class)->set('data.title', 'Lenovo Z50-70')
            ->set('data.category_id', Category::where('slug', 'laptops')->value('id'));
    }

    private function variantKey(Testable $page, string $sku): string
    {
        foreach ($page->get('data.variants') as $key => $variant) {
            if (($variant['sku'] ?? null) === $sku) {
                return $key;
            }
        }
        $this->fail("Variant $sku was not present in the fixture form.");
    }

    private function send(Testable $page, string $message = 'Help complete this product'): AiRun
    {
        $page->set('productAiMessage', $message)->call('sendProductAiMessage');

        return AiRun::findOrFail($page->get('productAiRunId'));
    }

    private function complete(AiRun $run, array $changes = [], array $attributes = [], array $catalog = [], array $sources = []): void
    {
        $run->update(['status' => 'completed', 'completed_at' => now(), 'response' => [
            'message' => 'Prepared changes for review.', 'changes' => $changes,
            'attributes' => $attributes, 'catalog' => $catalog, 'sources' => $sources,
        ]]);
    }

    private function newAttribute(string $key): array
    {
        return ['variant_key' => $key, 'row_key' => null, 'definition_id' => null,
            'key' => 'assistant_storage_interface', 'label' => 'Assistant storage interface',
            'value' => 'PCIe 4.0', 'unit' => null, 'data_type' => 'text',
            'group' => ['id' => null, 'name' => 'Assistant Storage', 'name_cs' => 'Úložiště']];
    }

    private function catalogCounts(): array
    {
        return [Product::count(), ProductVariant::count(), AttributeDefinition::count(), AttributeGroup::count(), Brand::count(), Category::count()];
    }

    private function savedProduct(): array
    {
        $product = Product::create(['title' => 'Lenovo Z50-70', 'category_id' => Category::where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'draft', 'is_active' => true]);
        $first = $product->variants()->create(['name' => 'First configuration', 'sku' => 'FIRST-SKU', 'color' => 'Blue',
            'condition' => 'new', 'currency' => 'CZK', 'stock_status' => 'unknown', 'price' => 100,
            'quantity' => 0, 'is_default' => true, 'is_active' => true]);
        $second = $product->variants()->create(['name' => 'Second configuration', 'sku' => 'SECOND-SKU', 'color' => 'Silver',
            'condition' => 'new', 'currency' => 'CZK', 'stock_status' => 'unknown', 'price' => 200,
            'quantity' => 0, 'is_default' => false, 'is_active' => true]);

        return [$product, $first, $second];
    }
}

class ProductAssistantDeniedPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Product $product): bool
    {
        return false;
    }
}
