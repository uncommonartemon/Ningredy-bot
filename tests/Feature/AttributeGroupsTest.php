<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitions\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitions\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitions\Pages\ListAttributeDefinitions;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Catalog\AttributePresentation;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AttributeGroupsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_group_is_optional_and_can_be_created_inline_without_losing_unsaved_attribute_fields(): void
    {
        $this->admin();
        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['label' => 'Unsorted feature', 'data_type' => 'text'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertNull(AttributeDefinition::where('label', 'Unsorted feature')->sole()->attribute_group_id);

        $page = Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['label' => 'Keyboard pitch', 'data_type' => 'number', 'default_unit' => 'mm'])
            ->callAction(TestAction::make('createOption')->schemaComponent('attribute_group_id'), data: [
                'name' => 'Keyboard', 'translations' => ['cs' => 'Klávesnice'], 'sort_order' => 20,
            ])->assertHasNoActionErrors();
        $group = AttributeGroup::where('name', 'Keyboard')->sole();

        $this->assertEquals($group->id, $page->get('data.attribute_group_id'));
        $this->assertSame('Keyboard pitch', $page->get('data.label'));
        $this->assertSame('mm', $page->get('data.default_unit'));
        $this->assertFalse($group->highlight_in_filters);
        $this->assertSame(['cs' => 'Klávesnice'], $group->translations);
        $this->assertDatabaseMissing('attribute_definitions', ['label' => 'Keyboard pitch']);
        $page->call('create')->assertHasNoFormErrors();
        $definition = AttributeDefinition::where('label', 'Keyboard pitch')->sole();
        $this->assertEquals($group->id, $definition->group->id);
    }

    public function test_admin_can_remove_a_group_from_an_attribute_without_changing_existing_values(): void
    {
        $this->admin();
        $group = AttributeGroup::create(['name' => 'Memory']);
        $definition = $this->definition('grouped-memory', $group);
        $product = $this->product();
        $this->value($product->defaultVariant, $definition, '32 GB');

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id])
            ->fillForm(['attribute_group_id' => null])->call('save')->assertHasNoFormErrors();

        $this->assertNull($definition->fresh()->attribute_group_id);
        $this->assertDatabaseHas('attribute_values', ['attribute_definition_id' => $definition->id, 'value' => '32 GB']);
        $this->assertDatabaseHas('attribute_groups', ['id' => $group->id]);
    }

    public function test_category_default_attribute_can_create_a_group_in_a_nested_native_modal(): void
    {
        $this->admin();
        $category = Category::where('slug', 'laptops')->sole();
        $page = Livewire::test(EditCategory::class, ['record' => $category->id])
            ->fillForm(['name' => 'Unsaved category title'])
            ->mountAction(TestAction::make('createOption')->schemaComponent('default_attribute_ids'))
            ->fillForm(['label' => 'Keyboard illumination', 'data_type' => 'text'])
            ->callAction(TestAction::make('createOption')->schemaComponent('attribute_group_id'), data: [
                'name' => 'Input devices', 'highlight_in_filters' => true,
            ])->assertHasNoActionErrors();
        $group = AttributeGroup::where('name', 'Input devices')->sole();
        $this->assertSame('Unsaved category title', $page->get('data.name'));
        $this->assertSame('Keyboard illumination', $page->get('mountedActions.0.data.label'));
        $this->assertEquals($group->id, $page->get('mountedActions.0.data.attribute_group_id'));
        $page->callMountedAction()->assertHasNoActionErrors();
        $definition = AttributeDefinition::where('label', 'Keyboard illumination')->sole();
        $this->assertEquals($group->id, $definition->attribute_group_id);
        $this->assertContains((string) $definition->id, array_map('strval', $page->get('data.default_attribute_ids')));
        $page->call('save')->assertHasNoFormErrors();
        $this->assertTrue($category->attributeDefinitions()->wherePivot('is_default', true)->whereKey($definition->id)->exists());
    }

    public function test_deleting_a_group_keeps_attributes_values_and_filtering_available(): void
    {
        $group = AttributeGroup::create(['name' => 'Storage']);
        $definition = $this->definition('ssd-capacity', $group);
        $product = $this->product();
        $this->value($product->defaultVariant, $definition, '512 GB');

        $group->delete();

        $this->assertNull($definition->fresh()->attribute_group_id);
        $this->assertDatabaseHas('attribute_values', ['attribute_definition_id' => $definition->id, 'value' => '512 GB']);
        $page = $this->catalog(['attributes' => ['ssd-capacity' => ['512 gb']]]);
        $this->assertSame(1, $page['products']['total']);
        $this->assertNull($page['facets']['attributes'][0]['group']);
        $this->assertNull($page['products']['data'][0]['attributes'][0]['group']);
    }

    public function test_bulk_assignment_and_removal_changes_only_the_selected_attribute_definitions(): void
    {
        $this->admin();
        $group = AttributeGroup::create(['name' => 'Ports']);
        $first = $this->definition('bulk-first');
        $second = $this->definition('bulk-second');
        $unselected = $this->definition('bulk-unselected');
        $product = $this->product();
        $this->value($product, $first, 'USB-C');

        $page = Livewire::test(ListAttributeDefinitions::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('assignGroup')->table()->bulk(), data: ['attribute_group_id' => $group->id])
            ->assertHasNoActionErrors();
        $this->assertEquals($group->id, $first->fresh()->attribute_group_id);
        $this->assertEquals($group->id, $second->fresh()->attribute_group_id);
        $this->assertNull($unselected->fresh()->attribute_group_id);
        $this->assertDatabaseHas('attribute_values', ['attribute_definition_id' => $first->id, 'value' => 'USB-C']);

        $page->selectTableRecords([$first->id])
            ->callAction(TestAction::make('assignGroup')->table()->bulk(), data: ['attribute_group_id' => null])
            ->assertHasNoActionErrors();
        $this->assertNull($first->fresh()->attribute_group_id);
        $this->assertEquals($group->id, $second->fresh()->attribute_group_id);
    }

    public function test_group_metadata_and_order_are_shared_by_facets_cards_product_and_variant_details(): void
    {
        $storage = AttributeGroup::create(['name' => 'Storage', 'sort_order' => 20, 'highlight_in_filters' => false]);
        $processor = AttributeGroup::create(['name' => 'Processor', 'sort_order' => 10,
            'highlight_in_filters' => true, 'translations' => ['cs' => 'Procesor', 'uk' => 'Процесор']]);
        $cpuLate = $this->definition('group-cpu-late', $processor, 20);
        $plain = $this->definition('group-plain', sort: 0);
        $ssd = $this->definition('group-ssd', $storage, 0);
        $cpuEarly = $this->definition('group-cpu-early', $processor, 10);
        $variantSsd = $this->definition('group-variant-ssd', $storage, 5);
        $product = $this->product();
        foreach ([$ssd, $plain, $cpuLate, $cpuEarly] as $definition) {
            $this->value($product, $definition, 'Inherited '.$definition->key);
        }
        $this->value($product->defaultVariant, $cpuEarly, 'Variant override');
        $this->value($product->defaultVariant, $variantSsd, 'Variant SSD');

        $page = $this->catalog();
        $expected = ['group-cpu-early', 'group-cpu-late', 'group-ssd', 'group-variant-ssd', 'group-plain'];
        $this->assertSame($expected, array_column($page['facets']['attributes'], 'key'));
        $this->assertSame($expected, array_column($page['products']['data'][0]['attributes'], 'key'));
        $expectedGroup = ['id' => $processor->id, 'name' => 'Processor',
            'translations' => ['cs' => 'Procesor', 'uk' => 'Процесор'], 'sort_order' => 10, 'highlight_in_filters' => true];
        $this->assertSame($expectedGroup, $page['facets']['attributes'][0]['group']);
        $this->assertSame($expectedGroup, $page['products']['data'][0]['attributes'][0]['group']);
        $this->assertSame(10, $page['facets']['attributes'][0]['sort_order']);
        $this->assertSame(10, $page['products']['data'][0]['attributes'][0]['sort_order']);
        $this->assertFalse($page['facets']['attributes'][2]['group']['highlight_in_filters']);
        $this->assertNull($page['facets']['attributes'][4]['group']);
        $this->assertSame('Variant override', $page['products']['data'][0]['attributes'][0]['value']);

        $detail = $this->get('/products/'.$product->slug)->assertOk()->viewData('page')['props']['product'];
        $this->assertSame(['group-cpu-early', 'group-cpu-late', 'group-ssd', 'group-plain'], array_column($detail['attributes'], 'key'));
        $this->assertSame($expectedGroup, $detail['attributes'][0]['group']);
        $this->assertSame(10, $detail['attributes'][0]['sort_order']);
        $this->assertSame(['group-cpu-early', 'group-variant-ssd'], array_column($detail['variants'][0]['attributes'], 'key'));
        $this->assertSame($expectedGroup, $detail['variants'][0]['attributes'][0]['group']);
    }

    public function test_equal_sort_orders_use_lexical_group_names_and_attribute_keys_consistently(): void
    {
        $second = AttributeGroup::create(['name' => 'Group 2', 'sort_order' => 0]);
        $first = AttributeGroup::create(['name' => 'Group 10', 'sort_order' => 0]);
        $product = $this->product();
        foreach ([
            $this->definition('port-2', $first),
            $this->definition('second-group-feature', $second),
            $this->definition('port-10', $first),
        ] as $definition) {
            $this->value($product, $definition, 'Value');
        }

        $page = $this->catalog();
        $expected = ['port-10', 'port-2', 'second-group-feature'];
        $this->assertSame($expected, array_column($page['facets']['attributes'], 'key'));
        $this->assertSame($expected, array_column($page['products']['data'][0]['attributes'], 'key'));
    }

    public function test_grouping_does_not_change_filter_matching_counts_price_or_inherited_overrides(): void
    {
        $ram = $this->definition('group-ram');
        $socket = $this->definition('group-socket');
        $first = $this->product(variant: ['color' => 'Silver', 'price' => 200]);
        $this->value($first, $ram, '16 GB');
        $this->value($first->defaultVariant, $ram, '32 GB');
        $this->value($first->defaultVariant, $socket, 'AM5');
        $other = $this->product(variant: ['color' => 'Black', 'price' => 500]);
        $this->value($other->defaultVariant, $ram, '16 GB');
        $this->value($other->defaultVariant, $socket, 'AM4');
        $filters = ['colors' => ['Silver'], 'attributes' => ['group-ram' => ['32 gb']]];
        $before = $this->catalog($filters);

        $group = AttributeGroup::create(['name' => 'Main hardware', 'highlight_in_filters' => true]);
        $ram->update(['attribute_group_id' => $group->id]);
        $socket->update(['attribute_group_id' => $group->id]);
        $after = $this->catalog($filters);

        $this->assertSame(1, $after['products']['total']);
        $this->assertSame($first->id, $after['products']['data'][0]['id']);
        $this->assertSame($group->id, $after['facets']['attributes'][0]['group']['id']);
        $this->assertSame($before['facets']['price'], $after['facets']['price']);
        $this->assertSame($this->optionCounts($before), $this->optionCounts($after));
        $this->assertSame(0, $this->optionCounts($after)['group-socket']['am4']);
        $this->assertSame(0, $this->catalog(['attributes' => ['group-ram' => ['32 gb'], 'group-socket' => ['am4']]])['products']['total']);

        $group->update(['highlight_in_filters' => false]);
        $flat = $this->catalog($filters);
        $this->assertSame($after['products']['total'], $flat['products']['total']);
        $this->assertSame($this->optionCounts($after), $this->optionCounts($flat));
        $this->assertFalse($flat['facets']['attributes'][0]['group']['highlight_in_filters']);
    }

    public function test_category_facets_do_not_gain_empty_or_non_filterable_groups(): void
    {
        $screenGroup = AttributeGroup::create(['name' => 'Display', 'highlight_in_filters' => true]);
        $componentGroup = AttributeGroup::create(['name' => 'Compatibility', 'highlight_in_filters' => true]);
        $notesGroup = AttributeGroup::create(['name' => 'Other information', 'highlight_in_filters' => true]);
        AttributeGroup::create(['name' => 'Empty group', 'highlight_in_filters' => true]);
        $screen = $this->definition('group-display', $screenGroup);
        $socket = $this->definition('group-component-socket', $componentGroup);
        $note = $this->definition('group-notes', $notesGroup, filterable: false);
        $laptop = $this->product();
        $this->value($laptop->defaultVariant, $screen, '15.6');
        $component = $this->product('components');
        $this->value($component->defaultVariant, $socket, 'AM5');
        $this->value($component->defaultVariant, $note, 'Informational only');

        $page = $this->catalog(['category' => 'components']);
        $this->assertSame(['group-component-socket'], array_column($page['facets']['attributes'], 'key'));
        $this->assertSame($componentGroup->id, $page['facets']['attributes'][0]['group']['id']);
        $this->assertSame(['group-component-socket', 'group-notes'], array_column($page['products']['data'][0]['attributes'], 'key'));
        $detail = $this->get('/products/'.$component->slug)->assertOk()->viewData('page')['props']['product'];
        $notes = collect($detail['variants'][0]['attributes'])->firstWhere('key', 'group-notes');
        $this->assertSame('Informational only', $notes['value']);
        $this->assertSame($notesGroup->id, $notes['group']['id']);
    }

    public function test_other_variant_only_attributes_never_leak_into_global_details_or_selected_telegram_caption(): void
    {
        $memoryGroup = AttributeGroup::create(['name' => 'Selected memory']);
        $graphicsGroup = AttributeGroup::create(['name' => 'Discrete graphics']);
        $ram = $this->definition('selected-ram', $memoryGroup);
        $gpu = $this->definition('other-gpu', $graphicsGroup);
        $product = $this->product();
        $this->value($product, $ram, '16 GB');
        $other = $product->variants()->create(['is_active' => true, 'name' => 'Dedicated GPU model']);
        // Real legacy rows may contain both foreign keys, so product.attributes
        // alone is not the set of inherited product-wide characteristics.
        $product->attributes()->create(['attribute_definition_id' => $gpu->id,
            'product_variant_id' => $other->id, 'value' => 'RTX Other Variant']);

        $detail = $this->get('/products/'.$product->slug.'?variant='.$product->defaultVariant->id)
            ->assertOk()->viewData('page')['props']['product'];
        $this->assertSame(['selected-ram'], array_column($detail['attributes'], 'key'));
        $this->assertSame($memoryGroup->id, $detail['attributes'][0]['group']['id']);
        $otherPayload = collect($detail['variants'])->firstWhere('id', $other->id);
        $this->assertSame('RTX Other Variant', $otherPayload['attributes'][0]['value']);
        $this->assertSame($graphicsGroup->id, $otherPayload['attributes'][0]['group']['id']);
        $this->assertSame(['selected-ram'], array_column($this->catalog()['products']['data'][0]['attributes'], 'key'));

        $caption = app(ProductCardPresenter::class)->caption($product, variantId: $product->defaultVariant->id);
        $this->assertStringContainsString('Selected memory', $caption);
        $this->assertStringContainsString('16 GB', $caption);
        $this->assertStringNotContainsString('Discrete graphics', $caption);
        $this->assertStringNotContainsString('RTX Other Variant', $caption);
        $otherCaption = app(ProductCardPresenter::class)->caption($product, variantId: $other->id);
        $this->assertStringContainsString('Discrete graphics', $otherCaption);
        $this->assertStringContainsString('RTX Other Variant', $otherCaption);

        $this->admin();
        $this->get('/admin/products/'.$product->id)->assertOk()
            ->assertSee('Selected memory')->assertSee('Discrete graphics');
    }

    public function test_draft_grouping_preserves_original_evidence_and_only_groups_known_keys_in_telegram_details(): void
    {
        $group = AttributeGroup::create(['name' => 'Display details', 'sort_order' => 10]);
        $this->definition('draft-screen', $group, 5);
        $specifications = [
            ['key' => 'unregistered-mode', 'name' => 'Experimental mode', 'value' => 'Adaptive mode',
                'source_url' => 'https://source.example/unknown', 'source_excerpt' => 'Adaptive mode is supported.'],
            ['key' => 'draft-screen', 'name' => 'Original display name', 'value' => '15.6-inch IPS',
                'source_url' => 'https://source.example/display', 'source_excerpt' => '15.6-inch IPS panel.'],
        ];
        $update = TelegramUpdate::create(['update_id' => 500001, 'telegram_user_id' => '42', 'chat_id' => '900',
            'message_id' => 1, 'text' => 'Grouped draft', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai',
            'model' => 'test-fixture', 'status' => 'completed', 'prompt' => 'Grouped draft',
            'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['title' => 'Grouped draft', 'description' => 'Original description',
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '42',
            'status' => 'pending_review', 'specifications' => $specifications, 'sources' => [], 'image_urls' => []]);
        $originalJson = $draft->getRawOriginal('specifications');

        $presented = AttributePresentation::specifications($draft->specifications)->keyBy('key');
        $this->assertSame($group->id, $presented['draft-screen']['group']['id']);
        $this->assertNull($presented['unregistered-mode']['group']);
        foreach ($specifications as $original) {
            foreach (['name', 'value', 'source_url', 'source_excerpt'] as $field) {
                $this->assertSame($original[$field], $presented[$original['key']][$field]);
            }
        }

        $messages = [];
        $telegram = $this->mock(TelegramClient::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(function (string $chatId, string $text, ...$arguments) use (&$messages): array {
            $messages[] = $text;

            return ['ok' => true, 'result' => ['message_id' => count($messages), 'chat' => ['id' => $chatId]]];
        });
        app(DraftTelegramPresenter::class)->sendDetails($telegram, '900', $draft);

        $details = $messages[0];
        $this->assertStringContainsString('Display details', $details);
        $this->assertStringContainsString('Original display name: 15.6-inch IPS', $details);
        $this->assertStringContainsString('Experimental mode: Adaptive mode', $details);
        $this->assertStringContainsString('https://source.example/display', $details);
        $this->assertStringContainsString('https://source.example/unknown', $details);
        $this->assertSame($originalJson, $draft->fresh()->getRawOriginal('specifications'));
        $this->assertSame($specifications, $draft->fresh()->specifications);
    }

    public function test_adding_many_groups_does_not_add_a_query_for_each_attribute_on_public_pages(): void
    {
        $product = $this->product();
        $definitions = [];
        for ($i = 0; $i < 24; $i++) {
            $definition = $this->definition('group-query-'.$i, sort: $i);
            $this->value($product, $definition, 'Value '.$i);
            $definitions[] = $definition;
        }
        $this->catalog();
        $beforeCatalog = $this->queryCount('/catalog');
        $beforeDetail = $this->queryCount('/products/'.$product->slug);
        foreach ($definitions as $i => $definition) {
            $group = AttributeGroup::create(['name' => 'Group '.$i]);
            $definition->update(['attribute_group_id' => $group->id]);
        }

        $this->assertLessThanOrEqual($beforeCatalog + 5, $this->queryCount('/catalog'));
        $this->assertLessThanOrEqual($beforeDetail + 3, $this->queryCount('/products/'.$product->slug));
    }

    public function test_product_type_is_derived_from_category_without_a_second_manual_selection(): void
    {
        $this->admin();
        $category = Category::where('slug', 'components')->sole();
        Livewire::test(CreateProduct::class)
            ->fillForm(['title' => 'Category inferred product', 'category_id' => $category->id, 'status' => 'draft'])
            ->set('data.product_type', 'laptop')
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('component', Product::where('title', 'Category inferred product')->sole()->product_type);
    }

    public function test_legacy_category_without_affinity_preserves_existing_valid_product_type(): void
    {
        $this->admin();
        $legacy = Category::create(['name' => 'Legacy category', 'slug' => 'legacy-category', 'is_active' => true]);
        $product = $this->product('components', ['status' => 'draft']);
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['category_id' => $legacy->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame('component', $product->fresh()->product_type);

        Livewire::test(CreateProduct::class)
            ->fillForm(['title' => 'Legacy new product', 'category_id' => $legacy->id, 'status' => 'draft'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('other', Product::where('title', 'Legacy new product')->sole()->product_type);
    }

    private function admin(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    private function definition(string $key, ?AttributeGroup $group = null, int $sort = 0, bool $filterable = true): AttributeDefinition
    {
        return AttributeDefinition::create(['key' => $key, 'label' => $key, 'sort_order' => $sort,
            'attribute_group_id' => $group?->id, 'is_filterable' => $filterable]);
    }

    private function product(string $category = 'laptops', array $attributes = [], array $variant = []): Product
    {
        $number = ++$this->sequence;
        $product = Product::create([
            'category_id' => Category::where('slug', $category)->value('id'),
            'product_type' => $category === 'laptops' ? 'laptop' : 'component',
            'status' => 'published', 'is_active' => true, 'title' => 'Group product '.$number,
            'slug' => 'group-product-'.$number, 'canonical_key' => 'group-product-'.$number, ...$attributes,
        ]);
        $product->variants()->create(['is_default' => true, 'is_active' => true, 'price' => 100, ...$variant]);

        return $product;
    }

    private function value(Product|ProductVariant $owner, AttributeDefinition $definition, string $value): void
    {
        $owner->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => $value]);
    }

    private function catalog(array $filters = []): array
    {
        return $this->get('/catalog?'.http_build_query($filters))->assertOk()->viewData('page')['props'];
    }

    private function optionCounts(array $page): array
    {
        return collect($page['facets']['attributes'])->mapWithKeys(fn (array $attribute) => [
            $attribute['key'] => collect($attribute['options'])->pluck('count', 'value')->all(),
        ])->all();
    }

    private function queryCount(string $url): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->get($url)->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
