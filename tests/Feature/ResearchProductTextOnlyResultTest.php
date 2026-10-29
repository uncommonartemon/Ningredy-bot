<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\ResearchProduct;
use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\BrandCatalog;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\SourceIdentityProvenance;
use App\Services\ProductSearch\Supervision\SearchProgressSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResearchProductTextOnlyResultTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://shop.example/lenovo-exact-123';

    private function research(string $stopReason, bool $assembled, bool $moneyRemains = false, bool $moneyExhausted = false, int $photoCount = 0, string $verificationStatus = 'verified', bool $filesExist = true, string $sourceType = 'retailer', array $responseOverrides = [], ?callable $beforeStage = null, string $text = 'Find Lenovo Exact 123'): array
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Queue::fake();
        ProductResearchAgent::fake([[
            'status' => 'found', 'title' => 'Lenovo Exact 123', 'brand' => 'Lenovo', 'model' => 'Exact 123',
            'product_type' => 'laptop', 'category' => 'laptops', 'color' => 'Black', 'condition' => 'new',
            'description' => 'Initial research description.', 'research_notes' => null,
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB'],
                // Live #413 already had exact label identifiers; source kind was its only blocker.
                ...($sourceType === 'web' ? [['key' => 'mpn', 'name' => 'Part number', 'value' => 'EXACT-123']] : [])],
            'sources' => [['title' => 'Exact laptop', 'url' => self::SOURCE, 'type' => $sourceType, 'image_urls' => []]],
            'primary_source_url' => self::SOURCE, 'official_source_url' => null, 'image_urls' => [], 'confidence' => 0.95,
            ...($sourceType === 'web' ? [] : ['selected_identity' => $this->observedIdentity('Exact 123', self::SOURCE)]),
            ...$responseOverrides,
        ]])->preventStrayPrompts();
        $update = TelegramUpdate::query()->create(['update_id' => 1234567, 'telegram_user_id' => '123', 'chat_id' => '456',
            'message_id' => 10, 'text' => $text, 'payload' => [], 'status' => 'processing']);
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft) use ($stopReason, $assembled, $photoCount, $verificationStatus, $filesExist, $beforeStage): int {
            $beforeStage?->__invoke($draft);
            $draft->update(['images_staged_at' => now(), 'gallery_status' => $photoCount > 0 ? 'partial' : 'missing', 'gallery_search_stop_reason' => $stopReason,
                ...($assembled ? [
                    'title' => 'Lenovo Exact 123 verified', 'description' => 'Final assembled description.',
                    'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '32 GB']],
                    'specifications_reconciled_source_url' => $draft->primary_source_url,
                ] : []),
            ]);

            for ($index = 0; $index < $photoCount; $index++) {
                $path = "drafts/{$draft->id}/{$index}.webp";
                if ($filesExist) {
                    Storage::disk('public')->put($path, 'previously-verified-photo-'.$index);
                }
                $draft->media()->create([
                    'disk' => 'public', 'path' => $path, 'source_url' => self::SOURCE."/{$index}.webp",
                    'mime_type' => 'image/webp', 'width' => 1600, 'height' => 1200,
                    'checksum' => hash('sha256', $path), 'verification_status' => $verificationStatus,
                    'sort_order' => $index, 'is_primary' => $index === 0,
                ]);
            }

            return $photoCount;
        });
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('hasRemaining')->andReturn($moneyRemains);
        $costBudget->shouldReceive('exceeded')->andReturn($moneyExhausted);

        return json_decode((string) (new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => $update->text])), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function terminalBudgetStops(): array
    {
        return [['cost_budget'], ['time_budget']];
    }

    public function test_unclassified_web_html_candidate_reaches_the_gallery_stage_without_relabel_or_retry(): void
    {
        $result = $this->research('cost_budget', assembled: false, sourceType: 'web');
        $draft = ProductDraft::query()->sole();

        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertSame(self::SOURCE, $draft->primary_source_url);
        $this->assertSame('web', $draft->sources[0]['type']);
        $this->assertSame(1, AiRun::query()->count());
        $this->assertDatabaseMissing('product_source_attempts', ['phase' => 'research_recovery']);
    }

    #[DataProvider('terminalBudgetStops')]
    public function test_finished_text_card_survives_a_zero_photo_budget_stop(string $reason): void
    {
        $result = $this->research($reason, assembled: true);
        $draft = ProductDraft::query()->sole();
        $this->assertSame('found', $result['status']);
        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertTrue($result['text_only_result']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame(0, $result['image_count']);
        // Live #223: the assistant said the photo search went on after it had stopped.
        $this->assertSame('stopped', $result['photo_search']);
        $this->assertSame($reason, $result['photo_search_stop_reason']);
        $this->assertStringContainsString('Не пиши, что поиск фото продолжается', $result['message']);
        $this->assertSame('Lenovo Exact 123 verified', $result['title']);
        $this->assertSame('Final assembled description.', $result['description']);
        $this->assertSame('32 GB', $result['specifications'][0]['value']);
        $this->assertSame('pending_review', $draft->status);
        $this->assertNull($draft->reviewed_at);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_a_time_slice_with_money_remaining_keeps_the_existing_continuation_policy(): void
    {
        $result = $this->research('time_budget', assembled: true, moneyRemains: true);
        $this->assertSame('gallery_search_continuing', $result['status']);
        $this->assertArrayNotHasKey('text_only_result', $result);
        $this->assertSame('pending_review', ProductDraft::query()->sole()->status);
    }

    public function test_source_exhaustion_keeps_searching_instead_of_prematurely_delivering_the_text_card(): void
    {
        $result = $this->research('exhausted', assembled: true, moneyRemains: true);
        $this->assertSame('gallery_search_continuing', $result['status']);
        Queue::assertPushed(ContinueDraftGallerySearch::class);
    }

    public function test_the_watchers_end_of_the_photo_search_stops_the_automatic_continuation_chain(): void
    {
        // Live #178: the watcher ended the photo search and the card was assembled, then the
        // automatic continuation began a new cycle every fifteen seconds, forgetting the verdict.
        $result = $this->research('exhausted', assembled: true, moneyRemains: true,
            beforeStage: fn (ProductDraft $draft) => app(SearchProgressSupervisor::class)
                ->requestWrapUp($draft->telegram_update_id, 'Магазины дали только миниатюры.'));

        $this->assertSame('found', $result['status']);
        $this->assertTrue($result['text_only_result']);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_final_assembly_spending_the_remaining_money_delivers_text_instead_of_queueing_another_cycle(): void
    {
        $result = $this->research('exhausted', assembled: true, moneyExhausted: true);
        $this->assertSame('found', $result['status']);
        $this->assertTrue($result['text_only_result']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame('cost_budget', ProductDraft::query()->sole()->gallery_search_stop_reason);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    #[DataProvider('terminalBudgetStops')]
    public function test_unassembled_information_is_never_reported_as_a_finished_text_card(string $reason): void
    {
        $result = $this->research($reason, assembled: false);
        $this->assertSame('gallery_search_paused', $result['status']);
        $this->assertArrayNotHasKey('text_only_result', $result);
        $this->assertTrue(ProductDraft::query()->sole()->reconciliationPending());
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_zero_photo_card_with_incomplete_assembly_stays_pending_without_approval(): void
    {
        $result = $this->research('specifications_unreconciled', assembled: false);
        $draft = ProductDraft::query()->sole();
        $this->assertSame('card_assembly_paused', $result['status']);
        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame('specifications_unreconciled', $result['stop_reason']);
        $this->assertSame('pending_review', $draft->status);
        $this->assertNull($draft->reviewed_at);
        $this->assertSame('16 GB', $draft->specifications[0]['value']);
    }

    public function test_assistant_keeps_the_id_of_a_ready_text_card_without_offering_publication(): void
    {
        $instructions = (string) app(ServerAssistantAgent::class)->instructions();
        $this->assertStringContainsString('text_only_result=true', $instructions);
        $this->assertStringContainsString('ready_for_approval=false запрещает публикацию', $instructions);
    }

    public static function partialPhotoResults(): array
    {
        return [['cost_budget', 7], ['time_budget', 1], ['specifications_unreconciled', 3]];
    }

    #[DataProvider('partialPhotoResults')]
    public function test_unassembled_card_returns_saved_photos_as_partial_result_without_unverified_facts(string $reason, int $photoCount): void
    {
        $result = $this->research($reason, assembled: false, photoCount: $photoCount);
        $draft = ProductDraft::query()->sole();

        $this->assertSame('partial_result', $result['status']);
        $this->assertTrue($result['partial_photo_result']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertSame($photoCount, $result['image_count']);
        $this->assertSame($photoCount, $result['partial_photo_count']);
        $this->assertSame($reason, $result['stop_reason']);
        $this->assertArrayNotHasKey('description', $result);
        $this->assertArrayNotHasKey('specifications', $result);
        $this->assertTrue($draft->reconciliationPending());
        $this->assertSame('16 GB', $draft->specifications[0]['value']);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        ProductResearchAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Lenovo Exact 123'));
    }

    public function test_pending_photos_do_not_promise_a_verified_preview(): void
    {
        $result = $this->research('cost_budget', assembled: false, photoCount: 3, verificationStatus: 'pending');

        $this->assertSame('card_assembly_paused', $result['status']);
        $this->assertFalse($result['partial_photo_result']);
        $this->assertSame(0, $result['partial_photo_count']);
        $this->assertSame(3, $result['image_count']);
    }

    public function test_missing_photo_files_do_not_promise_a_preview(): void
    {
        $result = $this->research('cost_budget', assembled: false, photoCount: 3, filesExist: false);

        $this->assertSame('card_assembly_paused', $result['status']);
        $this->assertFalse($result['partial_photo_result']);
        $this->assertSame(0, $result['partial_photo_count']);
    }

    public function test_unresolved_identity_reaches_the_assistant_as_its_real_blocker_not_as_not_found(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Queue::fake();
        $notes = 'Two configurations share this title; no observed page names the installed CPU.';
        $unresolved = ['status' => 'unresolved', 'title' => 'Lenovo Exact', 'brand' => 'Lenovo', 'model' => 'Exact',
            'product_type' => 'laptop', 'category' => 'laptops', 'color' => null, 'condition' => 'new',
            'description' => null, 'research_notes' => $notes, 'specifications' => [],
            'sources' => [['title' => 'Exact laptop', 'url' => self::SOURCE, 'type' => 'retailer', 'image_urls' => []]],
            'primary_source_url' => null, 'official_source_url' => null, 'image_urls' => [], 'confidence' => 0.4];
        ProductResearchAgent::fake([$unresolved, $unresolved])->preventStrayPrompts();
        $update = TelegramUpdate::query()->create(['update_id' => 1234568, 'telegram_user_id' => '123', 'chat_id' => '456',
            'message_id' => 11, 'text' => 'Find Lenovo Exact', 'payload' => [], 'status' => 'processing']);
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldNotReceive('stage');
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('hasRemaining')->andReturn(true);
        $costBudget->shouldReceive('exceeded')->andReturn(false);

        $result = json_decode((string) (new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => $update->text])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('research_interrupted', $result['status']);
        $this->assertContains('selected_identity', $result['blocked_by']);
        $this->assertSame($notes, $result['agent_comment']);
        $this->assertStringContainsString('не означает, что товар не найден', $result['message']);
        $this->assertSame(0, ProductDraft::query()->count());
        // The repair guidance names every way out of "unresolved", the configurator one
        // included (live #317/#323: Framework, three honest "unresolved" answers, no draft).
        $guidance = json_encode(ProductSourceAttempt::query()->where('action', 'repair_response')->latest('id')->first()?->output ?? []);
        $this->assertStringContainsString('configured_product', $guidance);
        $this->assertStringContainsString('sibling configuration of the same model line', $guidance);
    }

    public function test_quotations_from_a_compatible_photo_only_page_never_become_selected_product_evidence(): void
    {
        $photoPage = 'https://other-shop.example/lenovo-exact-125';
        $quote = fn (string $url, string $value): array => ['key' => 'ram', 'name' => 'Memory', 'value' => $value,
            'source_url' => $url, 'source_excerpt' => "Memory: {$value}", 'applicability' => 'Observed on this page.'];
        $this->research('cost_budget', assembled: false, responseOverrides: [
            'sources' => [
                ['title' => 'Exact laptop', 'url' => self::SOURCE, 'type' => 'retailer', 'image_urls' => []],
                ['title' => 'Sibling', 'url' => $photoPage, 'type' => 'retailer', 'image_urls' => [],
                    'roles' => ['compatible_photo_candidate'], 'applicability_reason' => 'Same chassis, different memory.'],
            ],
            'specification_evidence' => [$quote(self::SOURCE, '16 GB'), $quote($photoPage, '32 GB')],
        ]);

        $evidence = ProductDraft::query()->sole()->specification_evidence;
        $this->assertSame([self::SOURCE], array_column($evidence, 'source_url'));
        $this->assertSame('16 GB', $evidence[0]['value']);
    }

    public function test_an_operator_code_nobody_shows_is_warned_about_at_once_and_the_search_continues(): void
    {
        // The operator's code stays in the card as their label fact; the tool
        // refuses an answer that drops it or names the product by another page's code.
        $result = $this->research('cost_budget', assembled: true, responseOverrides: [
            'unconfirmed_operator_codes' => ['AWAC16251-9765BLU-PDE'],
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB'],
                ['key' => 'mpn', 'name' => 'Part number', 'value' => 'AWAC16251-9765BLU-PDE']],
        ], text: 'Find Lenovo Exact 123 AWAC16251-9765BLU-PDE');

        $this->assertSame('found', $result['status']);
        $this->assertSame(1, ProductDraft::query()->count());
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Не найдено ни на одной странице: AWAC16251-9765BLU-PDE')
            && str_contains((string) ($request['text'] ?? ''), 'Продолжаю поиск по ближайшему товару'));
    }

    public function test_a_requested_colour_no_page_shows_is_kept_as_a_difference_for_every_later_step(): void
    {
        // Live #215: every page of the model and the maker's sheet named another colour;
        // three "unresolved" answers left no draft. A found answer names the difference.
        $this->research('cost_budget', assembled: false, responseOverrides: [
            'color' => 'Storm Grey',
            'request_differences' => [['requirement' => 'color', 'requested' => 'Luna Grey', 'found' => 'Storm Grey',
                'evidence' => 'The maker lists Storm Grey for this model; Luna Grey belongs to another model.']],
        ], text: 'Find Lenovo Exact 123 Luna Grey');

        $draft = ProductDraft::query()->sole();
        $this->assertSame('Storm Grey', $draft->color);
        $this->assertSame('Luna Grey', ProductRequestContext::forDraft($draft)->forAgent()['request_differences']['items'][0]['requested']);
        $summary = ProductSourceAttempt::query()->where('action', 'research_summary')->sole();
        $this->assertSame('Storm Grey', $summary->output['request_differences'][0]['found']);
    }

    public function test_the_same_query_asked_twice_in_one_request_returns_the_first_draft_without_new_research(): void
    {
        // Live #247: two identical ResearchProduct calls in one turn; the second bought the research again.
        $this->research('cost_budget', assembled: true);
        $draft = ProductDraft::query()->sole();
        $update = TelegramUpdate::query()->sole();

        $second = json_decode((string) (new ResearchProduct($update, app(ProductImageResolver::class)))
            ->handle(new Request(['query' => $update->text])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('already_researched', $second['status']);
        $this->assertSame($draft->id, $second['draft_id']);
        $this->assertSame(1, ProductDraft::query()->count());
        $this->assertSame(1, ProductSourceAttempt::query()->where('action', 'research_summary')->count());
    }

    public function test_the_brands_own_catalog_is_offered_to_research_and_its_page_joins_the_sources(): void
    {
        // Operator 2026-10-27: the agent sees the brand and the code, looks there; Icecat stays first.
        BrandCatalog::query()->create(['brand' => 'Lenovo', 'catalog_url' => 'https://catalog.example', 'enabled' => true,
            'search_hint' => 'site:catalog.example {code}', 'lookup_template' => 'https://catalog.example/p/{code}',
            'instructions' => 'Manufacturer reference by model code.']);

        $this->research('cost_budget', assembled: false, responseOverrides: [
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB'], ['key' => 'mpn', 'name' => 'Part number', 'value' => 'EXACT-123']],
        ], text: 'Find Lenovo Exact 123 EXACT-123');

        $prompt = (string) AiRun::query()->orderBy('id')->value('prompt');
        $this->assertStringContainsString("The brand's own catalog", $prompt);
        $this->assertStringContainsString('site:catalog.example EXACT-123', $prompt);
        $catalogSource = collect(ProductDraft::query()->sole()->sources)->firstWhere('_brand_catalog', true);
        $this->assertSame('https://catalog.example/p/EXACT-123', $catalogSource['url']);
        $this->assertSame('manufacturer', $catalogSource['type']);
        $this->assertDatabaseHas('product_source_attempts', ['action' => 'offer_brand_catalog', 'decision' => 'queued_after_icecat']);
    }

    public function test_a_matching_official_datasheet_joins_the_photo_queue_and_a_mismatch_is_skipped_with_a_record(): void
    {
        config(['services.icecat.username' => 'tester']);
        $part = '';
        Http::fake(['https://live.icecat.biz/*' => function () use (&$part) {
            return Http::response(['data' => [
                'GeneralInfo' => ['Title' => 'Lenovo '.$part, 'Brand' => 'Lenovo', 'BrandPartCode' => $part, 'IcecatId' => '42',
                    'ProductName' => 'Exact', 'GTIN' => []],
                'Gallery' => [['Pic' => 'https://images.icecat.biz/img/gallery/one.jpg', 'PicWidth' => '1600', 'PicHeight' => '1200', 'No' => '1']],
                'FeaturesGroups' => [['FeatureGroup' => ['Name' => ['Value' => 'Memory']], 'Features' => [
                    ['PresentationValue' => '16 GB', 'Feature' => ['Name' => ['Value' => 'Internal memory']]]]]],
            ]]);
        }]);
        foreach (['EXACT-123' => true, 'OTHER-999' => false] as $part => $matches) {
            Cache::flush();
            ProductDraft::query()->delete();
            TelegramUpdate::query()->delete();

            $this->research('cost_budget', assembled: false);
            $draft = ProductDraft::query()->sole();
            $official = collect($draft->sources)->firstWhere('_official_gallery', true);

            if ($matches) {
                $this->assertSame(['https://images.icecat.biz/img/gallery/one.jpg'], $official['image_urls']);
                // The code match is the source's identity; every later page look keeps it.
                $this->assertSame('code_match', $official[SourceIdentityProvenance::KEY]['kind']);
                $this->assertContains('EXACT-123', $official[SourceIdentityProvenance::KEY]['codes']);
                $this->assertTrue(ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->where('captured_via', 'icecat')->exists());
            } else {
                $this->assertNull($official);
                $this->assertTrue(ProductSourceAttempt::query()->where('action', 'offer_official_gallery')->where('decision', 'code_mismatch')->exists());
            }
        }
    }

    public function test_a_datasheet_found_by_the_selected_code_matches_even_under_another_part_number(): void
    {
        // Live #456: Icecat files ASUS GA403UV-G14.R94060 under part 90NR0I02-M003W0.
        config(['services.icecat.username' => 'tester']);
        Cache::flush();
        Http::fake(['https://live.icecat.biz/*' => Http::response(['data' => [
            'GeneralInfo' => ['Title' => 'Lenovo Exact 123', 'Brand' => 'Lenovo', 'BrandPartCode' => '90NR0I02-M003W0', 'IcecatId' => '42',
                'ProductName' => 'Exact', 'GTIN' => []],
            'Gallery' => [['Pic' => 'https://images.icecat.biz/img/gallery/exact.jpg', 'PicWidth' => '2000', 'PicHeight' => '2000', 'No' => '1']],
        ]])]);

        $this->research('cost_budget', assembled: false);

        $official = collect(ProductDraft::query()->sole()->sources)->firstWhere('_official_gallery', true);
        $this->assertSame(['https://images.icecat.biz/img/gallery/exact.jpg'], $official['image_urls']);
    }

    public function test_a_barcode_that_points_at_another_product_still_lets_the_selected_part_code_find_its_datasheet(): void
    {
        config(['services.icecat.username' => 'tester']);
        Cache::flush();
        Http::fake(['https://live.icecat.biz/*' => function ($request) {
            $part = isset($request['GTIN']) ? 'OTHER-999' : 'EXACT-123';

            return Http::response(['data' => [
                'GeneralInfo' => ['Title' => 'Lenovo '.$part, 'Brand' => 'Lenovo', 'BrandPartCode' => $part, 'IcecatId' => '42',
                    'ProductName' => 'Exact', 'GTIN' => isset($request['GTIN']) ? ['0887276868158'] : []],
                'Gallery' => [['Pic' => 'https://images.icecat.biz/img/gallery/'.$part.'.jpg', 'PicWidth' => '1600', 'PicHeight' => '1200', 'No' => '1']],
            ]]);
        }]);

        $this->research('cost_budget', assembled: false, responseOverrides: ['specifications' => [
            ['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB'], ['key' => 'ean', 'name' => 'EAN', 'value' => '0887276868158']],
            // Live #439: the agent listed the datasheet page itself as a facts-only database entry.
            'sources' => [['title' => 'Exact laptop', 'url' => self::SOURCE, 'type' => 'retailer', 'image_urls' => []],
                ['title' => 'Icecat', 'url' => 'https://icecat.biz/p/lenovo/exact-123/exact-42.html', 'type' => 'database', 'roles' => ['facts'], 'image_urls' => []]]]);

        // The barcode's record belongs to OTHER-999; the queued gallery is the selected part code's,
        // and it replaces the agent's own entry for the same page instead of being shadowed by it.
        $sources = collect(ProductDraft::query()->sole()->sources);
        $official = $sources->firstWhere('_official_gallery', true);
        $this->assertSame(['https://images.icecat.biz/img/gallery/EXACT-123.jpg'], $official['image_urls']);
        $this->assertCount(1, $sources->filter(fn (array $source): bool => str_contains($source['url'], 'icecat.biz')));
    }
}
