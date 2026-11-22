<?php

namespace Tests\Feature;

use App\Ai\Tools\SearchCatalog;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductCatalogIdentity;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductRequestContext;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request as ToolRequest;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * #449 and #454 on the second installation, 2026-11-22: the operator typed a sticker's line -
 * a lot number joined to the part number («9DE00441-VEUR548G6028K») - for a memory kit the
 * catalog already held as VEUR548G6028K with the same EAN. The catalog search did not see it,
 * a full search ran, the card called the line a mismatch, and the approval made a second
 * product with that EAN: the database refused the row and the chat got the raw query.
 */
class StickerLineCodeTest extends TestCase
{
    use RefreshDatabase;

    private const string EAN = '4711378430141';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        Queue::fake([StoreProductImages::class]);
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
    }

    public function test_a_code_is_a_whole_part_of_a_sticker_line_only_by_whole_segments(): void
    {
        $this->assertTrue(ProductCatalogIdentity::isWholePartOf('VEUR548G6028K', '9DE00441-VEUR548G6028K'));
        $this->assertTrue(ProductCatalogIdentity::isWholePartOf('VEUR548G6028K', '9DE0044.VEUR548G6028K'));
        $this->assertTrue(ProductCatalogIdentity::isWholePartOf('ksgek.002', 'NX.KSGEK.002'));
        $this->assertFalse(ProductCatalogIdentity::isWholePartOf('VEUR548G6028', '9DE00441-VEUR548G6028K'));
        $this->assertFalse(ProductCatalogIdentity::isWholePartOf('VEUR548G6028K', 'VEUR548G6028K'));
        $this->assertFalse(ProductCatalogIdentity::isWholePartOf('CTD', 'M471A4G43MB1-CTD'));
        $this->assertFalse(ProductCatalogIdentity::isWholePartOf('NX.KSGEK', 'NX.KSGEK.002'));
        $this->assertSame('VEUR548G6028K', ProductCatalogIdentity::keptCode('VEUR548G6028K', '9DE00441-VEUR548G6028K'));
        $this->assertSame('PVVR532G600C30K', ProductCatalogIdentity::keptCode('VEUR548G6028K', 'PVVR532G600C30K'));
    }

    public function test_a_repeat_written_as_a_sticker_line_joins_the_item_holding_its_ean_and_keeps_its_code(): void
    {
        $kit = app(ProductDraftWorkflow::class)->approve($this->draft('VEUR548G6028K', self::EAN));

        $again = app(ProductDraftWorkflow::class)->approve($this->draft('9DE00441-VEUR548G6028K', self::EAN));

        $this->assertSame($kit->id, $again->id);
        $this->assertSame(1, Product::query()->count());
        $variant = $kit->variants()->sole();
        $this->assertSame(['VEUR548G6028K', self::EAN], [$variant->mpn, $variant->gtin]);
        $this->assertSame('VEUR548G6028K', $kit->fresh()->model);
    }

    public function test_the_same_ean_under_another_code_stops_with_both_items_named(): void
    {
        $kit = app(ProductDraftWorkflow::class)->approve($this->draft('VEUR548G6028K', self::EAN));

        try {
            app(ProductDraftWorkflow::class)->approve($this->draft('PVVR532G600C30K', self::EAN));
            $this->fail('A second item with the same EAN was approved.');
        } catch (QueryException $exception) {
            $this->fail('The database refused the row: '.$exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('EAN '.self::EAN.' уже у товара #'.$kit->id, $exception->getMessage());
            $this->assertStringContainsString('артикул VEUR548G6028K', $exception->getMessage());
        }
        $this->assertSame(1, Product::query()->count());
    }

    public function test_a_code_inside_a_line_under_another_ean_is_another_item(): void
    {
        $kit = app(ProductDraftWorkflow::class)->approve($this->draft('VEUR548G6028K', self::EAN));

        $other = app(ProductDraftWorkflow::class)->approve($this->draft('9DE00441-VEUR548G6028K', '4711378430059'));

        $this->assertNotSame($kit->id, $other->id);
    }

    public function test_the_button_names_the_item_holding_the_ean_instead_of_the_query(): void
    {
        $kit = app(ProductDraftWorkflow::class)->approve($this->draft('VEUR548G6028K', self::EAN));
        $draft = $this->draft('PVVR532G600C30K', self::EAN);

        $this->press("draft:add:{$draft->id}", 5401);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'EAN '.self::EAN.' уже у товара #'.$kit->id)
            && ! str_contains((string) $request['text'], 'SQLSTATE'));
        $this->assertSame('pending_review', $draft->fresh()->status);
    }

    public function test_a_database_refusal_on_approval_is_logged_and_said_without_the_query_or_paths(): void
    {
        Exceptions::fake();
        $draft = $this->draft('VEUR548G6028K', self::EAN);
        $this->mock(ProductDraftWorkflow::class)->shouldReceive('approve')->andThrow(new QueryException('sqlite',
            'insert into "product_variants" ("gtin") values (?)', [self::EAN],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: product_variants.gtin, product_variants.condition'),
            ['database' => 'C:\\somewhere\\database.sqlite']));

        $this->press("draft:add:{$draft->id}", 5402);

        Exceptions::assertReported(QueryException::class);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Товар не добавлен: база данных отказала в записи (SQLSTATE[23000]')
            && ! str_contains((string) $request['text'], 'insert into') && ! str_contains((string) $request['text'], 'database.sqlite'));
    }

    public function test_the_catalog_search_sees_the_item_inside_a_sticker_line_and_names_the_rest(): void
    {
        $kit = app(ProductDraftWorkflow::class)->approve($this->draft('VEUR548G6028K', self::EAN));
        $search = fn (string $query): array => json_decode((string) (new SearchCatalog)->handle(new ToolRequest(['query' => $query])), true);

        $found = $search('9DE00441-VEUR548G6028K')['products'][0];

        $this->assertSame([$kit->id, true, 'VEUR548G6028K', '9DE00441-VEUR548G6028K', '9DE00441'],
            [$found['id'], $found['code_part_match'], $found['matched_code'], $found['query_code'], $found['extra_in_query']]);
        $this->assertArrayNotHasKey('exact_code_match', $found);
        // An exact code is still exact, and a shorter code of the query does not reach a longer one in the catalog.
        $this->assertTrue($search('VEUR548G6028K')['products'][0]['exact_code_match']);
        $this->assertArrayNotHasKey('code_part_match', $search('VEUR548G6028')['products'][0] ?? []);
    }

    public function test_the_card_says_a_sticker_line_holds_the_found_code_instead_of_a_mismatch(): void
    {
        $draft = $this->draft('9DE00441-VEUR548G6028K', self::EAN);
        ProductRequestContext::rememberDifferences($draft->telegramUpdate, [
            'request_differences' => [['requirement' => 'model', 'requested' => '9DE00441-VEUR548G6028K', 'found' => 'VEUR548G6028K',
                'evidence' => 'Observed product pages identify the kit as VEUR548G6028K.']],
            'unconfirmed_operator_codes' => ['9DE00441-VEUR548G6028K'],
        ]);

        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '98765', $draft->fresh());

        Http::assertSent(function (ClientRequest $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, '⚠️ Код из запроса «9DE00441-VEUR548G6028K» содержит код товара «VEUR548G6028K»; «9DE00441» на страницах нет')
                && ! str_contains($text, 'Не совпадает с запросом') && ! str_contains($text, 'не найден ни на одной странице');
        });
    }

    private function draft(string $mpn, string $ean): ProductDraft
    {
        $number = ++$this->sequence;
        $update = TelegramUpdate::query()->create(['update_id' => 54000 + $number, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'message_id' => $number, 'text' => $mpn, 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'fixture', 'status' => 'completed',
            'prompt' => $mpn, 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'status' => 'pending_review', 'title' => 'Patriot Viper Elite 5 Ultra RGB 48GB DDR5-6000 CL28 Memory Kit', 'brand' => 'Patriot', 'model' => $mpn,
            'color' => 'Black', 'description' => 'A memory kit.', 'product_type' => 'component', 'category' => 'ram', 'confidence' => 0.95,
            'specifications' => [['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => $mpn], ['key' => 'ean', 'name' => 'EAN', 'value' => $ean],
                ['key' => 'memory_type', 'name' => 'Memory type', 'value' => 'DDR5']],
            'sources' => [['title' => 'Fixture', 'url' => 'https://shop.example/kit']], 'image_urls' => [],
            'primary_source_url' => 'https://shop.example/kit', 'specifications_reconciled_source_url' => 'https://shop.example/kit']);
        $path = 'drafts/'.$draft->id.'/kit.webp';
        $image = imagecreatetruecolor(1200, 1200);
        ob_start();
        imagewebp($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put($path, $bytes);
        $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => 'https://shop.example/kit.webp', 'role' => 'primary',
            'mime_type' => 'image/webp', 'width' => 1200, 'height' => 1200, 'file_size' => strlen($bytes), 'checksum' => hash('sha256', $bytes.$number),
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
