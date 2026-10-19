<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductCardRecoveryAgent;
use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use App\Services\ProductSearch\Recovery\CardRepairPatch;
use App\Services\ProductSearch\Recovery\ProductCardRecoverySupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductCardRecoverySupervisorTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://93.184.216.34/products/alienware-16x';

    private const DELL = 'https://www.dell.com/en-us/shop/alienware-16x/spd/useac16251';

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::put('ai.max_search_cost_usd', '1');
    }

    public function test_425_like_dead_end_researches_the_missing_citation_and_asks_for_a_new_assembly(): void
    {
        $this->measurableBudget(spent: 0.15);
        $draft = $this->draftWithFailure();
        $prompts = [];
        ProductCardRecoveryAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return $this->decision('research_text', 'Explain whether the Dell CPU/GPU listing applies to AC16251.',
                'Dell differs on CPU/GPU; the repair agent refused to cite it.');
        })->preventStrayPrompts();
        ProductTextEvidenceAgent::fake(fn (): array => ['facts' => [[
            'key' => 'cpu', 'name' => 'CPU', 'value' => 'Intel Core Ultra 9 275HX',
            'source_url' => self::DELL, 'source_excerpt' => 'Intel Core Ultra 9 275HX (24 cores)',
            'applicability' => 'Alienware 16X Aurora AC16251 configuration page',
        ]], 'summary' => 'Dell confirms the CPU of this configuration.'])->preventStrayPrompts();

        $this->assertTrue(app(ProductCardRecoverySupervisor::class)->recover($draft, $draft->telegram_update_id));

        $state = $draft->fresh()->card_repair_state;
        $this->assertSame(1, $state['supervisor']['generation']);
        $this->assertSame('research_text', $state['supervisor']['history'][0]['action']);
        $this->assertTrue($state['supervisor']['history'][0]['changed']);
        $this->assertNotSame($state['supervisor']['history'][0]['evidence_before'], $state['supervisor']['history'][0]['evidence_after']);
        $this->assertCount(1, $draft->fresh()->specification_evidence);
        // The agent saw the exact blocker, not a paraphrase of it.
        $this->assertSame([self::DELL], $prompts[0]['blockers']['citation_obligations']['cpu']['source_urls']);
        $this->assertTrue($prompts[0]['saved_photos']['kept']);
        $this->assertDatabaseHas('product_source_attempts', ['product_draft_id' => $draft->id,
            'phase' => 'card_recovery', 'action' => 'research_text', 'decision' => 'retry_card_assembly']);
        $this->assertDatabaseHas('ai_runs', ['telegram_update_id' => $draft->telegram_update_id, 'status' => 'completed']);
    }

    public function test_the_same_step_on_unchanged_evidence_is_not_bought_again(): void
    {
        $this->measurableBudget(spent: 0.2);
        $draft = $this->draftWithFailure();
        ProductCardRecoveryAgent::fake(fn (): array => $this->decision('research_text', 'Dell CPU applicability', 'Retry'))
            ->preventStrayPrompts();
        ProductTextEvidenceAgent::fake(fn (): array => ['facts' => [], 'summary' => 'Nothing new.'])->preventStrayPrompts();

        $supervisor = app(ProductCardRecoverySupervisor::class);
        $this->assertFalse($supervisor->recover($draft, $draft->telegram_update_id));
        $this->assertFalse($supervisor->recover($draft->fresh(), $draft->telegram_update_id));

        $history = $draft->fresh()->card_repair_state['supervisor']['history'];
        $this->assertCount(1, $history, 'The repeated identical step must not be executed or recorded as new work.');
        $this->assertSame('no_new_evidence', $history[0]['outcome']);
        $this->assertDatabaseHas('product_source_attempts', ['product_draft_id' => $draft->id,
            'phase' => 'card_recovery', 'decision' => 'repeat_without_new_evidence']);
    }

    public function test_lookup_code_saves_an_official_datasheet_as_new_evidence_and_asks_for_a_new_assembly(): void
    {
        $this->measurableBudget(spent: 0.3);
        config(['services.icecat.username' => 'tester']);
        Http::fake(['https://live.icecat.biz/*' => Http::response(['data' => [
            'GeneralInfo' => ['Title' => 'Alienware 16X Aurora AC16251', 'Brand' => 'Dell', 'BrandPartCode' => 'AC16251-X',
                'IcecatId' => '777', 'ProductName' => '16X Aurora', 'GTIN' => ['0730143314930']],
            'Gallery' => [], 'FeaturesGroups' => [['FeatureGroup' => ['Name' => ['Value' => 'Processor']],
                'Features' => [['PresentationValue' => 'Intel Core Ultra 9 290HX', 'Feature' => ['Name' => ['Value' => 'Processor model']]]]]],
        ]])]);
        $draft = $this->draftWithFailure();
        ProductCardRecoveryAgent::fake(fn (): array => $this->decision('lookup_code', '0730143314930', 'Check which product the label code is.'))
            ->preventStrayPrompts();

        $this->assertTrue(app(ProductCardRecoverySupervisor::class)->recover($draft, $draft->telegram_update_id));

        $this->assertSame('code_found', $draft->fresh()->card_repair_state['supervisor']['history'][0]['outcome']);
        $this->assertTrue(ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->where('captured_via', 'icecat')->exists());
        $this->assertTrue(ProductSourceAttempt::query()->where('phase', 'code_lookup')->where('action', 'lookup_icecat')->where('decision', 'found')->exists());
    }

    public function test_stronger_model_is_a_one_time_last_resort_for_the_next_assembly(): void
    {
        $this->measurableBudget(spent: 0.3);
        app(AiSettings::class)->saveCardAssemblyFallbackModel('gpt-6-sol');
        $draft = $this->draftWithFailure();
        ProductCardRecoveryAgent::fake(fn (): array => $this->decision('stronger_model', '', 'Evidence is complete; the model keeps mis-quoting it.'))
            ->preventStrayPrompts();

        $this->assertTrue(app(ProductCardRecoverySupervisor::class)->recover($draft, $draft->telegram_update_id));
        $this->assertTrue($draft->fresh()->card_repair_state['supervisor']['stronger_model']);
        // The same last resort on unchanged evidence is not bought twice.
        $this->assertFalse(app(ProductCardRecoverySupervisor::class)->recover($draft->fresh(), $draft->telegram_update_id));
    }

    public function test_blocked_keeps_the_agents_own_explanation_and_does_not_retry(): void
    {
        $this->measurableBudget(spent: 0.3);
        $draft = $this->draftWithFailure();
        ProductCardRecoveryAgent::fake(fn (): array => [
            ...$this->decision('blocked', '', 'Only the operator can say which GPU the unit has.'),
            'blocked_by' => ['gpu', 'operator_input'],
        ])->preventStrayPrompts();
        ProductTextEvidenceAgent::fake(fn (): array => $this->fail('Blocked must not search.'))->preventStrayPrompts();

        $this->assertFalse(app(ProductCardRecoverySupervisor::class)->recover($draft, $draft->telegram_update_id));

        $attempt = $draft->fresh()->card_repair_state['supervisor']['history'][0];
        $this->assertSame('blocked', $attempt['action']);
        $this->assertFalse($attempt['changed']);
        $row = ProductSourceAttempt::query()->where('phase', 'card_recovery')->latest('id')->first();
        $this->assertSame(['gpu', 'operator_input'], $row->output['diagnosis']['blocked_by']);
        $this->assertSame('Only the operator can say which GPU the unit has.', $row->output['diagnosis']['agent_comment']);
    }

    public function test_repair_contract_can_widen_only_an_already_affected_fact(): void
    {
        $this->measurableBudget(spent: 0.1);
        $draft = $this->draftWithFailure(withPatch: true);
        ProductCardRecoveryAgent::fake(fn (): array => [
            ...$this->decision('repair_contract', '', 'The Dell URL is a valid new citation, not an old enum value.'),
            'guidance' => 'Add the Dell URL to considered_source_urls for cpu and explain its applicability.',
            'repair_paths' => ['specifications.cpu.source_url', 'specifications.weight.value'],
        ])->preventStrayPrompts();

        // An unrelated key in the same request rejects the whole widening, so nothing unrelated slips in.
        $this->assertTrue(app(ProductCardRecoverySupervisor::class)->recover($draft, $draft->telegram_update_id));
        $state = $draft->fresh()->card_repair_state;
        $this->assertSame([], $state['supervisor']['history'][0]['detail']['expanded_paths']);
        $this->assertArrayHasKey('rejected_paths', $state['supervisor']['history'][0]['detail']);
        $this->assertStringContainsString('Dell URL', $state['supervisor']['guidance']);
        $this->assertSame(1, $state['supervisor']['generation']);

        ProductCardRecoveryAgent::fake(fn (): array => [
            ...$this->decision('repair_contract', '', 'Scope is too narrow.'),
            'repair_paths' => ['specifications.cpu.source_url'],
        ])->preventStrayPrompts();
        $this->assertTrue(app(ProductCardRecoverySupervisor::class)->recover($draft->fresh(), $draft->telegram_update_id));
        $patch = $draft->fresh()->card_repair_state['previous_feedback']['repair_context']['patch_repair'];
        $this->assertArrayHasKey('specifications.cpu.source_url', $patch['paths']);
        $this->assertArrayNotHasKey('specifications.weight.value', $patch['paths']);
    }

    public function test_no_paid_decision_without_remaining_budget(): void
    {
        $this->measurableBudget(spent: 1.0, exceeded: true);
        $draft = $this->draftWithFailure();
        ProductCardRecoveryAgent::fake(fn (): array => $this->fail('No AI call without budget.'))->preventStrayPrompts();

        $this->assertFalse(app(ProductCardRecoverySupervisor::class)->recover($draft, $draft->telegram_update_id));
        $this->assertNull($draft->fresh()->card_repair_state['supervisor'] ?? null);
    }

    public function test_read_text_refuses_private_targets_before_any_fetch(): void
    {
        $supervisor = app(ProductCardRecoverySupervisor::class);
        foreach (['http://127.0.0.1/specs', 'http://10.0.0.5/specs', 'http://localhost/specs', 'file:///etc/passwd', 'https://user:pw@maker.example/specs'] as $url) {
            try {
                $supervisor->validate($this->decision('read_text', $url, 'Read it.'));
                $this->fail('Private or credentialed URL accepted: '.$url);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('https://maker.example/specs', $supervisor->validate($this->decision('read_text', 'https://maker.example/specs', 'Read it.'))['query_or_url']);
    }

    public function test_saved_gallery_text_dead_end_is_supervised_to_a_reconciled_card_without_photo_search(): void
    {
        $this->measurableBudget(spent: 0.2);
        AppSetting::put('ai.fallback_sources_enabled', 'true');
        AppSetting::put('ai.card_assembly_fallback_model', null);
        Storage::fake('public');
        $draft = $this->draftWithFailure();
        $draft->update(['card_repair_state' => null]);
        $source = ['url' => self::PAGE, 'title' => 'Alienware 16X', 'type' => 'retailer'];
        $source['_photo_applicability'] = ProductPhotoApplicability::bind($draft, $source, [
            'verdict' => 'exact', 'reason' => 'Exact page.', 'source_model' => 'AC16251',
            'differences' => [], 'evidence' => ['Exact model on page.'], 'may_inspect_gallery' => true,
        ]);
        $draft->update(['photo_source_context' => $source, 'gallery_confirmed_sufficient' => true,
            'gallery_status' => 'partial', 'gallery_search_stop_reason' => 'specifications_unreconciled']);
        Storage::disk('public')->put('drafts/retained.webp', 'verified photo');
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/retained.webp',
            'source_url' => self::PAGE.'/1.jpg', 'checksum' => hash('sha256', 'retained'),
            'verification_status' => 'verified', 'photo_source_context' => $source]);

        $this->mock(ProductImageCandidateDiscovery::class)->shouldNotReceive('find');
        $calls = 0;
        $guidanceSeen = null;
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')
            ->andReturnUsing(function (ProductDraft $target, array $source) use (&$calls, &$guidanceSeen) {
                $calls++;
                if (($target->fresh()->specification_evidence ?? []) === []) {
                    return SpecificationReconciliationOutcome::notReady(
                        'Dell URL is not among allowed values.', repairContext: [
                            'recovery_action' => 'retry_current_source', 'photo_result_preserved' => true,
                            'failure_kind' => 'specification_evidence',
                            'citation_obligations' => ['cpu' => ['source_urls' => [self::DELL], 'source_refs' => [], 'explanation_required' => true]],
                            'unresolved_fields' => [['key' => 'cpu', 'status' => 'conflicting']],
                        ]);
                }
                $guidanceSeen = $source['_previous_assembly_feedback']['supervisor_guidance'] ?? null;

                return SpecificationReconciliationOutcome::alreadyReconciled(self::PAGE);
            });
        ProductCardRecoveryAgent::fake(fn (): array => [
            ...$this->decision('research_text', 'Does the Dell CPU listing apply to AC16251?', 'Need Dell applicability.'),
            'guidance' => 'Cite the Dell page for cpu and explain applicability.',
        ])->preventStrayPrompts();
        ProductTextEvidenceAgent::fake(fn (): array => ['facts' => [[
            'key' => 'cpu', 'name' => 'CPU', 'value' => 'Intel Core Ultra 9 275HX',
            'source_url' => self::DELL, 'source_excerpt' => 'Intel Core Ultra 9 275HX',
            'applicability' => 'Exact AC16251 configuration',
        ]], 'summary' => 'Confirmed.'])->preventStrayPrompts();

        $this->assertSame(1, app(ProductImageStorage::class)->stage($draft, null, $draft->telegram_update_id));

        $fresh = $draft->fresh();
        $this->assertFalse($fresh->reconciliationPending());
        $this->assertSame(self::PAGE, $fresh->specifications_reconciled_source_url);
        $this->assertCount(1, $fresh->media);
        $this->assertSame('Cite the Dell page for cpu and explain applicability.', $guidanceSeen);
        $this->assertDatabaseMissing('product_source_attempts', ['product_draft_id' => $draft->id,
            'action' => 'preserve_gallery_after_text_repair']);
    }

    private function decision(string $action, string $queryOrUrl, string $comment): array
    {
        return ['action' => $action, 'comment' => $comment, 'guidance' => '', 'query_or_url' => $queryOrUrl,
            'repair_paths' => [], 'blocked_by' => []];
    }

    private function measurableBudget(float $spent, bool $exceeded = false): void
    {
        $budget = $this->mock(ProductSearchCostBudget::class);
        $budget->shouldReceive('exceeded')->andReturn($exceeded);
        $budget->shouldReceive('unmeasurable')->andReturn(false);
        $budget->shouldReceive('spent')->andReturn($spent);
        $budget->shouldReceive('limit')->andReturn(1.0);
    }

    private function draftWithFailure(bool $withPatch = false): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 884250, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => 'Alienware 16X Aurora AC16251', 'payload' => [], 'status' => 'processing']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review', 'title' => 'Alienware 16X Aurora',
            'model' => 'AC16251', 'product_type' => 'laptop', 'primary_source_url' => self::PAGE,
            'specifications' => [['key' => 'cpu', 'name' => 'CPU', 'value' => 'Intel Core Ultra 9']],
            'sources' => [['url' => self::PAGE, 'title' => 'Alienware 16X', 'type' => 'retailer', 'image_urls' => []]],
            'image_urls' => []]);
        $candidate = ['title' => 'Alienware 16X Aurora', 'model' => 'AC16251', 'description' => 'Laptop',
            'specifications' => [
                ['key' => 'cpu', 'name' => 'CPU', 'value' => 'Intel Core Ultra 9', 'evidence' => 'confirmed', 'source_url' => self::PAGE],
                ['key' => 'weight', 'name' => 'Weight', 'value' => '2.7 kg', 'evidence' => 'confirmed', 'source_url' => self::PAGE],
            ]];
        $repairContext = [
            'failure_kind' => 'specification_evidence',
            'validation_errors' => ['cpu: differing or dropped cited observations require reason and considered_source_urls for the other sources. Missing source URLs: '.self::DELL.'.'],
            'citation_obligations' => ['cpu' => ['source_urls' => [self::DELL], 'source_refs' => [], 'explanation_required' => true]],
            'unresolved_fields' => [['key' => 'cpu', 'status' => 'conflicting']],
            'previous_candidate' => $candidate,
        ];
        $feedback = ['status' => 'not_ready', 'reason' => null, 'summary' => 'Dell URL is not among allowed values.', 'repair_context' => $repairContext];
        if ($withPatch) {
            $repair = app(CardRepairPatch::class)->prepare($draft, ['url' => self::PAGE], $update->id, $feedback);
            $this->assertNotNull($repair);
            $feedback['repair_context']['patch_repair'] = $repair;
        }
        $draft->update(['card_repair_state' => [
            'last_failure' => ['status' => 'not_ready', 'reason' => null, 'summary' => $feedback['summary'], 'repair_context' => $feedback['repair_context']],
            'previous_feedback' => $feedback,
        ]]);

        return $draft->fresh();
    }
}
