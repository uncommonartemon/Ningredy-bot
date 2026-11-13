<?php

namespace Tests\Feature;

use App\Ai\Agents\AttributeDictionaryReviewAgent;
use App\Ai\Agents\AttributeKeeperAgent;
use App\Ai\Tools\ManageCharacteristicDictionary;
use App\Filament\Resources\AttributeDefinitions\Pages\ListAttributeDefinitions;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: before a card is approved, a model that reads the whole characteristic
 * dictionary decides about each characteristic the dictionary does not know: the same as an
 * entry, or a new one with a name and a group. Its "same" is remembered, so the next card with
 * that name needs no model.
 */
class AttributeKeeperTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake([StoreProductImages::class]);
    }

    public function test_a_characteristic_named_another_way_lands_in_the_one_the_dictionary_has_and_is_remembered(): void
    {
        $prompts = [];
        AttributeKeeperAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['verdicts' => [['candidate' => 'memory_size', 'decision' => 'same', 'key' => 'ram', 'name' => null,
                'group' => null, 'remember' => true, 'reason' => 'System memory of a laptop.']]];
        })->preventStrayPrompts();

        $product = app(ProductDraftWorkflow::class)->approve($this->draft([$this->fact('memory_size', 'Memory size', '16 GB')]));

        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'memory_size']);
        $ram = AttributeDefinition::query()->where('key', 'ram')->sole();
        $this->assertSame('16 GB', $product->variants()->sole()->attributes()->where('attribute_definition_id', $ram->id)->sole()->value);
        $this->assertContains('memory size', $ram->aliases);
        // The model read the candidate against the whole dictionary.
        $this->assertStringContainsString('"key":"memory_size"', $prompts[0]);
        $this->assertStringContainsString('"key":"refresh_rate"', $prompts[0]);
        $this->assertSame(1, $this->runs('attribute_dictionary'));

        // The next card that calls it so needs no model.
        app(ProductDraftWorkflow::class)->approve($this->draft([$this->fact('memory_size', 'Memory size', '32 GB')]));
        $this->assertCount(1, $prompts);
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'memory_size']);
    }

    public function test_a_new_characteristic_gets_the_keepers_key_name_and_group_and_a_known_one_without_a_group_gets_one(): void
    {
        $noise = AttributeDefinition::query()->create(['key' => 'cooling_noise', 'label' => 'Noise']);
        AttributeKeeperAgent::fake(fn (): array => ['verdicts' => [
            ['candidate' => 'fans', 'decision' => 'new', 'key' => 'cooling_fan_count', 'name' => 'Cooling fan count',
                'group' => ['id' => null, 'name' => 'Cooling', 'name_cs' => 'Chlazení'], 'remember' => true, 'reason' => 'New property.'],
            ['candidate' => 'cooling_noise', 'decision' => 'same', 'key' => 'cooling_noise', 'name' => null,
                'group' => ['id' => null, 'name' => 'Cooling', 'name_cs' => 'Chlazení'], 'remember' => false, 'reason' => 'Known, no group.'],
        ]])->preventStrayPrompts();

        app(ProductDraftWorkflow::class)->approve($this->draft([$this->fact('fans', 'Fans', '2'), $this->fact('cooling_noise', 'Noise', '22 dB')]));

        $fans = AttributeDefinition::query()->where('key', 'cooling_fan_count')->sole();
        $this->assertSame('Cooling fan count', $fans->label);
        $this->assertSame('Cooling', $fans->group?->name);
        $this->assertSame(['fans'], $fans->aliases);
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'fans']);
        $this->assertSame('Cooling', $noise->fresh()->group?->name);
        $this->assertSame(1, AttributeGroup::query()->count());
    }

    public function test_a_card_the_dictionary_knows_entirely_asks_no_model(): void
    {
        AttributeKeeperAgent::fake(fn () => throw new RuntimeException('must not be asked'))->preventStrayPrompts();

        app(ProductDraftWorkflow::class)->approve($this->draft([$this->fact('ram', 'Memory', '16 GB'), $this->fact('cpu', 'Processor', 'Intel Core i5')]));

        $this->assertSame(0, $this->runs('attribute_dictionary'));
    }

    public function test_without_the_keepers_answer_the_card_is_written_as_before(): void
    {
        AttributeKeeperAgent::fake(fn () => throw new RuntimeException('provider down'))->preventStrayPrompts();

        $product = app(ProductDraftWorkflow::class)->approve($this->draft([$this->fact('gpu_bus_width', 'Graphics bus width', '128 bit')]));

        $this->assertSame('128 bit', $product->attributes()->sole()->value);
        $this->assertDatabaseHas('attribute_definitions', ['key' => 'gpu_bus_width']);
        $this->assertSame('failed', AiRun::query()->get()->first(fn (AiRun $run): bool => ($run->activity['stage'] ?? null) === 'attribute_dictionary')?->status);
    }

    public function test_two_characteristics_of_one_card_never_land_in_one(): void
    {
        AttributeKeeperAgent::fake(fn (): array => ['verdicts' => [['candidate' => 'memory_size', 'decision' => 'same', 'key' => 'ram',
            'name' => null, 'group' => null, 'remember' => true, 'reason' => 'Memory.']]])->preventStrayPrompts();

        $product = app(ProductDraftWorkflow::class)->approve($this->draft([$this->fact('ram', 'Memory', '16 GB'), $this->fact('memory_size', 'Memory size', '64 GB')]));

        $values = $product->variants()->sole()->attributes()->with('definition')->get()->concat($product->attributes()->with('definition')->get())
            ->mapWithKeys(fn ($value): array => [$value->definition->key => $value->value]);
        $this->assertSame('16 GB', $values['ram']);
        $this->assertSame('64 GB', $values['memory_size']);
        $this->assertNull(AttributeDefinition::query()->where('key', 'ram')->value('aliases'));
    }

    public function test_the_admin_review_applies_only_the_ticked_proposals(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        AttributeDefinition::query()->create(['key' => 'fans', 'label' => 'Fans']);
        AttributeDefinition::query()->create(['key' => 'cooling_fan_count', 'label' => 'Cooling fan count']);
        AttributeDefinition::query()->create(['key' => 'cooling_noise', 'label' => 'Noise']);
        AttributeDictionaryReviewAgent::fake(fn (): array => [
            'merges' => [['from' => 'fans', 'into' => 'cooling_fan_count', 'reason' => 'Одно и то же.'],
                ['from' => 'ram', 'into' => 'storage', 'reason' => 'Неверно: обе встроенные.']],
            'groups' => [['key' => 'cooling_noise', 'group' => ['id' => null, 'name' => 'Cooling', 'name_cs' => null], 'reason' => 'Охлаждение.'],
                ['key' => 'cooling_fan_count', 'group' => ['id' => null, 'name' => 'Cooling', 'name_cs' => null], 'reason' => 'Охлаждение.']],
        ])->preventStrayPrompts();

        $page = Livewire::test(ListAttributeDefinitions::class)->mountAction('reviewDictionary');
        // Two built-in keys are never offered for a merge; a characteristic going away gets no group.
        $review = json_decode((string) $page->get('mountedActions.0.data.review'), true);
        // Without a certainty the model gave, a proposal counts as doubtful: nothing is ticked for the operator.
        $this->assertSame([['from' => 'fans', 'into' => 'cooling_fan_count', 'certainty' => 'doubtful', 'reason' => 'Одно и то же.']], $review['merges']);
        $this->assertSame([], $page->get('mountedActions.0.data.merges'));
        $this->assertSame(['cooling_noise', 'cooling_fan_count'], array_column($review['groups'], 'key'));
        $page->setActionData(['merges' => [0], 'groups' => [0]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'fans']);
        $this->assertSame('Cooling', AttributeDefinition::query()->where('key', 'cooling_noise')->sole()->group?->name);
        $this->assertNull(AttributeDefinition::query()->where('key', 'cooling_fan_count')->sole()->attribute_group_id);
        $this->assertSame(1, $this->runs('attribute_dictionary_review'));
    }

    public function test_the_chat_merges_two_characteristics_on_request(): void
    {
        $update = TelegramUpdate::create(['update_id' => 880001, 'telegram_user_id' => '42', 'chat_id' => '900', 'message_id' => 1,
            'text' => 'merge', 'payload' => [], 'status' => 'processing']);
        config(['services.telegram.allowed_user_ids' => ['42']]);
        AttributeDefinition::query()->create(['key' => 'fans', 'label' => 'Fans']);
        $into = AttributeDefinition::query()->create(['key' => 'cooling_fan_count', 'label' => 'Cooling fan count']);

        $answer = json_decode((string) (new ManageCharacteristicDictionary($update))->handle(new Request(
            ['action' => 'merge', 'from_key' => 'fans', 'into_key' => 'cooling_fan_count'])), true);

        $this->assertTrue($answer['ok'], json_encode($answer));
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'fans']);
        $this->assertSame(['fans'], $into->fresh()->aliases);
    }

    private function runs(string $stage): int
    {
        return AiRun::query()->get()->filter(fn (AiRun $run): bool => ($run->activity['stage'] ?? null) === $stage)->count();
    }

    private function fact(string $key, string $name, string $value): array
    {
        return ['key' => $key, 'name' => $name, 'value' => $value, 'group' => null,
            'source_url' => 'https://source.example/product', 'source_excerpt' => $name.': '.$value.'.'];
    }

    private function draft(array $specifications): ProductDraft
    {
        $number = ++$this->sequence;
        $update = TelegramUpdate::create(['update_id' => 870000 + $number, 'telegram_user_id' => '42', 'chat_id' => '900',
            'message_id' => $number, 'text' => 'Dictionary fixture '.$number, 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test-fixture',
            'status' => 'completed', 'prompt' => 'Dictionary fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '42',
            'status' => 'pending_review', 'title' => 'Fixture laptop '.$number, 'brand' => 'Fixture', 'model' => 'FX-'.$number,
            'description' => 'A fixture laptop.', 'product_type' => 'laptop', 'category' => 'laptops', 'specifications' => $specifications,
            'sources' => [['title' => 'Fixture source', 'url' => 'https://source.example/product']], 'image_urls' => [],
            'confidence' => 0.95, 'primary_source_url' => 'https://source.example/product',
            'specifications_reconciled_source_url' => 'https://source.example/product',
        ]);
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/'.$draft->id.'/verified.webp',
            'source_url' => 'https://source.example/product.webp', 'role' => 'primary', 'mime_type' => 'image/webp',
            'width' => 4096, 'height' => 4096, 'file_size' => 1, 'checksum' => hash('sha256', 'dictionary-'.$number),
            'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

        return $draft;
    }
}
