<?php

namespace Tests\Feature;

use App\Ai\Agents\FormAdvisorAgent;
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
use App\Services\Catalog\FormProposals;
use App\Services\Products\ProductDraftWorkflow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: what a product has beyond its category's form is put to the operator -
 * in Telegram after the approval, and on the category's page - and in the strict mode only the
 * form is written until the operator says otherwise. Decision of 2026-11-16: a model looks at the
 * product against the form and picks what is worth asking, with a reason; any form is completed.
 */
class FormProposalsTest extends TestCase
{
    use RefreshDatabase;

    private Category $ram;

    private int $sequence = 0;

    /** @var list<string> what the form advisor was asked */
    private array $prompts = [];

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
        $this->advise(['rank' => 'по нему выбирают модули', 'voltage' => 'отличает модули одной серии']);
    }

    public function test_after_the_approval_the_operator_is_asked_about_what_the_model_picked(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        Http::assertSent(function (ClientRequest $request) use ($product): bool {
            $buttons = collect((array) data_get($request->data(), 'reply_markup.inline_keyboard', []))->flatten(1)->pluck('callback_data')->all();

            return str_contains((string) ($request['text'] ?? ''), 'В форму «Оперативная память» стоит добавить то, что нашлось у товара #'.$product->id)
                && str_contains((string) $request['text'], 'Rank — 2Rx8 (по нему выбирают модули)') && ! str_contains((string) $request['text'], 'HMA82GR7')
                && in_array('chqp:y:'.$product->id, $buttons, true);
        });
        // The model saw the form, the whole card and only what the form lacks as candidates.
        $sent = json_decode($this->prompts[0], true);
        $this->assertSame(['rank', 'voltage'], array_column($sent['candidates'], 'key'));
        $this->assertSame('memory_type', $sent['form'][0]['key']);
        $this->assertContains('HMA82GR7CJR8N-VK', array_column($sent['product']['characteristics'], 'value'));
        $rank = $this->question('rank');
        $voltage = $this->question('voltage');

        $this->press('chq:y:'.$rank->id, 7001);
        $this->press('chq:n:'.$voltage->id, 7002);

        $form = app(CategoryStandard::class)->formRows($this->ram->fresh());
        $this->assertContains($this->definition('rank')->id, array_column($form, 'attribute_definition_id'));
        $this->assertSame('2Rx8', collect($form)->firstWhere('attribute_definition_id', $this->definition('rank')->id)['example']);
        $this->assertSame(CategoryStandard::MAIN, collect($form)->firstWhere('attribute_definition_id', $this->definition('rank')->id)['section']);
        $this->assertNotContains($this->definition('voltage')->id, array_column($form, 'attribute_definition_id'));

        // The next product of the category is not asked about what was declined, and the model knows it was.
        CharacteristicQuestion::query()->where('status', 'open')->delete();
        app(ProductDraftWorkflow::class)->approve($this->draft('HMA82GR7-SECOND'));
        $this->assertSame(0, CharacteristicQuestion::query()->open()->get()
            ->filter(fn (CharacteristicQuestion $question): bool => $question->payload['key'] === 'voltage')->count());
        $this->assertNull($this->prompts[1] ?? null, 'Nothing was left to ask about the second product.');
    }

    public function test_only_what_the_model_picks_is_asked(): void
    {
        $this->advise(['rank' => 'по нему выбирают модули', 'warp_drive' => 'такой характеристики у товара нет']);

        app(ProductDraftWorkflow::class)->approve($this->draft());

        $this->assertSame(['rank'], CharacteristicQuestion::query()->pluck('payload')->pluck('key')->all());
        // Voltage was neither asked nor declined: the next product of the category brings it to the model again.
        app(ProductDraftWorkflow::class)->approve($this->draft('HMA82GR7-SECOND'));
        $this->assertSame(['voltage'], array_column(json_decode($this->prompts[1], true)['candidates'], 'key'));
    }

    public function test_all_proposals_about_a_product_are_answered_at_once(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        $this->press('chqp:y:'.$product->id, 7003);

        $keys = AttributeDefinition::query()->whereIn('id', array_column(app(CategoryStandard::class)->formRows($this->ram->fresh()), 'attribute_definition_id'))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['memory_type', 'rank', 'voltage'], $keys);
        $this->assertSame(0, CharacteristicQuestion::query()->open()->count());
    }

    public function test_all_at_once_answers_only_the_proposals_the_message_shows(): void
    {
        $product = 4321;
        foreach (range(1, FormProposals::PER_MESSAGE + 2) as $number) {
            $definition = $this->definition('extra_'.$number);
            CharacteristicQuestion::query()->create(['kind' => CharacteristicQuestion::FORM, 'payload' => [
                'category_id' => $this->ram->id, 'category' => $this->ram->name, 'definition_id' => $definition->id, 'key' => $definition->key,
                'name' => 'Extra '.$number, 'value' => 'v'.$number, 'product_id' => $product, 'held' => false, 'unit' => null, 'variant_id' => null]]);
        }
        $this->assertStringContainsString('Ещё 2 — после ответа на эти.', app(FormProposals::class)->message($product)['text']);

        $this->press('chqp:n:'.$product, 7005);

        $this->assertSame(FormProposals::PER_MESSAGE, CharacteristicQuestion::query()->where('status', 'declined')->count());
        $this->assertSame(['extra_9', 'extra_10'], array_column(array_column(app(FormProposals::class)->open($product), 'payload'), 'key'));
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], '• Extra 9 — v9') && ! str_contains((string) $request['text'], '• Extra 8 — v8')
            && collect((array) data_get($request->data(), 'reply_markup.inline_keyboard', []))->flatten(1)->contains('callback_data', 'chqp:y:'.$product));
    }

    public function test_a_category_without_a_form_gets_its_first_rows_proposed(): void
    {
        foreach (array_filter([$this->ram, $this->ram->parent]) as $category) {
            $category->attributeDefinitions()->newPivotQuery()->update(['is_default' => false, 'is_required' => false]);
        }
        $this->advise(['memory_type' => 'без него карточка модуля пуста', 'rank' => 'по нему выбирают модули']);

        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''),
            'У категории «Оперативная память» ещё нет формы. Из найденного у товара #'.$product->id.' в неё стоит взять:'));
        $this->press('chqp:y:'.$product->id, 7006);
        $form = app(CategoryStandard::class)->formRows($this->ram->fresh());
        $this->assertEqualsCanonicalizing([$this->definition('memory_type')->id, $this->definition('rank')->id], array_column($form, 'attribute_definition_id'));
        $this->assertSame('DDR4', collect($form)->firstWhere('attribute_definition_id', $this->definition('memory_type')->id)['example']);
    }

    public function test_a_model_that_cannot_be_reached_asks_nothing_and_the_product_stays(): void
    {
        FormAdvisorAgent::fake(fn () => throw new RuntimeException('provider down'))->preventStrayPrompts();

        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        $this->assertNotNull($product->fresh());
        $this->assertSame(0, CharacteristicQuestion::query()->count());
        $this->assertSame('failed', AiRun::query()->where('activity->stage', 'form_proposals')->sole()->status);
    }

    public function test_the_strict_mode_writes_the_form_and_a_yes_writes_a_held_value(): void
    {
        $this->ram->update(['form_only' => true]);

        $product = app(ProductDraftWorkflow::class)->approve($this->draft());

        $keys = fn (Product $product): array => $product->attributes()->with('definition')->get()
            ->concat($product->variants()->sole()->attributes()->with('definition')->get())->pluck('definition.key')->sort()->values()->all();
        $this->assertSame(['memory_type', 'mpn'], $keys($product));
        $rank = $this->question('rank');
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
        $rank = $this->question('rank');

        Livewire::test(EditCategory::class, ['record' => $this->ram->id])
            ->mountAction('formProposals')->setActionData(['add' => [$rank->id]])->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame('applied', $rank->fresh()->status);
        $this->assertSame(0, CharacteristicQuestion::query()->open()->count());
        $this->assertSame(1, CharacteristicQuestion::query()->where('status', 'declined')->count());
    }

    /** @param array<string, string> $picks key => reason; the first in the main section, the rest extra */
    private function advise(array $picks): void
    {
        $this->prompts = [];
        FormAdvisorAgent::fake(function (string $prompt) use ($picks): array {
            $this->prompts[] = $prompt;
            $candidates = array_column(json_decode($prompt, true)['candidates'] ?? [], 'key');
            $proposals = [];
            foreach ($picks as $key => $reason) {
                // A real model answers about the candidates it was given, and may invent one; never about a form row.
                if (in_array($key, $candidates, true) || ! AttributeDefinition::query()->where('key', $key)->exists()) {
                    $proposals[] = ['key' => $key, 'reason' => $reason, 'section' => $proposals === [] ? 'main' : 'extra'];
                }
            }

            return ['proposals' => $proposals];
        })->preventStrayPrompts();
    }

    private function question(string $key): CharacteristicQuestion
    {
        return CharacteristicQuestion::query()->get()->firstOrFail(fn (CharacteristicQuestion $question): bool => $question->payload['key'] === $key);
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
