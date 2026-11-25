<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductFormAssistantAgent;
use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Agents\TelegramDraftEditAgent;
use App\Ai\Agents\TelegramProductUploadAgent;
use App\Ai\Tools\ResearchProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Catalog\CatalogMap;
use App\Services\Catalog\CategoryPlacement;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductFormAssistant;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftFormActions;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision of 2026-11-25: one catalog map is the whole structure every agent and the chat place a
 * product by, the server settles the place by one rule, the card says when the kind is still open,
 * and what already sits in a parent with kinds is found and moved in the admin and the chat.
 * Live: a memory module and a server processor stood in «Комплектующие» beside the kinds made for them.
 */
class CatalogMapTest extends TestCase
{
    use RefreshDatabase;

    private const string SOURCE = 'https://shop.example/micron-rdimm';

    public function test_the_map_of_the_seeded_catalog_is_its_rule_and_one_line_per_node_with_kinds_under_their_parent(): void
    {
        $this->assertSame(<<<'MAP'
            Catalog map - where products go (slug: name [product_type]):
            Put a product in the deepest node that fits it. A node with kinds under it is chosen only when none of its kinds fits.
            category and product_type never contradict: a node's product_type is in brackets, and a kind has its parent's unless it shows its own.
            - laptops: Laptops [laptop]
            - computers: Desktop PCs [desktop]
            - components: Components [component]
              - ssd: SSD-накопители / SSD drives
              - hdd: Жёсткие диски / Hard drives
              - ram: Оперативная память / Memory modules
              - cpu: Процессоры / Processors
              - gpu: Видеокарты / Graphics cards
            - other-tech: Other electronics [other]
            MAP, app(CatalogMap::class)->forAgents());
        $this->assertSame(['laptops', 'computers', 'components', 'ssd', 'hdd', 'ram', 'cpu', 'gpu', 'other-tech'], app(CatalogMap::class)->slugs());
    }

    public function test_a_switched_off_category_is_left_out_and_a_kind_under_it_hangs_under_the_nearest_active_node(): void
    {
        $ram = Category::query()->where('slug', 'ram')->firstOrFail();
        Category::query()->create(['name' => 'DDR5 RDIMM', 'slug' => 'ddr5-rdimm', 'parent_id' => $ram->id, 'product_type_affinity' => 'component',
            'is_active' => true, 'sort_order' => 1]);
        Category::query()->where('slug', 'hdd')->update(['is_active' => false]);
        $map = app(CatalogMap::class);

        $this->assertStringContainsString("  - ram: Оперативная память / Memory modules\n    - ddr5-rdimm: DDR5 RDIMM\n", $map->forAgents());
        $this->assertSame('Components › Оперативная память › DDR5 RDIMM', $map->path('ddr5-rdimm'));

        $ram->update(['is_active' => false]);
        $map = app(CatalogMap::class);

        $this->assertStringNotContainsString('hdd', $map->forAgents());
        $this->assertStringNotContainsString('- ram:', $map->forAgents());
        $this->assertStringContainsString("  - ddr5-rdimm: DDR5 RDIMM\n", $map->forAgents());
        $this->assertNotContains('hdd', $map->slugs());
        $this->assertSame('Components › DDR5 RDIMM', $map->path('ddr5-rdimm'));
    }

    public function test_the_map_for_the_chat_carries_ids_and_names_the_nodes_whose_products_wait_for_a_kind(): void
    {
        $components = Category::query()->where('slug', 'components')->value('id');
        $ram = Category::query()->where('slug', 'ram')->value('id');

        $map = app(CatalogMap::class)->forAgents(withIds: true);

        $this->assertStringContainsString("- components (id {$components}): Components [component]", $map);
        $this->assertStringContainsString("  - ram (id {$ram}): Оперативная память / Memory modules", $map);
        $this->assertStringEndsWith("A product placed directly in a node with kinds waits for its kind: components (id {$components}).", $map);
    }

    public function test_the_forms_block_keeps_every_hint_form_and_note_the_operator_entered_by_slug(): void
    {
        Category::query()->where('slug', 'ram')->update(['card_standard' => 'No «Tray» in the title.']);

        $forms = app(CatalogMap::class)->formsForAgents();

        $this->assertStringContainsString('- ram: search hint: На наклейке модуля — P/N', $forms);
        $this->assertStringContainsString('form: mpn (Manufacturer part number; required), ram (Memory; required), memory_type (Memory type; required), data_rate (Data rate; required), module_type (Module type; required) | note: No «Tray» in the title.', $forms);
        $this->assertStringContainsString('A kind without a form of its own fills its parent\'s.', $forms);
        $this->assertStringNotContainsString('- computers:', $forms);
    }

    public function test_every_agent_and_chat_path_that_places_a_product_reads_the_same_map_and_takes_its_slugs_from_it(): void
    {
        $map = app(CatalogMap::class);
        $schema = new JsonSchemaTypeFactory;

        $research = (string) (new ProductResearchAgent)->instructions();
        $this->assertStringContainsString($map->forAgents(), $research);
        $this->assertStringContainsString($map->formsForAgents(), $research);
        $this->assertSame($map->slugs(), (new ProductResearchAgent)->schema($schema)['category']->toArray()['enum']);

        $this->assertStringContainsString($map->forAgents(), (string) (new TelegramProductUploadAgent)->instructions());
        $this->assertSame($map->slugs(), (new TelegramProductUploadAgent)->schema($schema)['category']->toArray()['enum']);
        $this->assertStringContainsString($map->forAgents(), (string) (new TelegramDraftEditAgent)->instructions());

        $assistant = (string) app(ServerAssistantAgent::class)->instructions();
        $this->assertStringContainsString($map->forAgents(withIds: true), $assistant);
        $this->assertStringContainsString('waits for its kind («товары без вида»', $assistant);

        $run = AiRun::query()->create(['provider' => 'openai', 'model' => 'test', 'status' => 'queued', 'prompt' => 'Категория?', 'started_at' => now(),
            'activity' => ['mode' => 'chat', 'locale' => 'ru', 'variant_key' => null, 'base' => [], 'history' => []]]);
        $prompt = json_decode(app(ProductFormAssistant::class)->prompt($run), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($map->forAgents(withIds: true), $prompt['catalog_map']);
        $this->assertArrayNotHasKey('categories', $prompt);
        $this->assertStringContainsString('category_id is the id of a node of catalog_map', (new ProductFormAssistantAgent)->instructions());
    }

    public function test_a_search_that_names_no_kind_ends_in_the_parent_and_the_card_says_so_until_the_operator_picks_one(): void
    {
        $draft = $this->researched(['category' => null]);

        $this->assertSame('components', $draft->category);
        $this->assertSame(['requested' => null, 'placed' => 'components', 'product_type' => 'component',
            'reason' => CategoryPlacement::NONE_CHOSEN, 'awaits_kind' => true], $draft->category_placement);
        $this->assertSame('📂 Components — вид не выбран (поиск не выбрал категорию)', app(DraftFormActions::class)->categoryLine($draft));

        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '98765', $draft);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($this->sentText($request), '📂 Components — вид не выбран (поиск не выбрал категорию)'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($this->sentText($request), "draft:kind:{$draft->id}"));

        // The menu offers the kinds; the press writes the kind as the operator's correction.
        $menu = app(DraftFormActions::class)->kindMenu($draft->fresh());
        $ram = Category::query()->where('slug', 'ram')->firstOrFail();
        $this->assertContains("dk:{$draft->id}:{$ram->id}", collect($menu['keyboard']['inline_keyboard'])->flatten(1)->pluck('callback_data')->all());
        $this->press("dk:{$draft->id}:{$ram->id}", 7101);

        $draft = $draft->fresh();
        $this->assertSame(['ram', 'component', null], [$draft->category, $draft->product_type, $draft->category_placement]);
        // A kind under the card's own category is the same product: the card's check stays.
        $this->assertSame(self::SOURCE, $draft->specifications_reconciled_source_url);
        $this->assertSame('📂 Components › Оперативная память', app(DraftFormActions::class)->categoryLine($draft));
        $this->assertNull(app(DraftFormActions::class)->kindButton($draft));
    }

    public function test_a_search_that_names_a_category_of_another_type_is_placed_by_the_type_and_the_card_names_the_cause(): void
    {
        $draft = $this->researched(['category' => 'laptops']);

        $this->assertSame('components', $draft->category);
        $this->assertSame(CategoryPlacement::TYPE_CONFLICT, $draft->category_placement['reason']);
        $this->assertSame('📂 Components — вид не выбран (поиск назвал «Laptops», а тип товара — комплектующая)',
            app(DraftFormActions::class)->categoryLine($draft));
    }

    public function test_a_search_that_chooses_the_parent_is_kept_there_and_the_kind_stays_open(): void
    {
        $draft = $this->researched(['category' => 'components']);

        $this->assertSame(['components', CategoryPlacement::CHOSEN, true],
            [$draft->category, $draft->category_placement['reason'], $draft->category_placement['awaits_kind']]);
        $this->assertSame('📂 Components — вид не выбран (поиск выбрал общую категорию)', app(DraftFormActions::class)->categoryLine($draft));
    }

    public function test_a_search_that_chooses_the_kind_keeps_it_and_the_card_only_names_the_place(): void
    {
        $draft = $this->researched(['category' => 'ram']);

        $this->assertSame(['ram', false], [$draft->category, $draft->category_placement['awaits_kind']]);
        $this->assertSame('📂 Components › Оперативная память', app(DraftFormActions::class)->categoryLine($draft));
    }

    public function test_the_approval_puts_the_product_where_the_card_said(): void
    {
        Storage::fake('public');
        Queue::fake();
        $draft = $this->draft('laptops', 'component');
        $this->assertStringStartsWith('📂 Components — вид не выбран (категория черновика «Laptops» — для другого типа товара',
            (string) app(DraftFormActions::class)->categoryLine($draft));

        $product = app(ProductDraftWorkflow::class)->approve($draft);

        $this->assertSame(['components', 'component'], [$product->category->slug, $product->product_type]);
    }

    public function test_the_admin_lists_products_waiting_for_a_kind_and_moves_them_in_one_action(): void
    {
        $this->admin();
        $waiting = $this->product('components', 'Micron MTC20F2085S1RC56BD2 32GB DDR5-5600 ECC Registered RDIMM');
        $placed = $this->product('ram', 'Kingston KVR32S22S8/16');

        $list = Livewire::test(ListProducts::class)->set('activeTab', 'awaiting_kind');
        $list->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$placed]);

        $ram = Category::query()->where('slug', 'ram')->value('id');
        $list->callTableBulkAction('moveToCategory', [$waiting], data: ['category_id' => $ram])->assertHasNoTableBulkActionErrors();

        $this->assertSame([$ram, 'component'], [$waiting->fresh()->category_id, $waiting->fresh()->product_type]);
        $list->assertCanNotSeeTableRecords([$waiting]);
    }

    public function test_the_chat_lists_products_waiting_for_a_kind_and_moves_one_with_its_data_tools(): void
    {
        $waiting = $this->product('components', 'Intel Xeon Gold 5416S Server Processor');
        $this->product('cpu', 'Intel Core i5-12400F');
        $components = Category::query()->where('slug', 'components')->value('id');
        $cpu = Category::query()->where('slug', 'cpu')->value('id');
        $operator = app(DatabaseOperatorService::class);

        $found = $operator->query('products', ['filters' => [['column' => 'category_id', 'operator' => 'in', 'value' => [$components]]]], ['id', 'title']);
        $this->assertSame([['id' => $waiting->id, 'title' => 'Intel Xeon Gold 5416S Server Processor']], $found['rows']);

        $operator->execute($operator->prepare([['action' => 'update', 'table' => 'products', 'selection' => ['ids' => [$waiting->id]],
            'values' => ['category_id' => $cpu]]]));

        $this->assertSame([$cpu, 'component'], [$waiting->fresh()->category_id, $waiting->fresh()->product_type]);
    }

    /** A research answer for a memory module, with the given fields; returns the draft it made. */
    private function researched(array $overrides): ProductDraft
    {
        Storage::fake('public');
        Cache::flush();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]])]);
        Queue::fake();
        ProductResearchAgent::fake([[
            'status' => 'found', 'title' => 'Micron 32GB DDR5-5600 ECC Registered RDIMM', 'brand' => 'Micron', 'model' => 'MTC20F2085S1RC56BD2',
            'product_type' => 'component', 'category' => 'ram', 'color' => null, 'condition' => 'new',
            'description' => 'A 32 GB DDR5 registered server memory module.', 'research_notes' => null,
            'specifications' => [['key' => 'mpn', 'name' => 'Part number', 'value' => 'MTC20F2085S1RC56BD2', 'source_url' => self::SOURCE, 'source_excerpt' => 'MTC20F2085S1RC56BD2']],
            'sources' => [['title' => 'Micron RDIMM', 'url' => self::SOURCE, 'type' => 'retailer', 'roles' => ['facts', 'exact_photo'],
                'applicability_reason' => 'Sells this module.', 'image_urls' => []]],
            'primary_source_url' => self::SOURCE, 'official_source_url' => null, 'image_urls' => [], 'confidence' => 0.9,
            'selected_identity' => $this->observedIdentity('MTC20F2085S1RC56BD2', self::SOURCE),
            ...$overrides,
        ]])->preventStrayPrompts();
        $update = TelegramUpdate::query()->create(['update_id' => 7100, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'message_id' => 10, 'text' => 'MTC20F2085S1RC56BD2', 'payload' => [], 'status' => 'processing']);
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft): int {
            $draft->update(['images_staged_at' => now(), 'gallery_status' => 'complete', 'specifications_reconciled_source_url' => $draft->primary_source_url]);
            $path = "drafts/{$draft->id}/0.webp";
            Storage::disk('public')->put($path, 'photo');
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => self::SOURCE.'/0.webp', 'mime_type' => 'image/webp',
                'width' => 1600, 'height' => 1200, 'checksum' => hash('sha256', $path), 'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

            return 1;
        });
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('hasRemaining')->andReturn(false);
        $costBudget->shouldReceive('exceeded')->andReturn(false);

        $result = json_decode((string) (new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => $update->text])), true, flags: JSON_THROW_ON_ERROR);

        return ProductDraft::query()->findOrFail($result['draft_id']);
    }

    private function draft(string $category, string $productType): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => 7200, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'message_id' => 1,
            'text' => 'Module', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test', 'status' => 'completed',
            'prompt' => 'Find', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'status' => 'pending_review', 'title' => 'Micron 32GB DDR5 RDIMM', 'brand' => 'Micron', 'model' => 'MTC20F2085S1RC56BD2',
            'description' => 'A module.', 'product_type' => $productType, 'category' => $category, 'condition' => 'new', 'confidence' => 0.9,
            'image_urls' => [], 'images_staged_at' => now(), 'gallery_status' => 'complete', 'specifications' => [],
            'sources' => [['title' => 'Micron', 'url' => self::SOURCE]], 'primary_source_url' => self::SOURCE,
            'specifications_reconciled_source_url' => self::SOURCE]);
        $image = imagecreatetruecolor(800, 800);
        ob_start();
        imagewebp($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put('drafts/'.$draft->id.'/a.webp', $bytes);
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/'.$draft->id.'/a.webp', 'source_url' => self::SOURCE.'/a.webp',
            'role' => 'primary', 'mime_type' => 'image/webp', 'width' => 800, 'height' => 800, 'file_size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes), 'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

        return $draft;
    }

    private function product(string $category, string $title): Product
    {
        return Product::query()->create(['title' => $title, 'category_id' => Category::query()->where('slug', $category)->value('id'),
            'product_type' => 'component', 'status' => 'published', 'is_active' => true, 'published_at' => now()]);
    }

    private function admin(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        app()->setLocale('ru');
    }

    /** The words and buttons of a request to Telegram, however it was encoded (JSON, form parts, an album's media). */
    private function sentText(ClientRequest $request): string
    {
        $data = $request->data();
        $fields = array_is_list($data) ? collect($data)->mapWithKeys(fn (array $part): array => [(string) ($part['name'] ?? '') => $part['contents'] ?? ''])->all() : $data;
        $decoded = fn (mixed $value): mixed => is_string($value) ? (json_decode($value, true) ?? $value) : $value;

        return json_encode([$fields['text'] ?? null, $fields['caption'] ?? null, $decoded($fields['media'] ?? null), $decoded($fields['reply_markup'] ?? null)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function press(string $data, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'callback-'.$updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'data' => $data,
            'message' => ['message_id' => 901, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
