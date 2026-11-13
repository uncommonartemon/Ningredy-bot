<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\CharacteristicQuestion;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Catalog\CategoryStandard;
use App\Services\Products\ProductDraftWorkflow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: what a product has beyond its category's form is put to the operator -
 * in Telegram after the approval, and on the category's page - and in the strict mode only the
 * form is written until the operator says otherwise.
 */
class FormProposalsTest extends TestCase
{
    use RefreshDatabase;

    private Category $ram;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        Queue::fake([StoreProductImages::class]);
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 950]])]);
        $this->ram = Category::query()->where('slug', 'ram')->firstOrFail();
        app(CategoryStandard::class)->syncForm($this->ram, [
            ['attribute_definition_id' => $this->definition('memory_type')->id, 'example' => 'DDR4 / DDR5', 'is_required' => true],
        ]);
    }

    public function test_after_the_approval_the_operator_is_asked_about_what_the_form_lacks(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        Http::assertSent(function (ClientRequest $request) use ($product): bool {
            $buttons = collect((array) data_get($request->data(), 'reply_markup.inline_keyboard', []))->flatten(1)->pluck('callback_data')->all();

            return str_contains((string) ($request['text'] ?? ''), 'нет характеристик, которые нашлись у товара #'.$product->id)
                && str_contains((string) $request['text'], 'Rank — 2Rx8') && ! str_contains((string) $request['text'], 'HMA82GR7')
                && in_array('chqp:y:'.$product->id, $buttons, true);
        });
        $rank = CharacteristicQuestion::query()->get()->first(fn (CharacteristicQuestion $question): bool => $question->payload['key'] === 'rank');
        $voltage = CharacteristicQuestion::query()->get()->first(fn (CharacteristicQuestion $question): bool => $question->payload['key'] === 'voltage');

        $this->press('chq:y:'.$rank->id, 7001);
        $this->press('chq:n:'.$voltage->id, 7002);

        $form = app(CategoryStandard::class)->formRows($this->ram->fresh());
        $this->assertContains($this->definition('rank')->id, array_column($form, 'attribute_definition_id'));
        $this->assertSame('2Rx8', collect($form)->firstWhere('attribute_definition_id', $this->definition('rank')->id)['example']);
        $this->assertNotContains($this->definition('voltage')->id, array_column($form, 'attribute_definition_id'));

        // The next product of the category is not asked about what was declined.
        CharacteristicQuestion::query()->where('status', 'open')->delete();
        app(ProductDraftWorkflow::class)->approve($this->draft('HMA82GR7-SECOND'));
        $this->assertSame(0, CharacteristicQuestion::query()->open()->get()
            ->filter(fn (CharacteristicQuestion $question): bool => $question->payload['key'] === 'voltage')->count());
    }

    public function test_all_proposals_about_a_product_are_answered_at_once(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        $this->press('chqp:y:'.$product->id, 7003);

        $keys = AttributeDefinition::query()->whereIn('id', array_column(app(CategoryStandard::class)->formRows($this->ram->fresh()), 'attribute_definition_id'))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['memory_type', 'rank', 'voltage'], $keys);
        $this->assertSame(0, CharacteristicQuestion::query()->open()->count());
    }

    public function test_a_form_nobody_worked_on_brings_no_proposals(): void
    {
        $this->ram->attributeDefinitions()->newPivotQuery()->update(['example' => null]);

        app(ProductDraftWorkflow::class)->approve($this->draft());

        $this->assertSame(0, CharacteristicQuestion::query()->count());
    }

    public function test_the_strict_mode_writes_the_form_and_a_yes_writes_a_held_value(): void
    {
        $this->ram->update(['form_only' => true]);

        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        $keys = fn (Product $product): array => $product->attributes()->with('definition')->get()
            ->concat($product->variants()->sole()->attributes()->with('definition')->get())->pluck('definition.key')->sort()->values()->all();
        $this->assertSame(['memory_type', 'mpn'], $keys($product));
        $rank = CharacteristicQuestion::query()->get()->first(fn (CharacteristicQuestion $question): bool => $question->payload['key'] === 'rank');
        $this->assertTrue($rank->payload['held']);

        $this->press('chq:y:'.$rank->id, 7004);

        $this->assertSame(['memory_type', 'mpn', 'rank'], $keys($product->fresh()));
    }

    public function test_the_category_page_lists_the_proposals_ticked_ones_join_the_form(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $draft = $this->draft();
        $draft->telegramUpdate->update(['chat_id' => '']);
        $draft->update(['telegram_review_chat_id' => null]);
        app(ProductDraftWorkflow::class)->approve($draft->fresh());
        $rank = CharacteristicQuestion::query()->get()->first(fn (CharacteristicQuestion $question): bool => $question->payload['key'] === 'rank');

        Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->mountAction('formProposals')->setActionData(['add' => [$rank->id]])->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame('applied', $rank->fresh()->status);
        $this->assertSame(0, CharacteristicQuestion::query()->open()->count());
        $this->assertSame(1, CharacteristicQuestion::query()->where('status', 'declined')->count());
    }

    private function definition(string $key): AttributeDefinition
    {
        return AttributeDefinition::query()->firstOrCreate(['key' => $key], ['label' => ucfirst($key), 'data_type' => 'text']);
    }

    private function draft(string $mpn = 'HMA82GR7CJR8N-VK'): ProductDraft
    {
        $number = ++$this->sequence;
        $update = TelegramUpdate::create(['update_id' => 890000 + $number, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'message_id' => $number,
            'text' => 'RAM fixture', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test-fixture', 'status' => 'completed',
            'prompt' => 'RAM fixture', 'started_at' => now(), 'completed_at' => now()]);
        $specifications = [['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => $mpn], ['key' => 'memory_type', 'name' => 'Memory type', 'value' => 'DDR4'],
            ['key' => 'rank', 'name' => 'Rank', 'value' => '2Rx8'], ['key' => 'voltage', 'name' => 'Voltage', 'value' => '1.2 V']];
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'status' => 'pending_review', 'title' => 'Fixture module '.$number, 'brand' => 'SK hynix', 'model' => $mpn, 'description' => 'A module.',
            'product_type' => 'component', 'category' => 'ram', 'confidence' => 0.95, 'image_urls' => [],
            'specifications' => array_map(fn (array $spec): array => [...$spec, 'source_url' => 'https://source.example/m', 'source_excerpt' => $spec['value']], $specifications),
            'sources' => [['title' => 'Fixture', 'url' => 'https://source.example/m']], 'primary_source_url' => 'https://source.example/m',
            'specifications_reconciled_source_url' => 'https://source.example/m']);
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/'.$draft->id.'/a.webp', 'source_url' => 'https://source.example/a.webp',
            'role' => 'primary', 'mime_type' => 'image/webp', 'width' => 2000, 'height' => 2000, 'file_size' => 1, 'checksum' => hash('sha256', 'p'.$number),
            'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

        return $draft;
    }

    private function press(string $data, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'callback-'.$updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'data' => $data,
            'message' => ['message_id' => 950, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
