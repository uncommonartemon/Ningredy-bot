<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductInputContext;
use App\Services\Products\ProductSpecificationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card assembly answers of the second installation's report (2026-11-27), replayed against the
 * server's checks: what the model answered, set beside what the server now makes of it.
 */
class CardAssemblyReplayTest extends TestCase
{
    use RefreshDatabase;

    /** Request 705 as the operator sent it: a used server memory module. */
    private const REQUEST_705 = "DDR5 EC8 RDIMM\n\n16GB 1Rx8 PC5-5600B-RD0-1010-XT\n\nM321R2GA3EBO-CWMXJ\n\n2ks, použity";

    private const ENTERASOURCE = 'https://enterasource.com/samsung-m321r2ga3eb0-cwmxj-16gb-1rx8-pc5-5600b-r-dimm';

    private const MEMORYSOLUTION = 'https://www.memorysolution.de/samsung/16gb-samsung-ddr5-5600-cl46-2gx8-ecc-reg.-sr/AD5B16GSR5600E46';

    private const IREPTECH = 'https://ireptech.com/product-detail/M321R2GA3EB0-CWMXJ?conditions=%5B%7B%22condition%22%3A%22REF%22%2C%22manufacturer%22%3Anull%7D%5D&description=Samsung+16GB+1Rx8+DDR5+RDIMM%2C+5600MT%2Fs&manufacturers=%5B%7B%22condition%22%3Anull%2C%22manufacturer%22%3A%22DELL%22%7D%5D&name=Samsung+16GB+1Rx8+DDR5+RDIMM%2C+5600MT%2Fs';

    private const GALLERY = 'https://industry-electronics.de/samsung/m321r2ga3eb0-cwm-16gb-ddr5-5600-cl46-2gx8-ecc-reg.-sr-lieske_2517324.htm';

    public function test_run_4128_no_longer_fails_on_form_fields_without_a_value_and_meets_the_typed_code_rule(): void
    {
        // Run 4128 (#706) reconciled the card, but three form fields came back without a value,
        // validation refused the whole answer, the repair returned them empty again and the
        // card was lost. The same answer also replaced the code the operator typed (letter O)
        // with the page's (digit 0): that is refused by the rule of 2026-11-13, not by the
        // empty fields any more.
        $draft = $this->draft706();
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->answer4128())->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => self::GALLERY], $draft->telegram_update_id);

        $this->assertFalse($outcome->isReconciled());
        $errors = json_encode($outcome->repairContext['validation_errors'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('specifications.13.value', $errors);
        $this->assertStringContainsString('A commercial identifier changed', $errors);
        $run = AiRun::query()->where('activity->stage', 'card_assembly')->latest('id')->firstOrFail();
        $this->assertSame(['memory_timings', 'rgb_lighting', 'xmp_support'], $run->activity['fields_without_value']);
    }

    public function test_run_4128_with_the_operators_code_kept_is_reconciled_and_keeps_the_operators_condition(): void
    {
        // The same answer with the one row the corrected instructions ask for differently: the
        // card keeps the code the operator typed, cited from the request (which, since the
        // request is the earlier message, is there to cite). The form fields nobody found
        // leave the card, which then lists them as not found; the offer's "Refurbished" row
        // does not overwrite the condition the operator named.
        $draft = $this->draft706();
        $answer = $this->answer4128();
        $answer['model'] = 'M321R2GA3EBO-CWMXJ';
        $answer['title'] = 'Samsung M321R2GA3EBO-CWMXJ 16 GB DDR5-5600 ECC Registered Memory Module';
        $answer['specifications'][0] = ['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => 'M321R2GA3EBO-CWMXJ', 'evidence' => 'confirmed',
            'group' => null, 'reason' => 'The operator typed this code; the observed pages show M321R2GA3EB0-CWMXJ with the digit 0.',
            'source_kind' => 'operator_request', 'source_ref' => null, 'source_url' => null, 'source_excerpt' => 'M321R2GA3EBO-CWMXJ',
            'considered_source_urls' => [self::IREPTECH], 'considered_source_refs' => []];
        ProductSpecificationReconciliationAgent::fake(fn (): array => $answer)->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => self::GALLERY], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled(), (string) json_encode($outcome->repairContext['validation_errors'] ?? $outcome->summary, JSON_UNESCAPED_UNICODE));
        $keys = array_column($outcome->specifications, 'key');
        $this->assertNotContains('condition', $keys);
        $this->assertSame([], array_intersect(['memory_timings', 'rgb_lighting', 'xmp_support'], $keys));
        $this->assertSame('M321R2GA3EBO-CWMXJ', collect($outcome->specifications)->firstWhere('key', 'mpn')['value']);
        $this->assertSame('used', $draft->fresh()->condition);
    }

    public function test_three_writings_of_one_kit_capacity_need_no_account(): void
    {
        // Run 3858 (#687): the research wrote "32 GB (2 × 16 GB)" from G.SKILL's sheet, a shop
        // "32GB (2 × 16GB)", and the assembly kept the first; the server demanded the shop be
        // accounted for as a disagreement, and a repair was paid for a difference of spaces.
        $gskill = 'https://www.gskill.com/specification/165/396/1765260340/F5-6000J3038F16GH2-FX5-Specification';
        $draft = $this->draft([
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '32 GB (2 × 16 GB)', 'source_url' => $gskill, 'source_excerpt' => 'Capacity 32GB (16GBx2)']],
            'specification_evidence' => [['key' => 'ram', 'name' => 'Memory', 'value' => '32GB (2 × 16GB)',
                'source_url' => 'https://evergreencomputer.com.hk/g-skill-g-skill-flare-x5-ddr5-32gb-2x16gb-6000mt-s-dimm-amd-expo-f5-6000j3038f16gh2-fx5.html',
                'source_excerpt' => 'G.Skill RAM Flare X5 - 32 GB (2 x 16 GB Kit) — MPN F5-6000J3038F16GH2-FX5; EAN 4711549510214',
                'applicability' => 'The page identifies the exact part number and kit capacity.', 'source_type' => 'retailer', 'scope' => 'exact_configuration']],
        ]);
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->card([
            ['key' => 'ram', 'name' => 'Memory', 'value' => '32 GB (2 × 16 GB)', 'evidence' => 'confirmed', 'group' => null,
                'reason' => 'Exact model and kit capacity are stated in the manufacturer specification.', 'source_kind' => 'web', 'source_ref' => null,
                'source_url' => $gskill, 'source_excerpt' => 'Capacity 32GB (16GBx2)', 'considered_source_urls' => [], 'considered_source_refs' => []],
        ]))->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://www.gskill.com/product/165/396/1765260340/F5-6000J3038F16GH2-FX5'], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled(), (string) json_encode($outcome->repairContext['validation_errors'] ?? null));
    }

    public function test_sources_stating_the_same_amount_in_more_or_less_detail_are_listed_by_the_server(): void
    {
        // Run 3975 (#693): the card's memory, "64 GB (1 × 64 GB)" from C2 Computer, carried a
        // reason; SBiT wrote the same and SK hynix's datasheet "64 GB; 2 ranks; x4
        // organization", and the answer failed for not listing them - again after a repair
        // had listed them once. A source with another amount is still the agent's to explain.
        $sbit = 'https://static.sbit.com.tw/en/productsearch.aspx?_searchtext=HMAA8GR7CJR4N-XN';
        $hynix = 'https://product.skhynix.com/files/pcms/Products/TechnicalResources/DataSheet/TR-20201119010109004.pdf';
        $c2 = 'https://www.c2-computer.com/collections/collection-server-parts-latest/products/hynix-hmaa8gr7cjr4n-xn-64gb-pc4-25600-ddr4-3200mt-s-2rx4-ecc-memory-new-brand-new';
        $draft = $this->draft([
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '64 GB (1 × 64 GB)', 'source_url' => $sbit, 'source_excerpt' => 'DDR4-3200AA 8Gx72 ECC Reg/RDIMM 2Rx4 (64GB).']],
            'specification_evidence' => [['key' => 'ram', 'name' => 'Memory', 'value' => '64 GB; 2 ranks; x4 organization', 'source_url' => $hynix,
                'source_excerpt' => 'HMAA8GR7CJR4N - WM/XN | 64GB | 8Gx72 | 4Gx4(H5ANAG4NCJR)*36 | 2.', 'applicability' => 'Official datasheet identifies the HMAA8GR7CJR4N family with 64GB.',
                'source_type' => 'manufacturer', 'scope' => 'product_family']],
        ]);
        $this->page($draft, $c2, '64GB DDR4-3200MT/s ECC 2Rx4 New Model Hynix HMAA8GR7CJR4N-XN Capacity 64GB');
        $row = ['key' => 'ram', 'name' => 'Memory', 'value' => '64 GB (1 × 64 GB)', 'evidence' => 'confirmed', 'group' => null,
            'reason' => 'The selected gallery identifies a 64GB module; the one-module configuration is supported by the matching model-family listing.',
            'source_kind' => 'web', 'source_ref' => null, 'source_url' => $c2,
            'source_excerpt' => '64GB DDR4-3200MT/s ECC 2Rx4 New Model Hynix HMAA8GR7CJR4N-XN Capacity 64GB', 'considered_source_urls' => [], 'considered_source_refs' => []];
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->card([$row]))->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => $c2], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled(), (string) json_encode($outcome->repairContext['validation_errors'] ?? null));
        $this->assertEqualsCanonicalizing([$sbit, $hynix], $outcome->specifications[0]['considered_source_urls']);

        // Another amount is not listed for the agent.
        $other = $this->draft(['specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '32 GB (2 × 16 GB)', 'source_url' => $sbit,
            'source_excerpt' => 'DDR4-3200AA 8Gx72 ECC Reg/RDIMM 2Rx4 (64GB).']]]);
        $this->page($other, $c2, '64GB DDR4-3200MT/s ECC 2Rx4 New Model Hynix HMAA8GR7CJR4N-XN Capacity 64GB');
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->card([$row]))->preventStrayPrompts();

        $refused = app(ProductSpecificationReconciler::class)->reconcile($other, ['url' => $c2], $other->telegram_update_id);

        $this->assertFalse($refused->isReconciled());
        $this->assertStringContainsString('Missing source URLs: '.$sbit, json_encode($refused->repairContext['validation_errors'], JSON_UNESCAPED_SLASHES));
    }

    public function test_the_agents_are_told_that_condition_and_notation_are_not_blockers(): void
    {
        // Live #705: the assembly blocked on "New" against a requested used item and on
        // PC5-5600B-RD0-1010-XT against DDR5-5600 / PC5-44800, one speed in two notations.
        $assembly = $this->squashed((string) (new ProductSpecificationReconciliationAgent)->instructions());
        $this->assertStringContainsString('Never return not_ready, conflicting or unknown over condition', $assembly);
        $this->assertStringContainsString('PC5-44800', $assembly);
        $this->assertStringContainsString('A code the operator wrote that no observed page repeats is not such evidence and never a blocker', $assembly);
        $this->assertStringNotContainsString('configuration, color, keyboard layout or condition does', $assembly);
        $identity = $this->squashed((string) (new ProductSourceIdentityAgent)->instructions());
        $this->assertStringContainsString('Never return conflicting or uncertain over condition', $identity);
        $this->assertStringNotContainsString('a used listing when the operator requested new', $identity);
        $research = $this->squashed((string) (new ProductResearchAgent)->instructions());
        $this->assertStringContainsString('never search for used, refurbished or open-box offers because of it', $research);
    }

    private function squashed(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /** The draft of #706: research run 4053's facts for the request that was the earlier message 705. */
    private function draft706(): ProductDraft
    {
        $request = TelegramUpdate::query()->create(['update_id' => 970705, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => self::REQUEST_705, 'payload' => ['message' => ['text' => self::REQUEST_705]], 'status' => 'completed']);
        $followUp = TelegramUpdate::query()->create(['update_id' => 970706, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => 'Ищи ещё раз', 'payload' => ['message' => ['text' => 'Ищи ещё раз']], 'status' => 'processing']);
        ProductInputContext::rememberSearchRequest($followUp, [self::REQUEST_705]);
        $web = fn (string $key, string $name, string $value, string $url, string $excerpt): array => compact('key', 'name', 'value') + ['source_url' => $url, 'source_excerpt' => $excerpt];
        $draft = $this->draft([
            'telegram_update_id' => $followUp->id, 'title' => 'Samsung M321R2GA3EBO-CWMXJ 16GB DDR5-5600 RDIMM Memory Module',
            'brand' => 'Samsung', 'model' => 'M321R2GA3EBO-CWMXJ', 'condition' => 'used', 'category' => 'ram', 'product_type' => 'component',
            'specifications' => [
                ['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => 'M321R2GA3EBO-CWMXJ'],
                $web('brand', 'Brand', 'Samsung', self::ENTERASOURCE, 'Manufacturer: Samsung'),
                $web('ram', 'Memory', '16 GB', self::ENTERASOURCE, 'Capacity | 16GB'),
                $web('memory_type', 'Memory type', 'DDR5', self::ENTERASOURCE, 'Part Type | DDR5 SDRAM'),
                $web('data_rate', 'Data rate', '5600 MT/s', self::ENTERASOURCE, 'Speed | DDR5-5600'),
                $web('module_type', 'Module type', 'RDIMM', self::ENTERASOURCE, 'registered ECC memory module (RDIMM)'),
                $web('ecc_memory', 'ECC memory support', 'Yes, registered ECC', self::ENTERASOURCE, 'ECC | Yes; Registered | Yes'),
                $web('buffered', 'Buffered', 'Registered', self::ENTERASOURCE, 'Registered | Yes'),
                $web('memory_rank', 'Memory rank', 'Single rank, x8 (1Rx8)', self::ENTERASOURCE, 'single rank with x8 devices (1Rx8)'),
                $web('voltage', 'Memory voltage', '1.1 V', self::ENTERASOURCE, 'Voltage | 1.1V'),
                ['key' => 'condition', 'name' => 'Condition', 'value' => 'Used'],
                $web('cas_latency', 'CAS latency', 'CL46', self::MEMORYSOLUTION, 'Latenzzeit: CL46'),
                $web('pin_count', 'Pin count', '288', self::MEMORYSOLUTION, 'Formfaktor: 288pin LongDIMM'),
            ],
            'specification_evidence' => [
                ['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB', 'source_url' => self::MEMORYSOLUTION, 'source_excerpt' => 'Kapazität: | 16GB'],
                ['key' => 'data_rate', 'name' => 'Data rate', 'value' => '5600 MT/s', 'source_url' => self::MEMORYSOLUTION, 'source_excerpt' => 'Geschwindigkeit: | DDR5-5600 (PC5-5600)'],
                ['key' => 'memory_rank', 'name' => 'Memory rank', 'value' => 'Single rank', 'source_url' => self::MEMORYSOLUTION, 'source_excerpt' => 'Modulkonfiguration: | Single Rank'],
                ['key' => 'module_type', 'name' => 'Module type', 'value' => 'RDIMM', 'source_url' => self::MEMORYSOLUTION, 'source_excerpt' => 'Typ: | RDIMM'],
            ],
        ]);
        // What the assembly's text tools had read on ireptech.com (the quotations of run 4128).
        $this->page($draft, self::IREPTECH, "MANUFACTURE PART #: M321R2GA3EB0-CWMXJ\nSamsung 16GB 1Rx8 DDR5 RDIMM, 5600MT/s\nREFURBISHED");

        return $draft;
    }

    /** Run 4128's answer, without its translations. */
    private function answer4128(): array
    {
        $row = fn (string $key, string $name, string $value, string $evidence, ?string $url, ?string $excerpt, string $reason, array $considered = []): array => [
            'key' => $key, 'name' => $name, 'value' => $value, 'evidence' => $evidence, 'group' => null, 'reason' => $reason,
            'source_kind' => 'web', 'source_ref' => null, 'source_url' => $url, 'source_excerpt' => $excerpt,
            'considered_source_urls' => $considered, 'considered_source_refs' => []];

        return [
            'offer_selection' => ['action' => 'retain_selected_offer', 'source_url' => self::GALLERY,
                'reason' => 'The selected gallery page describes the 16 GB DDR5-5600 ECC registered single-rank module with the base code M321R2GA3EB0-CWM. A separate retailer identifies the corresponding full M321R2GA3EB0-CWMXJ code and the same relevant configuration, supporting use of this gallery for the physical module. The gallery listing is new, while the exact-code offer is labeled refurbished; condition is recorded separately from the physical product and the observed refurbished value is retained.'],
            'overall_status' => 'reconciled', 'recovery_action' => 'none', 'unresolved_fields' => [], 'photo_applicability_conflict' => null,
            'summary' => 'Карточка собрана для модуля Samsung с наблюдаемым кодом M321R2GA3EB0-CWMXJ. Вместо введённой буквы O в коде страницы показывают цифру 0; точный код и характеристики подтверждены отдельным предложением.',
            'title' => 'Samsung M321R2GA3EB0-CWMXJ 16 GB DDR5-5600 ECC Registered Memory Module', 'model' => 'M321R2GA3EB0-CWMXJ', 'color' => null,
            'description' => 'This Samsung 16 GB DDR5 registered ECC memory module is designed for compatible server systems. It operates at a data rate of 5600 MT/s and uses a single-rank 1Rx8 organization, with registered buffering and ECC support.',
            'specifications' => [
                $row('mpn', 'Manufacturer part number', 'M321R2GA3EB0-CWMXJ', 'corrected', self::IREPTECH, 'MANUFACTURE PART #: M321R2GA3EB0-CWMXJ', 'The code entered by the operator differs from the observed code by O versus 0.'),
                $row('brand', 'Brand', 'Samsung', 'confirmed', self::ENTERASOURCE, 'Manufacturer: Samsung', 'The exact-code listing identifies Samsung as the manufacturer.'),
                $row('ram', 'Memory', '16 GB', 'confirmed', self::ENTERASOURCE, 'Capacity | 16GB', 'The exact-code listing and the selected gallery page describe a 16 GB module.'),
                $row('memory_type', 'Memory type', 'DDR5', 'confirmed', self::ENTERASOURCE, 'Part Type | DDR5 SDRAM', 'The exact-code listing identifies this as DDR5 SDRAM.'),
                $row('data_rate', 'Data rate', '5600 MT/s', 'confirmed', self::IREPTECH, 'Samsung 16GB 1Rx8 DDR5 RDIMM, 5600MT/s', 'The exact-code listing specifies 5600 MT/s.'),
                $row('module_type', 'Module type', 'RDIMM', 'confirmed', self::IREPTECH, 'Samsung 16GB 1Rx8 DDR5 RDIMM, 5600MT/s', 'The exact-code listing names the module as a DDR5 RDIMM.'),
                $row('ecc_memory', 'ECC memory support', 'Yes, registered ECC', 'confirmed', self::ENTERASOURCE, 'ECC | Yes; Registered | Yes', 'The applicable exact-code retailer specifications state ECC and registered support.'),
                $row('buffered', 'Buffered', 'Registered', 'confirmed', self::ENTERASOURCE, 'Registered | Yes', 'The exact-code listing identifies registered buffering.'),
                $row('memory_rank', 'Memory rank', 'Single rank, x8 (1Rx8)', 'confirmed', self::ENTERASOURCE, 'single rank with x8 devices (1Rx8)', 'The exact-code listing directly identifies single rank with x8 devices.', [self::MEMORYSOLUTION]),
                $row('voltage', 'Memory voltage', '1.1 V', 'confirmed', self::ENTERASOURCE, 'Voltage | 1.1V', 'The exact-code retailer listing specifies 1.1 V.'),
                $row('condition', 'Condition', 'Refurbished', 'corrected', self::IREPTECH, 'REFURBISHED', 'The exact-code offer is explicitly labeled REFURBISHED.'),
                $row('cas_latency', 'CAS latency', 'CL46', 'confirmed', self::MEMORYSOLUTION, 'Latenzzeit: CL46', 'The applicable listing explicitly states CL46.'),
                $row('pin_count', 'Pin count', '288', 'confirmed', self::MEMORYSOLUTION, 'Formfaktor: 288pin LongDIMM', 'The applicable listing identifies a 288-pin DIMM.'),
                $row('memory_timings', 'Memory timings', '', 'dropped', null, null, 'No complete memory timing string is documented in the observed evidence.', [self::MEMORYSOLUTION, self::GALLERY]),
                $row('rgb_lighting', 'RGB lighting', '', 'dropped', null, null, 'The observed pages do not establish whether the module has RGB lighting.', [self::MEMORYSOLUTION, self::GALLERY]),
                $row('xmp_support', 'XMP support', '', 'dropped', null, null, 'No observed source documents XMP support for this server memory module.', [self::MEMORYSOLUTION, self::GALLERY]),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $specifications */
    private function card(array $specifications): array
    {
        return ['overall_status' => 'reconciled', 'recovery_action' => 'none', 'unresolved_fields' => [],
            'photo_applicability_conflict' => null, 'summary' => 'Подтверждено.', 'title' => 'Memory module', 'model' => 'Memory module',
            'color' => null, 'description' => 'A memory module.', 'specifications' => $specifications];
    }

    private function draft(array $overrides): ProductDraft
    {
        $updateId = $overrides['telegram_update_id'] ?? TelegramUpdate::query()->create(['update_id' => random_int(800000, 899999),
            'telegram_user_id' => '1', 'chat_id' => '1', 'text' => 'Memory module', 'payload' => [], 'status' => 'processing'])->id;
        $run = AiRun::query()->create(['telegram_update_id' => $updateId, 'provider' => 'fake', 'model' => 'fake', 'status' => 'completed',
            'prompt' => 'research', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::query()->create([
            'telegram_update_id' => $updateId, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'title' => 'Memory module', 'model' => 'Memory module', 'description' => 'A memory module.',
            'sources' => [], 'image_urls' => [], ...$overrides, 'telegram_update_id' => $updateId,
        ]);
    }

    private function page(ProductDraft $draft, string $url, string $text): void
    {
        ProductSourcePageEvidence::query()->create(['product_draft_id' => $draft->id,
            'url_hash' => hash('sha256', ProductImageStorage::normalizeCandidateUrl($url)), 'url' => $url,
            'specification_text' => $text, 'captured_via' => 'http']);
    }
}
