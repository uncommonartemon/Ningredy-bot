<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductFormAssistantAgent;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Jobs\RunProductFormAssistant;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductFormAssistant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ProductFormAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.admin.name' => 'assistant-test']);
        $this->admin = User::factory()->create(['name' => 'assistant-test', 'is_admin' => true]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function response(array $changes = [], array $attributes = [], array $sources = []): array
    {
        return ['message' => 'Review the form.', 'changes' => $changes, 'attributes' => $attributes, 'catalog' => [], 'sources' => $sources];
    }

    private function start(string $mode = 'chat', string $message = 'Describe the product', array $form = []): AiRun
    {
        return app(ProductFormAssistant::class)->start($this->admin, null, 'test-session', $mode, $message,
            $form ?: ['title' => 'Lenovo Z50-70', 'variants' => ['v1' => ['sku' => 'TEST-SKU', 'is_default' => true, 'attributes' => []]]], []);
    }

    public function test_job_completes_with_structured_response_and_usage_without_saving_a_product(): void
    {
        ProductFormAssistantAgent::fake([$this->response([['path' => 'description', 'value' => 'Operator supplied description']])])->preventStrayPrompts();
        $run = $this->start();
        (new RunProductFormAssistant($run->id))->handle(app(ProductFormAssistant::class));

        $run->refresh();
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame('description', $run->response['changes'][0]['path']);
        $this->assertArrayHasKey('prompt_tokens', $run->usage);
        $this->assertNotEmpty($run->invocation_id);
        $this->assertSame(0, Product::count());
        ProductFormAssistantAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Lenovo Z50-70'));
    }

    public function test_cancelled_response_is_never_resurrected_after_provider_returns(): void
    {
        $run = $this->start();
        ProductFormAssistantAgent::fake(function () use ($run): array {
            $run->update(['status' => 'cancelled']);

            return $this->response([['path' => 'title', 'value' => 'Late answer']]);
        })->preventStrayPrompts();
        (new RunProductFormAssistant($run->id))->handle(app(ProductFormAssistant::class));
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($run->fresh()->response);
        $this->assertArrayHasKey('prompt_tokens', $run->fresh()->usage);
        $this->assertNotEmpty($run->fresh()->invocation_id);
    }

    public function test_job_failure_is_recorded_without_catalog_changes(): void
    {
        ProductFormAssistantAgent::fake(fn () => throw new \RuntimeException('Test provider failure'))->preventStrayPrompts();
        $run = $this->start();
        (new RunProductFormAssistant($run->id))->handle(app(ProductFormAssistant::class));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, Product::count());
    }

    public function test_duplicate_session_submission_reuses_the_active_run(): void
    {
        $first = $this->start();
        $second = $this->start();
        $this->assertSame($first->id, $second->id);
        Queue::assertPushed(RunProductFormAssistant::class, 1);
    }

    public function test_unsaved_dictionary_proposals_are_context_for_the_next_chat_turn(): void
    {
        $pending = ['ai:test-token' => [
            'kind' => 'attribute', 'label' => 'Memory bandwidth', 'key' => 'memory_bandwidth', 'unit' => 'GB/s',
            'data_type' => 'number', 'value' => '120', 'group' => ['id' => null, 'name' => 'Graphics', 'name_cs' => 'Grafika'],
        ]];
        $run = app(ProductFormAssistant::class)->start($this->admin, null, 'pending-session', 'chat', 'Correct the bandwidth',
            ['title' => 'GPU', 'variants' => ['v1' => ['attributes' => ['row1' => ['attribute_definition_id' => 'ai:test-token', 'value' => '120']]]]],
            [], 'v1', $pending);
        $prompt = json_decode(app(ProductFormAssistant::class)->prompt($run), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($pending, $prompt['pending_catalog']);
        $this->assertContains('open_box', $prompt['commercial_options']['conditions']);
        $this->assertContains('in_stock', $prompt['commercial_options']['stock_statuses']);
    }

    public function test_pending_native_select_labels_show_names_and_keep_persisted_name_lookup(): void
    {
        Filament::bootCurrentPanel();
        $page = Livewire::test(CreateProduct::class)->set('data.title', 'A product')
            ->set('productAiMessage', 'Create missing dictionary entries')->call('sendProductAiMessage');
        $run = AiRun::findOrFail($page->get('productAiRunId'));
        $variantKey = $run->activity['variant_key'];
        $response = $this->response(attributes: [[
            'variant_key' => $variantKey, 'row_key' => null, 'definition_id' => null, 'key' => 'pending_spec',
            'label' => 'Pending specification', 'value' => 'Value', 'unit' => null, 'data_type' => 'text', 'group' => null,
        ]]);
        $response['catalog'] = [
            ['kind' => 'brand', 'name' => 'Pending brand', 'product_type' => null],
            ['kind' => 'category', 'name' => 'Pending category', 'product_type' => 'other'],
        ];
        $run->update(['status' => 'completed', 'response' => $response]);
        $page->call('pollProductAi');
        $form = $page->instance()->form;
        $this->assertStringContainsString('Pending brand', $form->getComponentByStatePath('brand_id')->getOptionLabel());
        $this->assertStringContainsString('Pending category', $form->getComponentByStatePath('category_id')->getOptionLabel());
        $rows = $page->get("data.variants.$variantKey.attributes");
        $rowKey = array_key_first($rows);
        $this->assertStringContainsString('Pending specification', $form->getComponentByStatePath("variants.$variantKey.attributes.$rowKey.attribute_definition_id")->getOptionLabel());

        $page->call('create')->assertHasNoFormErrors();
        $product = Product::where('title', 'A product')->sole();
        $this->assertSame('Pending brand', $product->brand->name);
        $this->assertSame('Pending category', $product->category->name);
        $this->assertSame('Pending brand', $page->instance()->form->getComponentByStatePath('brand_id')->getOptionLabel());
        $this->assertSame('Pending category', $page->instance()->form->getComponentByStatePath('category_id')->getOptionLabel());
    }

    public function test_successful_retry_clears_assistant_error_without_hiding_other_form_errors(): void
    {
        Filament::bootCurrentPanel();
        $page = Livewire::test(CreateProduct::class);
        $page->call('enrichProductWithAi')->assertHasErrors(['productAiMessage']);
        $page->set('data.title', 'Lenovo Z50-70')->call('enrichProductWithAi')
            ->assertHasNoErrors(['productAiMessage']);
        $page->call('cancelProductAi')->assertHasNoErrors(['productAiMessage']);

        $page = Livewire::test(CreateProduct::class)->call('create')->assertHasFormErrors(['category_id']);
        $page->set('data.title', 'Another product')->call('enrichProductWithAi')->assertHasFormErrors(['category_id']);
    }

    public function test_translator_has_no_web_tools_and_preserves_original_text(): void
    {
        $this->assertSame([], (new ProductFormAssistantAgent('translate'))->tools());
        $run = $this->start('translate', 'Translate', ['title' => 'Original', 'description' => 'Source text']);
        $run->response = $this->response([
            ['path' => 'title', 'value' => 'Changed source'],
            ['path' => 'translations.cs.title', 'value' => 'Preklad'],
            ['path' => 'translations.ru.title', 'value' => 'Wrong locale'],
        ]);
        $result = app(ProductFormAssistant::class)->apply($run, $run->activity['base'], []);
        $this->assertSame('Original', $result['form']['title']);
        $this->assertSame('Preklad', $result['form']['translations']['cs']['title']);
        $this->assertArrayNotHasKey('ru', $result['form']['translations']);
    }

    public function test_enrichment_rejects_source_less_facts_and_commercial_changes(): void
    {
        $run = $this->start('enrich');
        $run->response = $this->response([['path' => 'description', 'value' => 'Unverified']]);
        $result = app(ProductFormAssistant::class)->apply($run, $run->activity['base'], []);
        $this->assertSame(0, $result['changed']);
        $run->response = $this->response([['path' => 'variants.v1.price', 'value' => '5000']], sources: [['title' => 'Source', 'url' => 'https://www.lenovo.com/product']]);
        $result = app(ProductFormAssistant::class)->apply($run, $run->activity['base'], []);
        $this->assertArrayNotHasKey('price', $result['form']['variants']['v1']);
    }

    public function test_commercial_changes_require_explicit_operator_field_and_number(): void
    {
        $run = $this->start(message: 'Set price 5000 CZK and quantity 2');
        $run->response = $this->response([
            ['path' => 'variants.v1.price', 'value' => '5000'], ['path' => 'variants.v1.quantity', 'value' => '2'],
        ]);
        $result = app(ProductFormAssistant::class)->apply($run, $run->activity['base'], []);
        $this->assertEquals(5000, $result['form']['variants']['v1']['price']);
        $this->assertEquals(2, $result['form']['variants']['v1']['quantity']);
        $run->prompt = 'Installed memory 16 GB';
        $run->response = $this->response([['path' => 'variants.v1.price', 'value' => '1']]);
        $result = app(ProductFormAssistant::class)->apply($run, $run->activity['base'], []);
        $this->assertArrayNotHasKey('price', $result['form']['variants']['v1']);
    }

    public function test_urls_reject_private_schemes_credentials_addresses_and_local_hosts(): void
    {
        $service = app(ProductFormAssistant::class);
        foreach (['javascript:alert(1)', 'http://localhost/a', 'https://127.0.0.1/a', 'http://10.0.0.1/a', 'https://user:password@example.com/a', 'http://[::1]/', 'https://catalog.internal/a', 'https://host.local/a'] as $url) {
            $this->assertFalse($service->safeUrl($url), $url);
        }
        $this->assertTrue($service->safeUrl('https://www.lenovo.com/cz/cs/p/product?sku=123'));
    }

    public function test_operator_prices_accept_czech_thousands_spaces(): void
    {
        $run = $this->start();
        $run->response = $this->response([['path' => 'variants.v1.price', 'value' => '12990']]);
        foreach (['12 990', "12\u{00a0}990", "12\u{202f}990"] as $price) {
            $run->prompt = 'Set price '.$price.' CZK';
            $result = app(ProductFormAssistant::class)->apply($run, $run->activity['base'], []);
            $this->assertEquals(12990, $result['form']['variants']['v1']['price']);
        }
    }

    public function test_deleted_attribute_row_is_not_recreated_when_ai_omits_row_key(): void
    {
        $definition = AttributeDefinition::create(['key' => 'removed_memory', 'label' => 'Removed memory', 'data_type' => 'number']);
        $base = ['title' => 'Laptop', 'variants' => ['v1' => ['is_default' => true, 'attributes' => [
            'row1' => ['attribute_definition_id' => $definition->id, 'value' => '16', 'unit' => 'GB'],
        ]]]];
        $run = $this->start(form: $base);
        $run->response = $this->response(attributes: [[
            'variant_key' => 'v1', 'row_key' => null, 'definition_id' => $definition->id, 'key' => $definition->key,
            'label' => $definition->label, 'value' => '24', 'unit' => 'GB', 'data_type' => 'number', 'group' => null,
        ]]);
        $base['variants']['v1']['attributes'] = [];
        $result = app(ProductFormAssistant::class)->apply($run, $base, []);
        $this->assertSame([], $result['form']['variants']['v1']['attributes']);
        $this->assertNotEmpty($result['conflicts']);
    }

    public function test_attribute_translations_follow_the_accepted_value_and_never_a_rejected_update(): void
    {
        $definition = AttributeDefinition::create(['key' => 'test_memory', 'label' => 'Test memory', 'data_type' => 'number', 'default_unit' => 'GB']);
        $base = ['title' => 'Laptop', 'variants' => ['v1' => ['sku' => 'TEST', 'is_default' => true, 'attributes' => [
            'row1' => ['attribute_definition_id' => $definition->id, 'value' => '16', 'unit' => 'GB', 'translations' => ['cs' => ['name' => 'Pamet', 'value' => '16']]],
        ]]]];
        $run = $this->start(form: $base);
        $run->response = $this->response([
            ['path' => 'variants.v1.attributes.row1.translations.cs.name', 'value' => 'Operacni pamet'],
            ['path' => 'variants.v1.attributes.row1.translations.cs.value', 'value' => '24'],
        ], [[
            'variant_key' => 'v1', 'row_key' => 'row1', 'definition_id' => $definition->id, 'key' => $definition->key,
            'label' => $definition->label, 'value' => '24', 'unit' => 'GB', 'data_type' => 'number', 'group' => null,
        ]]);
        $result = app(ProductFormAssistant::class)->apply($run, $base, []);
        $this->assertSame('24', $result['form']['variants']['v1']['attributes']['row1']['value']);
        $this->assertSame('24', $result['form']['variants']['v1']['attributes']['row1']['translations']['cs']['value']);
        $this->assertSame([], $result['conflicts']);

        $base['variants']['v1']['attributes']['row1']['unit'] = 'MiB';
        $result = app(ProductFormAssistant::class)->apply($run, $base, []);
        $this->assertSame('16', $result['form']['variants']['v1']['attributes']['row1']['value']);
        $this->assertSame('16', $result['form']['variants']['v1']['attributes']['row1']['translations']['cs']['value']);
        $this->assertNotEmpty($result['conflicts']);
    }

    public function test_new_attribute_reuses_pending_definition_on_a_second_chat_turn(): void
    {
        $beforeDefinitions = AttributeDefinition::count();
        $run = $this->start();
        $proposal = ['variant_key' => 'v1', 'row_key' => null, 'definition_id' => null, 'key' => 'memory_bandwidth', 'label' => 'Memory bandwidth',
            'value' => '100', 'unit' => 'GB/s', 'data_type' => 'number', 'group' => ['id' => null, 'name' => 'Graphics', 'name_cs' => 'Grafika']];
        $run->response = $this->response(attributes: [$proposal]);
        $first = app(ProductFormAssistant::class)->apply($run, $run->activity['base'], []);
        $activity = $run->activity;
        $activity['base'] = app(ProductFormAssistant::class)->snapshot($first['form']);
        $run->activity = $activity;
        $proposal['value'] = '120';
        $run->response = $this->response(attributes: [$proposal]);
        $second = app(ProductFormAssistant::class)->apply($run, $first['form'], $first['pending']);
        $this->assertCount(1, $second['pending']);
        $this->assertCount(1, $second['form']['variants']['v1']['attributes']);
        $this->assertSame('120', array_values($second['form']['variants']['v1']['attributes'])[0]['value']);
        $this->assertSame($beforeDefinitions, AttributeDefinition::count());
    }
}
