<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Tools\ResearchProduct;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSpecificationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Decision of 2026-11-27: the condition the operator names for the item (used, refurbished,
 * open box) is the state of their own unit. The bot searches the model exactly as for a new
 * item and writes the condition into the card when it is saved; a page selling the product new
 * is a full match. Live #705: the search phrase ended in "used", the pages sold the module new
 * or recertified, and the card assembly refused the card over the condition.
 */
class UsedItemSearchedAsNewTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_705 = "DDR5 EC8 RDIMM\n\n16GB 1Rx8 PC5-5600B-RD0-1010-XT\n\nM321R2GA3EBO-CWMXJ\n\n2ks, použity";

    private const CDW = 'https://www.cdw.com/product/samsung-16gb-ddr5-5600-rdimm/9137460';

    private const SHOPBLT = 'https://www.shopblt.com/item/1.1v-16gb-1rx8-5600-cwm-ddr5-m321r2ga3eb0/samdrv_m321r2ga3eb0cwm.html';

    private const XAIPART = 'https://www.xaipart.com/products/m321r2ga3eb0-cwm';

    public function test_a_used_module_is_searched_as_new_and_saved_as_used(): void
    {
        $draft = $this->research();

        // The phrase research searches with carries no condition; the card carries the operator's.
        $prompt = AiRun::query()->where('activity->stage', 'research')->sole()->prompt;
        $query = preg_split('/\R/', $prompt)[0];
        $this->assertSame('Samsung M321R2GA3EBO-CWMXJ DDR5 EC8 RDIMM 16GB 1Rx8 PC5-5600B-RD0-1010-XT', $query);
        $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])(used|refurbished|second-hand|pre-owned|open box)(?![\p{L}])/iu', $query);
        $this->assertSame('used', $draft->condition);
        $this->assertNotContains('condition', array_column($draft->specifications, 'key'));
        // The observed offers' other condition stays recorded, as before.
        $this->assertContains('condition', array_column(ProductRequestContext::stored($draft)['differences'], 'requirement'));
        // And research is told not to look for used offers because of it.
        $this->assertStringContainsString('never search for used, refurbished or open-box offers because of it',
            (string) preg_replace('/\s+/', ' ', (string) (new ProductResearchAgent)->instructions()));
    }

    public function test_a_page_selling_the_module_new_completes_the_card_of_a_used_one(): void
    {
        $draft = $this->research();
        ProductSourcePageEvidence::query()->create(['product_draft_id' => $draft->id,
            'url_hash' => hash('sha256', ProductImageStorage::normalizeCandidateUrl(self::CDW)), 'url' => self::CDW,
            'specification_text' => "Mfg # M321R2GA3EBO-CWM\n# SAMSUNG 16GB DDR5-5600 RDIMM\nCondition: New", 'captured_via' => 'http']);
        $answer = $this->assembled($draft);
        $answer['specifications'][] = ['key' => 'condition', 'name' => 'Condition', 'value' => 'New', 'evidence' => 'confirmed', 'group' => null,
            'reason' => 'The selected offer is new.', 'source_kind' => 'web', 'source_ref' => null, 'source_url' => self::CDW,
            'source_excerpt' => 'Condition: New', 'considered_source_urls' => [], 'considered_source_refs' => []];
        ProductSpecificationReconciliationAgent::fake(fn (): array => $answer)->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft->fresh(), ['url' => self::CDW], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled(), (string) json_encode($outcome->repairContext['validation_errors'] ?? $outcome->summary, JSON_UNESCAPED_UNICODE));
        $this->assertNotContains('condition', array_column($outcome->specifications, 'key'));
        $this->assertSame('used', $draft->fresh()->condition);
    }

    public function test_the_condition_in_a_search_phrase_becomes_the_cards_condition(): void
    {
        $this->assertSame(['Samsung M321R2GA3EBO-CWMXJ DDR5 EC8 RDIMM 16GB 1Rx8 PC5-5600B-RD0-1010-XT', 'used'],
            ProductCondition::fromSearchQuery('Samsung M321R2GA3EBO-CWMXJ DDR5 EC8 RDIMM 16GB 1Rx8 PC5-5600B-RD0-1010-XT used', null));
        $this->assertSame(['Lenovo ThinkPad T14 Gen 4', 'refurbished'], ProductCondition::fromSearchQuery('Lenovo ThinkPad T14 Gen 4 renewed', null));
        $this->assertSame(['Lenovo ThinkPad T14 Gen 4', 'open_box'], ProductCondition::fromSearchQuery('Lenovo ThinkPad T14 Gen 4', 'open_box'));
        $this->assertSame(['Kingston FURY Beast 32GB', null], ProductCondition::fromSearchQuery('Kingston FURY Beast 32GB', null));
    }

    /** Request 705 and research run 3995's answer, shortened to the fields the card needs. */
    private function research(): ProductDraft
    {
        Storage::fake('public');
        Cache::flush();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]])]);
        Queue::fake();
        $update = TelegramUpdate::query()->create(['update_id' => 980705, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'message_id' => 705, 'text' => self::REQUEST_705, 'payload' => ['message' => ['text' => self::REQUEST_705]], 'status' => 'processing']);
        $fact = fn (string $key, string $name, string $value, string $url, string $excerpt): array => compact('key', 'name', 'value') + ['group' => null, 'source_url' => $url, 'source_excerpt' => $excerpt];
        ProductResearchAgent::fake([[
            'status' => 'found', 'title' => 'Samsung 16GB DDR5-5600 EC8 RDIMM 1Rx8 — M321R2GA3EBO-CWMXJ (Used)', 'brand' => 'Samsung',
            'model' => 'M321R2GA3EBO-CWMXJ', 'product_type' => 'component', 'category' => 'ram', 'color' => null, 'condition' => 'used',
            'description' => 'A 16 GB DDR5-5600 registered ECC server memory module.',
            'research_notes' => 'The exact operator-supplied code M321R2GA3EBO-CWMXJ was not found on an observed page. No used offer for the selected module was verified; the observed offers were backordered or factory recertified.',
            'label_code_misread' => null, 'code_aliases' => [], 'unconfirmed_operator_codes' => ['M321R2GA3EBO-CWMXJ'],
            'request_differences' => [
                ['requirement' => 'model', 'requested' => 'M321R2GA3EBO-CWMXJ', 'found' => 'M321R2GA3EBO-CWM', 'evidence' => 'CDW identifies the closest observed 16GB DDR5-5600 RDIMM as M321R2GA3EBO-CWM; the requested XJ suffix is not shown.'],
                ['requirement' => 'condition', 'requested' => 'Used', 'found' => 'No used offer verified; observed offers were backordered or factory recertified', 'evidence' => 'ShopBLT shows backorder availability; Aztek identifies its offer as factory recertified.'],
            ],
            'selected_identity' => ['basis' => 'full_model', 'model_scope' => 'single_product', 'observed_model' => 'M321R2GA3EBO-CWM', 'source_url' => self::CDW,
                'source_excerpt' => 'Mfg # M321R2GA3EBO-CWM', 'reason' => 'CDW identifies a specific 16GB DDR5-5600 RDIMM model; it is the closest observed configuration.'],
            'specifications' => [
                ['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => 'M321R2GA3EBO-CWMXJ', 'group' => null],
                $fact('brand', 'Brand', 'Samsung', self::CDW, '# SAMSUNG 16GB DDR5-5600 RDIMM'),
                $fact('ram', 'Memory', '16 GB', self::CDW, '# SAMSUNG 16GB DDR5-5600 RDIMM'),
                $fact('memory_type', 'Memory type', 'DDR5', self::SHOPBLT, 'Samsung M321R2GA3EB0-CWM M321R2GA3EB0CWM 16gb Ddr5-5600 Rdimm 1rx8 1.1v'),
                $fact('data_rate', 'Data rate', '5600 MT/s', self::SHOPBLT, 'Description: 16gb Ddr5-5600 Rdimm 1rx8 1.1v'),
                $fact('module_type', 'Module type', 'RDIMM', self::CDW, '# SAMSUNG 16GB DDR5-5600 RDIMM'),
                $fact('ecc_memory', 'ECC memory support', 'Yes, ECC registered', self::XAIPART, '16GB DDR5-5600 ECC Registered DIMM (RDIMM)'),
                ['key' => 'condition', 'name' => 'Condition', 'value' => 'Used', 'group' => null],
            ],
            'specification_evidence' => [],
            'sources' => [
                ['title' => 'SAMSUNG 16GB DDR5-5600 RDIMM - M321R2GA3EBO-CWM', 'url' => self::CDW, 'type' => 'retailer', 'roles' => ['facts', 'exact_photo'],
                    'applicability_reason' => 'Identifies the closest matching 16GB DDR5-5600 RDIMM code, missing only the requested XJ suffix.', 'image_urls' => []],
                ['title' => 'Samsung M321R2GA3EB0-CWM 16GB DDR5-5600 RDIMM 1Rx8 1.1V', 'url' => self::SHOPBLT, 'type' => 'retailer', 'roles' => ['facts'],
                    'applicability_reason' => 'Listing confirms a similar 16GB DDR5-5600 1Rx8 RDIMM and 1.1 V.', 'image_urls' => []],
                ['title' => 'M321R2GA3EB0-CWM - 16GB DDR5-5600 ECC RDIMM', 'url' => self::XAIPART, 'type' => 'web', 'roles' => ['facts'],
                    'applicability_reason' => 'Provides detailed specifications for the near-identical EB0-CWM code.', 'image_urls' => []],
            ],
            'primary_source_url' => self::CDW, 'official_source_url' => null, 'image_urls' => [], 'confidence' => 0.74,
        ]])->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft): int {
            $draft->update(['images_staged_at' => now(), 'gallery_status' => 'partial']);
            $path = "drafts/{$draft->id}/0.webp";
            Storage::disk('public')->put($path, 'photo');
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => self::CDW.'/0.webp', 'mime_type' => 'image/webp',
                'width' => 1600, 'height' => 1200, 'checksum' => hash('sha256', $path), 'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

            return 1;
        });
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('hasRemaining')->andReturn(false);
        $costBudget->shouldReceive('exceeded')->andReturn(false);

        // The assistant's real call of #705: the phrase ended in "used", no condition given.
        $result = json_decode((string) (new ResearchProduct($update, $resolver, imageStorage: $storage))->handle(new Request([
            'query' => 'Samsung M321R2GA3EBO-CWMXJ DDR5 EC8 RDIMM 16GB 1Rx8 PC5-5600B-RD0-1010-XT used',
        ])), true, flags: JSON_THROW_ON_ERROR);

        return ProductDraft::query()->findOrFail($result['draft_id']);
    }

    /** Every fact as research cited it, the operator's code from the request. */
    private function assembled(ProductDraft $draft): array
    {
        $rows = collect($draft->specifications)->map(fn (array $fact): array => $fact['key'] === 'mpn'
            ? ['key' => 'mpn', 'name' => $fact['name'], 'value' => $fact['value'], 'evidence' => 'confirmed', 'group' => null,
                'reason' => 'The operator typed this code; CDW shows M321R2GA3EBO-CWM without the XJ suffix.', 'source_kind' => 'operator_request',
                'source_ref' => null, 'source_url' => null, 'source_excerpt' => 'M321R2GA3EBO-CWMXJ', 'considered_source_urls' => [], 'considered_source_refs' => []]
            : ['key' => $fact['key'], 'name' => $fact['name'], 'value' => $fact['value'], 'evidence' => 'confirmed', 'group' => null,
                'reason' => 'Cited from the observed page.', 'source_kind' => 'web', 'source_ref' => null, 'source_url' => $fact['source_url'],
                'source_excerpt' => $fact['source_excerpt'], 'considered_source_urls' => [], 'considered_source_refs' => []])->values()->all();

        return ['offer_selection' => ['action' => 'retain_selected_offer', 'source_url' => self::CDW, 'reason' => 'The selected offer is the closest observed module.'],
            'overall_status' => 'reconciled', 'recovery_action' => 'none', 'unresolved_fields' => [], 'photo_applicability_conflict' => null,
            'summary' => 'Карточка собрана.', 'title' => $draft->title, 'model' => $draft->model, 'color' => null,
            'description' => 'A 16 GB DDR5-5600 registered ECC server memory module.', 'specifications' => $rows];
    }
}
