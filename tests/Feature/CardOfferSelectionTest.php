<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Ai\Tools\SearchProductTextEvidence;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductSourceIdentityJudge;
use App\Services\ProductSearch\Recovery\CardOfferSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class CardOfferSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://shop.example/hp-b82jjua';

    private function draft(string $request = 'HP EliteBook 845 G11 16 GB RAM 512 GB SSD new'): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 984, 'payload' => [], 'text' => $request]);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'research', 'started_at' => now()]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'HP EliteBook 845 G11', 'model' => 'EliteBook 845 G11', 'brand' => 'HP', 'condition' => 'new',
            'description' => 'Research selected the 300 nit configuration.',
            'primary_source_url' => self::SOURCE,
            'image_urls' => ['https://shop.example/front.jpg', 'https://shop.example/back.jpg'],
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '9G0G5ET#ABF'],
                ['key' => 'display', 'name' => 'Display', 'value' => '300 nits'],
            ], 'sources' => [],
        ]);
        ProductSourcePageEvidence::create([
            'product_draft_id' => $draft->id, 'url_hash' => hash('sha256', self::SOURCE), 'url' => self::SOURCE,
            'specification_text' => 'HP EliteBook 845 G11. SKU: B82JJUA#ABA. RAM: 16 GB. SSD: 512 GB. Display: 400 nits.',
            'captured_via' => 'browser',
        ]);

        return $draft;
    }

    private function candidate(): array
    {
        return [
            'overall_status' => 'reconciled', 'summary' => 'Выбрана подходящая комплектация источника фото.',
            'title' => 'HP EliteBook 845 G11 B82JJUA#ABA', 'model' => 'EliteBook 845 G11', 'color' => null,
            'description' => 'HP EliteBook 845 G11 with a 400 nit display.',
            'offer_selection' => ['action' => 'adopt_source_offer', 'source_url' => self::SOURCE,
                'reason' => 'The operator specified the family and 16/512, not the research SKU.'],
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => 'B82JJUA#ABA', 'evidence' => 'corrected',
                    'reason' => 'Selected the photographed offer.', 'source_url' => self::SOURCE, 'source_excerpt' => 'SKU: B82JJUA#ABA'],
                ['key' => 'display', 'name' => 'Display', 'value' => '400 nits', 'evidence' => 'corrected',
                    'reason' => 'This offer has the 400 nit panel.', 'source_url' => self::SOURCE, 'source_excerpt' => 'Display: 400 nits'],
            ],
        ];
    }

    public function test_gallery_offer_can_be_assembled_without_overwriting_the_draft_before_validation(): void
    {
        $draft = $this->draft();
        $original = $draft->only(['title', 'specifications', 'description', 'image_urls']);
        $this->mock(ProductSourceIdentityJudge::class)->shouldReceive('judgeForAssembly')->once()->andReturn('confirmed');
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use (&$calls, $draft): array {
            $calls++;
            $payload = json_decode($prompt, true);
            $this->assertSame($draft->telegramUpdate->text, $payload['offer_context']['original_operator_request']);
            $this->assertSame(self::SOURCE, $payload['offer_context']['gallery_source']['url']);
            $this->assertSame('9G0G5ET#ABF', $payload['current_specifications'][0]['value']);

            return $this->candidate();
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertTrue($outcome->isReconciled(), $outcome->summary ?? '');
        $this->assertSame('B82JJUA#ABA', $outcome->specifications[0]['value']);
        $this->assertSame('400 nits', $outcome->specifications[1]['value']);
        $this->assertSame(1, $calls);
        $this->assertSame('adopt_source_offer', $outcome->offerSelection['action']);
        $this->assertSame($original, $draft->fresh()->only(array_keys($original)));
    }

    public function test_a_broad_model_phrase_is_not_an_immutable_operator_code(): void
    {
        $draft = $this->draft('Acme Orbit Pro 14 16 GB RAM 512 GB SSD new');
        $draft->update(['brand' => 'Acme', 'model' => 'Orbit Pro 14']);
        $candidate = $this->candidate();
        $candidate['title'] = 'Acme Orbit Pro 14-inch B82JJUA#ABA';
        $candidate['model'] = 'Orbit Pro B82JJUA#ABA';
        $this->mock(ProductSourceIdentityJudge::class)->shouldReceive('judgeForAssembly')->once()->andReturn('confirmed');

        $selection = app(CardOfferSelection::class)->validate(
            $draft->fresh(), ['url' => self::SOURCE], $candidate,
            [['url' => self::SOURCE, 'specification_text' => 'Acme Orbit Pro, 14-inch, B82JJUA#ABA. RAM 16 GB, SSD 512 GB, new.']],
            $draft->telegram_update_id,
        );

        $this->assertSame('adopt_source_offer', $selection['action']);
        $this->assertSame('9G0G5ET#ABF', $draft->fresh()->specifications[0]['value']);
    }

    public function test_explicit_operator_sku_cannot_be_replaced_by_another_offer(): void
    {
        $draft = $this->draft('HP EliteBook 845 G11 9G0G5ET#ABF new');
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judgeForAssembly');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->candidate())->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertStringContainsString('operator', $outcome->summary ?? '');
        $this->assertSame('9G0G5ET#ABF', $draft->fresh()->specifications[0]['value']);
        $this->assertCount(2, $draft->fresh()->image_urls);
    }

    public function test_adoption_cannot_redirect_the_card_to_a_different_gallery(): void
    {
        $draft = $this->draft();
        $candidate = $this->candidate();
        $candidate['offer_selection']['source_url'] = 'https://other.example/product';
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judgeForAssembly');
        ProductSpecificationReconciliationAgent::fake(fn () => $candidate)->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertCount(2, $draft->fresh()->image_urls);
    }

    public function test_a_semantic_requirement_conflict_cannot_be_overridden_by_adoption(): void
    {
        $draft = $this->draft('HP EliteBook 845 G11 16/512 German QWERTZ new');
        $this->mock(ProductSourceIdentityJudge::class)->shouldReceive('judgeForAssembly')->andReturn('conflicting');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->candidate())->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertSame('9G0G5ET#ABF', $draft->fresh()->specifications[0]['value']);
    }

    public function test_offer_judge_checks_the_proposed_card_for_stale_facts_from_the_previous_offer(): void
    {
        $draft = $this->draft();
        $previousSource = 'https://research.example/hp-previous-offer';
        $draft->update(['specifications' => [
            ...$draft->specifications,
            ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB',
                'source_url' => $previousSource, 'source_excerpt' => 'Installed RAM: 32 GB'],
        ]]);
        ProductSourcePageEvidence::create([
            'product_draft_id' => $draft->id, 'url_hash' => hash('sha256', $previousSource), 'url' => $previousSource,
            'specification_text' => 'HP EliteBook 845 G11. SKU: 9G0G5ET#ABF. Installed RAM: 32 GB.',
            'captured_via' => 'browser',
        ]);
        $candidate = $this->candidate();
        $candidate['specifications'][] = [
            'key' => 'ram', 'name' => 'RAM', 'value' => '32 GB', 'evidence' => 'confirmed',
            'source_url' => $previousSource, 'source_excerpt' => 'Installed RAM: 32 GB',
        ];
        ProductSpecificationReconciliationAgent::fake(fn () => $candidate)->preventStrayPrompts();
        $judgedPayload = null;
        ProductSourceIdentityAgent::fake(function (string $prompt) use (&$judgedPayload): array {
            $judgedPayload = json_decode($prompt, true);
            $proposedRam = collect($judgedPayload['proposed_card']['specifications'] ?? [])->firstWhere('key', 'ram');

            return ['match' => ($proposedRam['value'] ?? null) === '32 GB' ? 'conflicting' : 'confirmed',
                'confidence' => 0.99,
                'reason' => 'The source satisfies the 16 GB request, but a proposed 32 GB fact belongs to the previous offer.'];
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);

        $this->assertFalse($outcome->isReconciled(), 'A matching source must not authorize incompatible facts in the proposed card.');
        $this->assertIsArray($judgedPayload);
        $this->assertEquals($candidate['specifications'], $judgedPayload['proposed_card']['specifications']);
        $this->assertStringContainsString('16 GB RAM', $judgedPayload['original_operator_request']);
        $this->assertStringContainsString('RAM: 16 GB', $judgedPayload['evidence_text']);
        $this->assertSame('9G0G5ET#ABF', $draft->fresh()->specifications[0]['value']);
    }

    public function test_renaming_the_action_to_retain_cannot_silently_replace_a_sku(): void
    {
        $draft = $this->draft();
        $candidate = $this->candidate();
        $candidate['offer_selection']['action'] = 'retain_selected_offer';
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judgeForAssembly');
        ProductSpecificationReconciliationAgent::fake(fn () => $candidate)->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertStringContainsString('commercial identifier changed', $outcome->summary ?? '');
    }

    public function test_old_explicit_sku_in_a_family_page_or_model_name_does_not_authorize_its_replacement(): void
    {
        $draft = $this->draft('HP EliteBook 845 G11 9G0G5ET#ABF new');
        $page = ProductSourcePageEvidence::where('product_draft_id', $draft->id)->firstOrFail();
        $page->update(['specification_text' => $page->specification_text.' Other available variant: 9G0G5ET#ABF.']);
        $candidate = $this->candidate();
        $candidate['model'] = 'EliteBook 845 G11 (also 9G0G5ET#ABF)';
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judgeForAssembly');
        ProductSpecificationReconciliationAgent::fake(fn () => $candidate)->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertStringContainsString('explicit operator commercial identifier', $outcome->summary ?? '');
    }

    public function test_unavailable_judge_does_not_turn_offer_reselection_into_a_success(): void
    {
        $draft = $this->draft();
        $this->mock(ProductSourceIdentityJudge::class)->shouldReceive('judgeForAssembly')->andReturn('unavailable');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->candidate())->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertStringContainsString('unavailable', $outcome->summary ?? '');
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_a_temporary_requirement_check_failure_does_not_buy_the_assembled_card_again(): void
    {
        $draft = $this->draft();
        $calls = 0;
        $this->mock(ProductSourceIdentityJudge::class)->shouldReceive('judgeForAssembly')->twice()->andReturn('unavailable', 'confirmed');
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return $this->candidate();
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertTrue($outcome->isReconciled(), $outcome->summary ?? '');
        $this->assertSame(1, $calls);
        $this->assertCount(2, $draft->fresh()->image_urls);
    }

    public function test_missing_original_request_never_authorizes_offer_reselection(): void
    {
        $draft = $this->draft('');
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judgeForAssembly');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->candidate())->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::SOURCE], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertStringContainsString('original operator request is unavailable', $outcome->summary ?? '');
    }

    public function test_text_search_can_target_the_gallery_offer_without_inheriting_the_old_sku(): void
    {
        $draft = $this->draft();
        ProductTextEvidenceAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertSame(self::SOURCE, $payload['selected_product']['source_url']);
            $this->assertStringContainsString('B82JJUA#ABA', $payload['selected_product']['observed_specification_text']);
            $this->assertStringNotContainsString('9G0G5ET', json_encode($payload['selected_product']));
            $this->assertSame('HP EliteBook 845 G11 16 GB RAM 512 GB SSD new', $payload['original_operator_request']);

            return ['facts' => [], 'summary' => 'No additional facts needed'];
        })->preventStrayPrompts();
        $tool = new SearchProductTextEvidence($draft->id, $draft->telegram_update_id, ['url' => self::SOURCE]);
        $result = json_decode($tool->handle(new Request(['question' => 'Confirm panel', 'target_offer' => 'source_offer'])), true);
        $this->assertTrue($result['found'], $result['reason'] ?? '');
        $this->assertSame('9G0G5ET#ABF', $draft->fresh()->specifications[0]['value']);
        $this->assertCount(2, $draft->fresh()->image_urls);
    }

    public function test_text_tool_cannot_invent_a_gallery_source_when_none_was_bound(): void
    {
        $draft = $this->draft();
        ProductTextEvidenceAgent::fake()->preventStrayPrompts();
        $tool = new SearchProductTextEvidence($draft->id, $draft->telegram_update_id);
        $result = json_decode($tool->handle(new Request(['question' => 'Panel', 'target_offer' => 'source_offer'])), true);
        $this->assertFalse($result['found']);
        $this->assertDatabaseCount('ai_runs', 1);
    }

    public function test_resume_commits_the_whole_card_and_preserves_the_existing_gallery_without_research(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $draft = $this->draft();
        $draft->update(['product_type' => 'laptop', 'gallery_status' => 'partial',
            'gallery_confirmed_sufficient' => true, 'gallery_search_stop_reason' => 'specifications_unreconciled']);
        foreach (range(1, 7) as $position) {
            $path = "drafts/{$draft->id}/photo-{$position}.webp";
            Storage::disk('public')->put($path, "already-verified-photo-{$position}");
            $draft->media()->create([
                'disk' => 'public', 'path' => $path, 'source_url' => "https://shop.example/photo-{$position}.jpg",
                'role' => 'secondary', 'mime_type' => 'image/webp',
                'checksum' => hash('sha256', "already-verified-photo-{$position}"),
                'verification_status' => 'source_verified', 'sort_order' => $position - 1,
                'is_primary' => $position === 1, 'gallery_traversal_complete' => true,
            ]);
        }
        $mediaBefore = $draft->media()->get()->toArray();
        $callback = TelegramUpdate::create(['update_id' => 985, 'payload' => [], 'text' => 'draft:continue-search:'.$draft->id]);
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;
            $this->assertStringNotContainsString('draft:continue-search', json_decode($prompt, true)['original_operator_request']);

            return $this->candidate();
        })->preventStrayPrompts();
        ProductSourceIdentityAgent::fake(fn () => ['match' => 'confirmed', 'confidence' => 0.99,
            'reason' => 'Matches the original family, RAM and SSD requirements.'])->preventStrayPrompts();

        $stored = app(ProductImageStorage::class)->stage($draft, telegramUpdateId: $callback->id);
        $this->assertSame(7, $stored);
        $final = $draft->fresh();
        $this->assertSame('complete', $final->gallery_status);
        $this->assertSame(self::SOURCE, $final->specifications_reconciled_source_url);
        $this->assertSame('B82JJUA#ABA', $final->specifications[0]['value']);
        $this->assertSame('400 nits', $final->specifications[1]['value']);
        $this->assertStringContainsString('400 nit', $final->description);
        $this->assertSame($mediaBefore, $final->media()->get()->toArray());
        $this->assertSame(1, $calls);
        $this->assertNull($final->gallery_search_stop_reason);
        $this->assertSame(2, AiRun::where('telegram_update_id', $callback->id)->count());
        Http::assertNothingSent();
    }
}
