<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Catalog\CategoryStandard;
use App\Services\Products\ManualProductAttributes;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: the bot works by the category's standard - research and the card
 * assembly read the form with the operator's examples and the note, the server writes values the
 * way the form says, and the card reports each field: filled, not found or inexact.
 */
class CategoryStandardBotTest extends TestCase
{
    use RefreshDatabase;

    private Category $ram;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake([StoreProductImages::class]);
        $this->ram = Category::query()->where('slug', 'ram')->firstOrFail();
        app(CategoryStandard::class)->syncForm($this->ram, [
            ['attribute_definition_id' => $this->definition('ram', 'number', 'GB')->id, 'example' => '16 GB', 'is_required' => true],
            ['attribute_definition_id' => $this->definition('memory_type')->id, 'example' => 'DDR4 / DDR5', 'is_required' => true],
            ['attribute_definition_id' => $this->definition('module_type')->id, 'example' => 'UDIMM / RDIMM / SO-DIMM', 'is_required' => true],
            ['attribute_definition_id' => $this->definition('voltage', 'text', 'V')->id, 'example' => '1.2 V'],
            ['attribute_definition_id' => $this->definition('cas_latency')->id, 'example' => 'CL22', 'section' => 'extra'],
        ]);
        $this->ram->update(['card_standard' => 'No «Brand new», «Tray» or «Used» in the title.']);
    }

    public function test_research_reads_the_form_with_its_examples_and_the_note(): void
    {
        $instructions = (string) (new ProductResearchAgent)->instructions();

        $this->assertStringContainsString('the form of this category, fill these with these exact keys: ram (Memory; write like: 16 GB; required), '
            .'memory_type (Memory type; write like: DDR4 / DDR5; required)', $instructions);
        $this->assertStringContainsString('cas_latency (Cas latency; write like: CL22)', $instructions);
        $this->assertStringContainsString("the operator's note for this category: No «Brand new», «Tray» or «Used» in the title.", $instructions);
        $standard = app(CategoryStandard::class)->forAgents($this->ram);
        $this->assertSame(['ram', 'memory_type', 'module_type', 'voltage', 'cas_latency'], array_column($standard['form'], 'key'));
        $this->assertSame('required', $standard['form'][0]['importance']);
    }

    public function test_a_kind_without_its_own_form_starts_a_product_with_its_parents(): void
    {
        $ssd = Category::query()->where('slug', 'ssd')->firstOrFail();
        $ssd->attributeDefinitions()->newPivotQuery()->update(['is_default' => false, 'is_required' => false]);
        $components = Category::query()->where('slug', 'components')->firstOrFail();
        app(CategoryStandard::class)->syncForm($components, [['attribute_definition_id' => $this->definition('interface')->id]]);

        $rows = array_values(app(ManualProductAttributes::class)->defaults($ssd->id));

        $this->assertSame([$this->definition('interface')->id], array_column($rows, 'attribute_definition_id'));
    }

    public function test_values_are_written_as_the_standard_says(): void
    {
        $standard = app(CategoryStandard::class);
        $ram = $this->definition('ram', 'number', 'GB');

        $this->assertSame('16 GB', $standard->standardValue($ram, '16гб', '16 GB'));
        $this->assertSame('8 GB', $standard->standardValue($ram, '8GB', '16 GB'));
        $this->assertSame('1 TB', $standard->standardValue($ram, '1 TB', '16 GB'));
        $module = $this->definition('module_type');
        $this->assertSame('SO-DIMM', $standard->standardValue($module, '260-pin SODIMM', 'UDIMM / RDIMM / SO-DIMM'));
        $this->assertSame('RDIMM', $standard->standardValue($module, 'rdimm', 'UDIMM / RDIMM / SO-DIMM'));
        // Two variants named at once: the value stays as written.
        $this->assertSame('UDIMM or RDIMM', $standard->standardValue($module, 'UDIMM or RDIMM', 'UDIMM / RDIMM / SO-DIMM'));
        $this->assertSame('1.2 V', $standard->standardValue($this->definition('voltage', 'text', 'V'), '1.2v', '1.2 V'));
    }

    public function test_the_card_reports_each_field_and_the_product_gets_the_standard_values(): void
    {
        $draft = $this->draft([
            ['key' => 'memory_size', 'name' => 'Memory', 'value' => '16гб'],
            ['key' => 'memory_type', 'name' => 'Memory type', 'value' => 'DDR4 SDRAM'],
            ['key' => 'module_type', 'name' => 'Module type', 'value' => 'Supported'],
            ['key' => 'voltage', 'name' => 'Operating voltage', 'value' => '1.2v'],
        ]);
        $draft->update(['card_repair_state' => ['last_failure' => ['repair_context' => ['unresolved_fields' => [
            ['key' => 'voltage', 'status' => 'conflicting', 'reason' => 'Two pages give 1.2 V and 1.35 V.'],
        ]]]]]);
        AttributeDefinition::query()->where('key', 'ram')->firstOrFail()->update(['aliases' => ['memory size']]);

        $line = app(CategoryStandard::class)->reportLine($draft->fresh());

        $this->assertStringContainsString('🧩 Форма «', $line);
        $this->assertStringContainsString('заполнено 2 из 5', $line);
        $this->assertStringContainsString('❓ Не найдено: Cas latency', $line);
        $this->assertStringContainsString('⚠️ Неточность: ', $line);
        $this->assertStringContainsString('«Supported», не из вариантов: UDIMM / RDIMM / SO-DIMM', $line);
        $this->assertStringContainsString('Voltage — «1.2v», Two pages give 1.2 V and 1.35 V.', $line);

        $product = app(ProductDraftWorkflow::class)->approve($draft->fresh());

        $values = $product->attributes()->with('definition')->get()->concat($product->variants()->sole()->attributes()->with('definition')->get())
            ->mapWithKeys(fn ($value): array => [$value->definition->key => $value->value])->all();
        $this->assertSame('DDR4', $values['memory_type']);
        $this->assertSame('1.2 V', $values['voltage']);
        $this->assertStringStartsWith('16', (string) $values['ram']);
        $this->assertStringNotContainsString('гб', (string) $values['ram']);
    }

    private function definition(string $key, string $type = 'text', ?string $unit = null): AttributeDefinition
    {
        return AttributeDefinition::query()->firstOrCreate(['key' => $key], ['label' => ucfirst(str_replace('_', ' ', $key)), 'data_type' => $type, 'default_unit' => $unit]);
    }

    private function draft(array $specifications): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 880100, 'telegram_user_id' => '42', 'chat_id' => '900', 'message_id' => 1,
            'text' => 'RAM fixture', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test-fixture', 'status' => 'completed',
            'prompt' => 'RAM fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '42',
            'status' => 'pending_review', 'title' => 'Fixture module', 'brand' => 'Fixture', 'model' => 'FM-16', 'description' => 'A memory module.',
            'product_type' => 'component', 'category' => 'ram', 'confidence' => 0.95, 'image_urls' => [],
            'specifications' => array_map(fn (array $spec): array => [...$spec, 'source_url' => 'https://source.example/m', 'source_excerpt' => $spec['value']], $specifications),
            'sources' => [['title' => 'Fixture', 'url' => 'https://source.example/m']], 'primary_source_url' => 'https://source.example/m',
            'specifications_reconciled_source_url' => 'https://source.example/m']);
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/'.$draft->id.'/a.webp', 'source_url' => 'https://source.example/a.webp',
            'role' => 'primary', 'mime_type' => 'image/webp', 'width' => 2000, 'height' => 2000, 'file_size' => 1, 'checksum' => hash('sha256', 'ram-fixture'),
            'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

        return $draft;
    }
}
