<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Agents\ProductSpecificationRepairAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductInputContext;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use App\Services\Products\SpecificationReconciliationStatus;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSpecificationReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_cited_specs_and_translations_survive_reconciliation(): void
    {
        $draft = $this->seedDraft(['sources' => [['url' => 'https://manufacturer.example/specs']]]);
        $this->seedEvidence($draft, 'https://manufacturer.example/specs', 'Three USB ports');
        // The card is English; its only translation is Czech, and it covers
        // specifications too. A translation is not evidence: one for a key the
        // card does not keep, or for a language the catalog does not store,
        // is left out without failing the verified card.
        ProductSpecificationReconciliationAgent::fake(fn () => [
            ...$this->fakeReconciled(specifications: [
                ['key' => 'usb_ports', 'name' => 'USB', 'value' => '3', 'evidence' => 'confirmed',
                    'source_url' => 'https://manufacturer.example/specs', 'source_excerpt' => 'Three USB ports'],
            ]), 'translations' => [
                'cs' => ['title' => 'Testovací produkt', 'description' => 'Ověřený popis', 'specifications' => [
                    ['key' => 'usb_ports', 'name' => 'USB porty', 'value' => '3'],
                    ['key' => 'not_on_the_card', 'name' => 'Něco', 'value' => 'jiného'],
                ]],
                'ru' => ['title' => 'Тестовый товар', 'description' => 'Проверенное описание', 'specifications' => []],
            ],
        ])->preventStrayPrompts();

        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/photos'], null);
        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame('usb_ports', $result->specifications[0]['key']);
        $this->assertSame('https://manufacturer.example/specs', $result->specifications[0]['source_url']);
        $this->assertSame(['cs' => [
            'title' => 'Testovací produkt',
            'description' => 'Ověřený popis',
            'specifications' => [['key' => 'usb_ports', 'name' => 'USB porty', 'value' => '3']],
        ]], $result->translations);
        $this->assertSame([], $draft->fresh()->image_urls);
    }

    public function test_new_text_evidence_survives_model_failure_and_counts_as_progress(): void
    {
        $draft = $this->seedDraft();
        ProductSpecificationReconciliationAgent::fake(function () use ($draft) {
            $draft->update(['specification_evidence' => [['key' => 'usb_ports', 'value' => '3', 'source_url' => 'https://maker.example/specs', 'source_excerpt' => 'Three USB ports']]]);
            throw new \RuntimeException('Interrupted after research');
        })->preventStrayPrompts();
        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/photos'], null);
        $this->assertFalse($result->isReconciled());
        $this->assertTrue($result->madeProgress);
        $this->assertCount(1, $draft->fresh()->specification_evidence);
    }

    public function test_a_long_summary_does_not_discard_valid_card_fields_or_trigger_another_call(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'summary' => str_repeat('Проверено. ', 100)];
        })->preventStrayPrompts();
        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);
        $this->assertTrue($outcome->isReconciled());
        $this->assertSame(1, $calls);
        $this->assertSame(500, mb_strlen($outcome->summary));
    }

    public function test_rephrasing_saved_observations_does_not_buy_more_assembly_attempts(): void
    {
        $fact = ['key' => 'condition', 'value' => 'Not stated', 'source_url' => 'https://shop.example/product',
            'source_excerpt' => 'Test Product', 'applicability' => 'Exact model'];
        $draft = $this->seedDraft(['specification_evidence' => [$fact]]);
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use ($draft, $fact, &$calls): array {
            $calls++;
            $draft->update(['specification_evidence' => [...$draft->fresh()->specification_evidence,
                [...$fact, 'key' => 'offer_condition', 'value' => "Still not stated, attempt {$calls}",
                    'applicability' => "The same page still says nothing, explanation {$calls}"],
            ]]);

            return [...$this->fakeReconciled(), 'overall_status' => 'not_ready', 'summary' => "Missing condition {$calls}"];
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertFalse($outcome->madeProgress);
        $this->assertSame(2, $calls);
        // Interpretations remain available for review; they just are not new observations.
        $this->assertCount(3, $draft->fresh()->specification_evidence);
    }

    public function test_changed_numbers_and_conflicting_sources_still_count_as_new_observations(): void
    {
        foreach ([
            ['source_url' => 'https://shop.example/product', 'source_excerpt' => 'Weight: 25 kg'],
            ['source_url' => 'https://maker.example/product', 'source_excerpt' => 'Weight: 2.5 kg'],
        ] as $newObservation) {
            $fact = ['key' => 'weight', 'value' => '2.5 kg', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'Weight: 2.5 kg'];
            $draft = $this->seedDraft(['specification_evidence' => [$fact]]);
            ProductSpecificationReconciliationAgent::fake(function () use ($draft, $fact, $newObservation): array {
                $draft->update(['specification_evidence' => [$fact, [...$fact, ...$newObservation]]]);

                return [...$this->fakeReconciled(), 'overall_status' => 'not_ready'];
            })->preventStrayPrompts();

            $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/product'], null);
            $this->assertTrue($outcome->madeProgress);
            $this->assertCount(2, $draft->fresh()->specification_evidence);
        }
    }

    public function test_reordering_removing_or_reformatting_old_evidence_is_not_progress(): void
    {
        $first = ['key' => 'ram', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 16 GB'];
        $second = ['key' => 'storage', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'SSD: 512 GB'];
        foreach ([[$second, $first], [$first], [[...$first, 'source_excerpt' => "RAM:  16\nGB"], $second]] as $after) {
            $draft = $this->seedDraft(['specification_evidence' => [$first, $second]]);
            ProductSpecificationReconciliationAgent::fake(function () use ($draft, $after): array {
                $draft->update(['specification_evidence' => $after]);

                return [...$this->fakeReconciled(), 'overall_status' => 'not_ready'];
            })->preventStrayPrompts();

            $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/product'], null);
            $this->assertFalse($outcome->madeProgress);
        }
    }

    public function test_assembly_recovers_before_review_and_passes_the_real_error_to_the_agent(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use (&$calls): array {
            $payload = json_decode($prompt, true);
            if (++$calls === 1) {
                throw new \RuntimeException('Provider response timed out.');
            }
            $this->assertSame('technical_error', $payload['previous_assembly_feedback']['reason']);
            $this->assertSame('Provider response timed out.', $payload['previous_assembly_feedback']['summary']);

            return $this->fakeReconciled();
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)
            ->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled());
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('product_source_attempts', 2);
    }

    public function test_assembly_stops_identical_failed_verdicts_without_new_progress(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'overall_status' => 'not_ready', 'summary' => 'No evidence of this product.'];
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)
            ->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertFalse($outcome->isReconciled());
        $this->assertSame(2, $calls);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_agent_can_return_an_unverifiable_offer_to_search_without_repairing_or_publishing_it(): void
    {
        app(AiSettings::class)->saveCardAssemblyFallbackModel('gpt-6-sol');
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'overall_status' => 'not_ready',
                'recovery_action' => 'try_other_source', 'summary' => 'Состояние этого предложения не подтверждено.'];
        })->preventStrayPrompts();
        $outcome = app(ProductCardAssembly::class)->complete($draft,
            ['url' => 'https://shop.example/product'], $draft->telegram_update_id);
        $this->assertFalse($outcome->isReconciled());
        $this->assertSame('try_other_source', $outcome->repairContext['recovery_action']);
        $this->assertSame(1, $calls);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_agent_repairs_schema_error_with_its_rejected_candidate_and_field_errors(): void
    {
        $draft = $this->seedDraft(['image_urls' => ['https://shop.example/photo.jpg']]);
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(title: 'Proposed card'), 'model' => null];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;
            $payload = json_decode($prompt, true);
            $feedback = $payload['previous_assembly_feedback'];
            $this->assertSame('invalid_response', $feedback['reason']);
            $this->assertSame(['model' => null], $payload['repair']['current_values']);
            $this->assertArrayNotHasKey('previous_candidate', $feedback['repair_context']);
            $this->assertArrayHasKey('model', $feedback['repair_context']['validation_errors']);

            return $this->fakeRepair($prompt, ['model' => 'Test Product']);
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);
        $this->assertTrue($outcome->isReconciled());
        $this->assertSame('Proposed card', $outcome->title);
        $this->assertSame(2, $calls);
        $this->assertSame(['https://shop.example/photo.jpg'], $draft->fresh()->image_urls);
        $this->assertSame('Test Product', $draft->fresh()->title);
    }

    public function test_agent_can_repair_rejected_evidence_without_researching_the_product_again(): void
    {
        $draft = $this->seedDraft(['specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']]]);
        $url = 'https://shop.example/product';
        $this->seedEvidence($draft, $url, 'Installed memory: 16 GB');
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls, $url): array {
            $calls++;

            return $this->fakeReconciled([[
                'key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed',
                'source_url' => $url, 'source_excerpt' => 'made-up quote',
            ]]);
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;
            $payload = json_decode($prompt, true);
            $context = $payload['previous_assembly_feedback']['repair_context'];
            $this->assertSame('made-up quote', $payload['repair']['affected_candidate_facts']['ram']['source_excerpt']);
            $this->assertStringContainsString('excerpt was not observed', implode(' ', $context['validation_errors']));

            return $this->fakeRepair($prompt, ['specifications.ram.source_excerpt' => 'Installed memory: 16 GB']);
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => $url], $draft->telegram_update_id);
        $this->assertTrue($outcome->isReconciled());
        $this->assertSame(2, $calls);
        $this->assertSame('Installed memory: 16 GB', $outcome->specifications[0]['source_excerpt']);
        $this->assertDatabaseCount('product_source_page_evidence', 1);
    }

    public function test_joined_quote_feedback_reaches_the_next_assembly_attempt_with_saved_evidence(): void
    {
        $draft = $this->seedDraft(['specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']]]);
        $url = 'https://shop.example/product';
        $this->seedEvidence($draft, $url, 'Installed memory is 16 GB. This model has two slots. One slot is free.');
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls, $url): array {
            $calls++;

            return $this->fakeReconciled([[
                'key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed',
                'source_url' => $url, 'source_excerpt' => 'Installed memory is 16 GB. One slot is free.',
            ]]);
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls, $url): array {
            $calls++;
            $payload = json_decode($prompt, true);
            $context = $payload['previous_assembly_feedback']['repair_context'];
            $this->assertStringContainsString('Saved text exists for this URL', implode(' ', $context['validation_errors']));
            $this->assertStringContainsString('separate nonadjacent passages', implode(' ', $context['validation_errors']));
            $this->assertSame($url, $payload['repair']['affected_candidate_facts']['ram']['source_url']);
            $this->assertStringContainsString('This model has two slots.', $payload['source_specification_text']);

            return $this->fakeRepair($prompt, ['specifications.ram.source_excerpt' => "Installed memory is 16 GB.\nOne slot is free."]);
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => $url], $draft->telegram_update_id);
        $this->assertTrue($outcome->isReconciled(), $outcome->summary ?? '');
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('product_source_page_evidence', 1);
    }

    public function test_repeating_the_same_invalid_candidate_stops_without_publishing_it(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'model' => null];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;

            return $this->fakeRepair($prompt, ['model' => null]);
        })->preventStrayPrompts();
        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);
        $this->assertSame(2, $calls);
        $this->assertFalse($result->isReconciled());
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_new_web_quotes_do_not_extend_the_same_empty_response_repair_across_models(): void
    {
        app(AiSettings::class)->saveModel('gpt-6-luna');
        app(AiSettings::class)->saveCardAssemblyFallbackModel('gpt-6-sol');
        $draft = $this->seedDraft();
        $models = [];
        ProductSpecificationReconciliationAgent::fake(function (string $prompt, $attachments, $provider, string $model) use ($draft, &$models): array {
            $models[] = $model;
            $draft->update(['specification_evidence' => [...($draft->fresh()->specification_evidence ?? []), [
                'key' => 'weight', 'value' => '1 kg', 'source_url' => 'https://shop.example/specs',
                'source_excerpt' => 'New but irrelevant observation '.count($models),
            ]]]);

            return [];
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id, allowFallbackModel: true);

        $this->assertSame(['gpt-6-luna', 'gpt-6-luna', 'gpt-6-sol'], $models);
        $this->assertSame('response_format', $result->repairContext['failure_kind']);
        $this->assertTrue($result->madeProgress, 'New observations remain stored, but they did not fix JSON');
        $this->assertCount(3, $draft->fresh()->specification_evidence);
        $run = AiRun::query()->latest('id')->first();
        $this->assertSame('[]', $run->activity['response_diagnostics']['raw_text']);
        $this->assertArrayHasKey('finish_reason', $run->activity['response_diagnostics']);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_a_compatible_photo_source_cannot_be_adopted_as_the_product(): void
    {
        $draft = $this->seedDraft();
        ProductSpecificationReconciliationAgent::fake(fn () => [
            ...$this->fakeReconciled(), 'offer_selection' => [
                'action' => 'adopt_source_offer', 'source_url' => 'https://shop.example/photos',
                'reason' => 'Replace the requested configuration with the photo source.',
            ],
        ])->preventStrayPrompts();

        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, [
            'url' => 'https://shop.example/photos', '_photo_applicability' => ['verdict' => 'compatible'],
        ], $draft->telegram_update_id);

        $this->assertSame('invalid_response', $result->unavailableReason);
        $this->assertStringContainsString('only illustrates the requested product', $result->summary);
        $this->assertSame('Test Product', $draft->fresh()->title);
    }

    public function test_structured_label_evidence_reaches_assembly_and_retains_its_own_provenance(): void
    {
        $draft = $this->seedDraft(['specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB']]]);
        $update = $draft->telegramUpdate;
        $input = ProductInputContext::fromPhotoExtraction($update, [
            'has_text' => true, 'text' => 'Test Product, 16 GB DDR4', 'identifiers' => [],
            'observations' => [[
                'key' => 'ram_capacity', 'name' => 'Memory capacity', 'value' => '16 GB',
                'source_kind' => 'photo_label', 'source_excerpt' => 'Memory: 16 GB DDR4',
                'confidence' => 0.99, 'uncertain_characters' => null,
            ]],
        ]);
        $update->update(['text' => 'Test Product, 16 GB DDR4', 'payload' => [ProductInputContext::PAYLOAD_KEY => $input]]);
        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($update): array {
            $payload = json_decode($prompt, true);
            $this->assertNull($payload['original_operator_request'], 'OCR is evidence, not a human requirement');
            $this->assertSame('Memory: 16 GB DDR4', $payload['input_evidence'][0]['source_excerpt']);

            return $this->fakeReconciled(specifications: [[
                'key' => 'ram', 'name' => 'Memory', 'value' => '16 GB', 'evidence' => 'confirmed',
                'source_kind' => 'photo_label', 'source_ref' => 'telegram-photo:'.$update->id.':1',
                'source_url' => null, 'source_excerpt' => 'Memory: 16 GB DDR4',
            ]]);
        })->preventStrayPrompts();

        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/photos'], $draft->telegram_update_id);

        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame('photo_label', $result->specifications[0]['source_kind']);
        $this->assertSame('telegram-photo:'.$update->id.':1', $result->specifications[0]['source_ref']);
        $this->assertNull($result->specifications[0]['source_url']);
        $this->assertSame(0, ProductSourcePageEvidence::query()->count());
    }

    public function test_an_unresolved_text_fact_does_not_reject_an_accepted_gallery(): void
    {
        $draft = $this->seedDraft();
        ProductSpecificationReconciliationAgent::fake(fn () => [
            ...$this->fakeReconciled(), 'overall_status' => 'not_ready', 'recovery_action' => 'try_other_source',
            'unresolved_fields' => [['key' => 'keyboard_layout', 'status' => 'unknown', 'reason' => 'Requested layout not documented yet.']],
        ])->preventStrayPrompts();

        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, [
            'url' => 'https://shop.example/photos', '_photo_applicability' => ['verdict' => 'exact'],
        ], $draft->telegram_update_id);

        $this->assertFalse($result->isReconciled());
        $this->assertTrue($result->repairContext['photo_result_preserved']);
        $this->assertSame('retry_current_source', $result->repairContext['recovery_action']);
        $this->assertSame('keyboard_layout', $result->repairContext['unresolved_fields'][0]['key']);
    }

    public function test_fact_recovery_state_survives_jobs_without_buying_the_same_format_failure(): void
    {
        app(AiSettings::class)->saveModel('gpt-6-luna');
        app(AiSettings::class)->saveCardAssemblyFallbackModel('gpt-6-sol');
        $draft = $this->seedDraft(['image_urls' => ['https://shop.example/retained.jpg']]);
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [];
        })->preventStrayPrompts();
        $source = ['url' => 'https://shop.example/photos', '_photo_applicability' => ['verdict' => 'exact']];
        $state = [];
        $first = app(ProductCardAssembly::class)->resumeFacts($draft, $source, $draft->telegram_update_id, $state);
        // The stronger model is not an automatic second pass any more.
        $this->assertSame(2, $calls);
        $this->assertSame('invalid_response', $first->unavailableReason);

        // Simulate a different worker reading persisted JSON, with another
        // quote arriving meanwhile. New facts cannot fix malformed JSON.
        $state = json_decode(json_encode($state), true);
        $this->seedEvidence($draft, 'https://shop.example/specs', 'RAM: 16 GB');
        $second = app(ProductCardAssembly::class)->resumeFacts($draft->fresh(), $source, $draft->telegram_update_id, $state);

        $this->assertSame(2, $calls);
        $this->assertSame('invalid_response', $second->unavailableReason);
        $this->assertSame(['https://shop.example/retained.jpg'], $draft->fresh()->image_urls);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_a_failure_cached_under_the_previous_repair_contract_is_assembled_again(): void
    {
        app(AiSettings::class)->saveModel('gpt-6-luna');
        app(AiSettings::class)->saveCardAssemblyFallbackModel('gpt-6-sol');
        $draft = $this->seedDraft(['image_urls' => ['https://shop.example/retained.jpg']]);
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [];
        })->preventStrayPrompts();
        $source = ['url' => 'https://shop.example/photos', '_photo_applicability' => ['verdict' => 'exact']];
        $state = [];
        app(ProductCardAssembly::class)->resumeFacts($draft, $source, $draft->telegram_update_id, $state);
        $this->assertSame(2, $calls);

        // A job persisted before contract v2 had no contract_version: its
        // failure was judged under the old agent payload and cannot be reused.
        unset($state['contract_version']);
        $state = json_decode(json_encode($state), true);
        app(ProductCardAssembly::class)->resumeFacts($draft->fresh(), $source, $draft->telegram_update_id, $state);

        // Assembled again (already-seen failure signatures still stop repeats early).
        $this->assertGreaterThan(2, $calls);
        $this->assertSame(2, $state['contract_version']);
    }

    public function test_observed_physical_conflict_reopens_photo_selection_instead_of_freezing_a_wrong_gallery(): void
    {
        $draft = $this->seedDraft(['color' => 'Black']);
        $this->seedEvidence($draft, 'https://shop.example/photos', 'This variant has a white chassis.');
        ProductSpecificationReconciliationAgent::fake(fn () => [
            ...$this->fakeReconciled(), 'overall_status' => 'not_ready', 'recovery_action' => 'try_other_source',
            'photo_applicability_conflict' => [
                'physical_feature' => 'color', 'reason' => 'The white gallery cannot illustrate the requested black chassis.',
                'source_url' => 'https://shop.example/photos', 'source_excerpt' => 'This variant has a white chassis.',
            ],
        ])->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, [
            'url' => 'https://shop.example/photos', '_photo_applicability' => ['verdict' => 'compatible'],
        ], $draft->telegram_update_id);

        $this->assertSame(SpecificationReconciliationStatus::NotReady, $outcome->status);
        $this->assertSame('try_other_source', $outcome->repairContext['recovery_action']);
        $this->assertFalse($outcome->repairContext['photo_result_preserved']);
        $this->assertSame('color', $outcome->repairContext['photo_applicability_conflict']['physical_feature']);
    }

    public function test_an_invented_physical_conflict_cannot_revoke_a_gallery(): void
    {
        $draft = $this->seedDraft();
        ProductSpecificationReconciliationAgent::fake(fn () => [
            ...$this->fakeReconciled(), 'overall_status' => 'not_ready', 'recovery_action' => 'try_other_source',
            'photo_applicability_conflict' => [
                'physical_feature' => 'color', 'reason' => 'The gallery is white.',
                'source_url' => 'https://shop.example/unread', 'source_excerpt' => 'White chassis.',
            ],
        ])->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, [
            'url' => 'https://shop.example/photos', '_photo_applicability' => ['verdict' => 'exact'],
        ], $draft->telegram_update_id);

        $this->assertSame('invalid_response', $outcome->unavailableReason);
        $this->assertSame('specification_evidence', $outcome->repairContext['failure_kind']);
        $this->assertStringContainsString('source excerpt was not observed', $outcome->summary);
        $this->assertArrayNotHasKey('recovery_action', $outcome->repairContext);
    }

    public function test_fact_recovery_requires_new_observations_after_a_repeated_factual_blocker(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'overall_status' => 'not_ready',
                'unresolved_fields' => [['key' => 'keyboard_layout', 'status' => 'unknown', 'reason' => 'Not found.']]];
        })->preventStrayPrompts();
        $state = [];
        $source = ['url' => 'https://shop.example/photos', '_photo_applicability' => ['verdict' => 'exact']];
        app(ProductCardAssembly::class)->resumeFacts($draft, $source, $draft->telegram_update_id, $state);
        app(ProductCardAssembly::class)->resumeFacts($draft->fresh(), $source, $draft->telegram_update_id, $state);
        $this->assertSame(2, $calls);

        $this->seedEvidence($draft, 'https://shop.example/specs', 'Keyboard: Czech');
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return $this->fakeReconciled();
        })->preventStrayPrompts();
        $result = app(ProductCardAssembly::class)->resumeFacts($draft->fresh(), $source, $draft->telegram_update_id, $state);

        $this->assertTrue($result->isReconciled());
        $this->assertSame(3, $calls);
        $this->assertNull($state['last_failure']);
    }

    public function test_unrelated_new_quotes_do_not_extend_an_unchanged_explicit_blocker(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use ($draft, &$calls): array {
            $calls++;
            $this->seedEvidence($draft, 'https://shop.example/specs-'.$calls, 'Unrelated marketing detail '.$calls);

            return [...$this->fakeReconciled(), 'overall_status' => 'not_ready',
                'unresolved_fields' => [['key' => 'keyboard_layout', 'status' => 'unknown', 'reason' => 'Still missing '.$calls]]];
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertSame(2, $calls);
        $this->assertFalse($outcome->isReconciled());
        $this->assertSame('keyboard_layout', $outcome->repairContext['unresolved_fields'][0]['key']);
    }

    public function test_resolving_explicit_blockers_allows_further_repair_without_another_web_call(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;
            if ($calls === 4) {
                return $this->fakeReconciled();
            }

            return [...$this->fakeReconciled(), 'overall_status' => 'not_ready',
                'unresolved_fields' => array_map(fn (string $key): array => ['key' => $key, 'status' => 'unknown', 'reason' => 'Missing.'],
                    array_slice(['ram', 'keyboard_layout', 'weight'], $calls - 1))];
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled());
        $this->assertSame(4, $calls);
    }

    public function test_a_long_explanation_of_one_value_does_not_throw_away_the_card(): void
    {
        // Request #102: a whole card was refused because the 19th field's
        // reason ran past 300 characters. Nobody reads it but the audit.
        $draft = $this->seedDraft(['sources' => [['url' => 'https://manufacturer.example/specs']]]);
        $this->seedEvidence($draft, 'https://manufacturer.example/specs', 'Three USB ports');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->fakeReconciled(specifications: [
            ['key' => 'usb_ports', 'name' => 'USB', 'value' => '3', 'evidence' => 'confirmed',
                'source_url' => 'https://manufacturer.example/specs', 'source_excerpt' => 'Three USB ports',
                'reason' => str_repeat('The manufacturer lists the ports once. ', 20)],
        ]))->preventStrayPrompts();

        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/photos'], null);

        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame('3', $result->specifications[0]['value']);
    }

    public function test_assembly_keeps_repairing_past_three_attempts_while_each_one_fixes_something(): void
    {
        // Requests #99/#102: every attempt cleared the last error and hit a
        // smaller unresolved set; a cap of three ended cards one fix away.
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'title' => null, 'model' => null, 'description' => null, 'summary' => null];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls): array {
            $field = ['title', 'model', 'description', 'summary'][$calls - 1];
            $calls++;
            $payload = json_decode($prompt, true);
            $this->assertArrayHasKey($field, $payload['repair']['current_values']);

            return $this->fakeRepair($prompt, [$field => $this->fakeReconciled()[$field]]);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($result->isReconciled());
        $this->assertSame(5, $calls);
    }

    public function test_the_fallback_model_finishes_a_card_the_assembly_model_could_not(): void
    {
        // Request #51: gpt-6-luna quoted one display line the same wrong way
        // until the loop guard stopped it, and the operator got "not finished".
        $settings = app(AiSettings::class);
        $settings->saveModel('gpt-6-luna');
        $settings->saveCardAssemblyFallbackModel('gpt-6-sol');
        $draft = $this->seedDraft();
        $models = [];
        ProductSpecificationReconciliationAgent::fake(function (string $prompt, $attachments, $provider, string $model) use (&$models): array {
            $models[] = $model;

            return [...$this->fakeReconciled(), 'title' => null];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt, $attachments, $provider, string $model) use (&$models): array {
            $models[] = $model;

            return $this->fakeRepair($prompt, ['title' => $model === 'gpt-6-luna' ? null : 'Test Product']);
        })->preventStrayPrompts();

        $plain = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);
        $this->assertFalse($plain->isReconciled(), 'without permission the 20x pricier model never runs');
        $this->assertNotContains('gpt-6-sol', $models);
        $models = [];

        $result = app(ProductCardAssembly::class)->complete($draft->fresh(), ['url' => 'https://shop.example/product'], $draft->telegram_update_id, allowFallbackModel: true);

        $this->assertTrue($result->isReconciled());
        $this->assertSame(['gpt-6-luna', 'gpt-6-luna', 'gpt-6-sol'], $models, 'the cheap model until it repeats itself, then the fallback');
    }

    public function test_waiting_for_a_busy_openai_queue_is_not_a_repeated_failure(): void
    {
        // Request #51: assembly ran beside another search, twice gave up
        // waiting for the shared OpenAI queue, and that read as one failure
        // repeated - the card ended although nothing was wrong with it.
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            if (++$calls <= 2) {
                throw new \RuntimeException('OpenAI heavy operation queue timed out while waiting for the previous request.',
                    previous: new LockTimeoutException);
            }

            return $this->fakeReconciled();
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($result->isReconciled());
        $this->assertSame(3, $calls);
    }

    public function test_a_rate_limited_provider_is_waited_out_not_counted_as_a_failure(): void
    {
        // Request #53: OpenAI rate-limited both models for a minute and the
        // card ended after two identical "rate limited" errors.
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            if (++$calls <= 2) {
                throw new \RuntimeException('Application rate limited by AI provider [openai].');
            }

            return $this->fakeReconciled();
        })->preventStrayPrompts();
        // The gate's own cooldown would sleep for real between the attempts.
        $this->app->instance(OpenAiHeavyOperationGate::class, new OpenAiHeavyOperationGate(fn () => null));

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($result->isReconciled());
        $this->assertSame(3, $calls);
    }

    public function test_without_a_fallback_model_a_repeated_failure_is_still_the_end(): void
    {
        app(AiSettings::class)->saveModel('gpt-6-luna');
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'title' => null];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;

            return $this->fakeRepair($prompt, ['title' => null]);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertFalse($result->isReconciled());
        $this->assertSame(2, $calls);
    }

    public function test_failures_taking_turns_are_one_loop_and_end_the_repair(): void
    {
        // A, B, A: each differs from the one before it, and none of it is progress.
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'title' => null];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls): array {
            // A and B are different validation errors of the same allowed
            // field: a patch cannot break an unrelated, previously valid model.
            $value = ++$calls % 2 === 0 ? ['not a string'] : null;

            return $this->fakeRepair($prompt, ['title' => $value]);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertFalse($result->isReconciled());
        $this->assertSame(3, $calls, 'the third attempt repeats the first: stop there, not at the guard');
    }

    public function test_different_validation_errors_with_the_same_summary_get_another_repair_attempt(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        $summaries = [];
        $modelErrors = [];
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(), 'title' => null, 'model' => ['not a string']];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls, &$summaries, &$modelErrors): array {
            $feedback = json_decode($prompt, true)['previous_assembly_feedback'];
            $summaries[] = $feedback['summary'];
            $this->assertSame('invalid_response', $feedback['reason']);
            $this->assertArrayHasKey('model', $feedback['repair_context']['validation_errors']);
            $modelErrors[] = $feedback['repair_context']['validation_errors']['model'];

            // Both summaries contain the unchanged title error plus one more.
            // The second error changes from model type to model length, within
            // the existing scope; it is not an invented new broken field.
            return ++$calls === 2
                ? $this->fakeRepair($prompt, ['model' => str_repeat('x', 256)])
                : $this->fakeRepair($prompt, ['title' => 'Test Product', 'model' => 'Test Product']);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertSame(3, $calls);
        $this->assertSame($summaries[0], $summaries[1]);
        $this->assertNotSame($modelErrors[0], $modelErrors[1]);
        $this->assertTrue($result->isReconciled());
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_rewording_a_candidate_does_not_hide_repeated_validation_errors(): void
    {
        $draft = $this->seedDraft(['image_urls' => ['https://shop.example/photo.jpg']]);
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            $calls++;

            return [...$this->fakeReconciled(title: 'Proposed title', summary: 'Reworded explanation 1'),
                'model' => ['candidate' => 'Invalid model attempt 1']];
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;

            return $this->fakeRepair($prompt, ['model' => ['candidate' => 'Invalid model attempt '.$calls]],
                'Reworded explanation '.$calls);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertSame(2, $calls);
        $this->assertSame('invalid_response', $result->unavailableReason);
        $this->assertFalse($result->madeProgress);
        $this->assertSame('Test Product', $draft->fresh()->title);
        $this->assertSame(['https://shop.example/photo.jpg'], $draft->fresh()->image_urls);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_a_write_failure_after_a_valid_model_response_is_technical_not_invalid_response(): void
    {
        $draft = $this->seedDraft();
        $writeFailed = false;
        // Inject a one-off failure at the actual audit write boundary, after
        // the response and candidate have already passed normal validation.
        AiRun::updating(function (AiRun $run) use (&$writeFailed): void {
            if (! $writeFailed && $run->isDirty('status') && $run->status === 'completed') {
                $writeFailed = true;
                throw new \RuntimeException('Audit write interrupted after response validation.');
            }
        });
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->fakeReconciled(title: 'Valid proposal'))
            ->preventStrayPrompts();

        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($writeFailed);
        $this->assertSame('technical_error', $result->unavailableReason);
        $this->assertSame('Valid proposal', $result->repairContext['previous_candidate']['title']);
        $this->assertSame('failed', AiRun::query()->latest('id')->first()->status);
        $this->assertSame('Test Product', $draft->fresh()->title);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_spent_money_does_not_stop_a_started_assembly_from_repairing_its_card(): void
    {
        // Request #89: a complete card was refused over a missing citation and
        // its repair was forbidden at $1.14 of $1, so the operator got nothing.
        // The money limit guards against endless spending; assembly is bounded
        // by its own attempts instead.
        $draft = $this->seedDraft();
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('exceeded')->andReturnTrue();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('Provider response timed out.');
            }

            return $this->fakeReconciled();
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)
            ->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled());
        $this->assertSame(2, $calls);
    }

    public function test_assembly_does_not_start_recovery_once_the_jobs_time_is_gone(): void
    {
        $draft = $this->seedDraft();
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturn(SpecificationReconciliationOutcome::unavailable('technical_error'));
        $this->mock(ProductSearchTimeBudget::class)->shouldReceive('assemblyCanStart')->once()->andReturnFalse();

        $outcome = app(ProductCardAssembly::class)
            ->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($outcome->isBudgetExhausted());
    }

    public function test_rewording_the_same_unresolved_card_without_new_evidence_does_not_buy_a_third_call(): void
    {
        $draft = $this->seedDraft();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls): array {
            return [...$this->fakeReconciled(), 'overall_status' => 'not_ready',
                'summary' => ++$calls === 1 ? 'The page names another SKU.' : 'This is still a different configuration.'];
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertFalse($result->isReconciled());
        $this->assertSame(2, $calls);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    private function telegramUpdateId(): int
    {
        return TelegramUpdate::query()->create([
            'update_id' => random_int(1_000_000, 9_000_000),
            'telegram_user_id' => '12345',
            'chat_id' => '12345',
            'text' => 'test',
            'payload' => ['update_id' => 1],
            'status' => 'received',
        ])->id;
    }

    public function test_an_existing_field_cannot_be_published_without_its_source_evidence(): void
    {
        $draft = $this->seedDraft(['specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']]]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->fakeReconciled([
            ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed'],
        ]))->preventStrayPrompts();
        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/product'], null);
        $this->assertFalse($result->isReconciled());
        $this->assertStringContainsString('source evidence', $result->summary);
        $this->assertSame('16 GB', $draft->fresh()->specifications[0]['value']);
    }

    public function test_corrected_must_change_the_value_not_just_claim_that_it_was_fixed(): void
    {
        $draft = $this->seedDraft(['specifications' => [['key' => 'wifi', 'name' => 'Wi-Fi', 'value' => 'Wi-Fi 6E']]]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'Wireless: Wi-Fi');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->fakeReconciled([
            ['key' => 'wifi', 'name' => 'Wi-Fi', 'value' => 'Wi-Fi 6E', 'evidence' => 'corrected',
                'reason' => '6E is not documented, removing the unsupported detail.',
                'source_url' => 'https://shop.example/product', 'source_excerpt' => 'Wireless: Wi-Fi'],
        ]))->preventStrayPrompts();
        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/product'], null);
        $this->assertFalse($result->isReconciled());
        $this->assertStringContainsString('corrected but unchanged', $result->summary);
    }

    public function test_cited_unchanged_capacity_with_reviewed_conflicts_does_not_buy_an_assembly_retry(): void
    {
        $fact = ['key' => 'maximum_supported_memory', 'name' => 'Maximum supported memory', 'value' => '256 GB',
            'source_url' => 'https://maker.example/specs', 'source_excerpt' => 'Max. Memory: 256 GB'];
        $other = [...$fact, 'value' => '192 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'Max. Memory: 192 GB'];
        $draft = $this->seedDraft(['specifications' => [$fact], 'specification_evidence' => [$other]]);
        $reason = 'The manufacturer identifies this model; the retailer memory ceiling is outdated.';
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls, $fact, $other, $reason): array {
            $calls++;

            return $this->fakeReconciled([[...$fact, 'evidence' => 'corrected', 'reason' => $reason,
                'considered_source_urls' => [$other['source_url']]]]);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame(1, $calls);
        $this->assertSame($fact['value'], $result->specifications[0]['value']);
        $this->assertSame($fact['source_excerpt'], $result->specifications[0]['source_excerpt']);
        $this->assertSame($reason, $result->specifications[0]['reason']);
        $this->assertSame([$other['source_url']], $result->specifications[0]['considered_source_urls']);
    }

    public function test_a_known_source_url_does_not_authorize_a_fabricated_excerpt(): void
    {
        $draft = $this->seedDraft(['sources' => [['url' => 'https://maker.example/specs']]]);
        $this->seedEvidence($draft, 'https://maker.example/specs', 'Three USB ports');
        ProductSpecificationReconciliationAgent::fake(fn () => $this->fakeReconciled([
            ['key' => 'usb_ports', 'name' => 'USB', 'value' => '4', 'evidence' => 'confirmed',
                'source_url' => 'https://maker.example/specs', 'source_excerpt' => 'Four USB ports'],
        ]))->preventStrayPrompts();
        $result = app(ProductSpecificationReconciler::class)->reconcile($draft, ['url' => 'https://shop.example/photos'], null);
        $this->assertFalse($result->isReconciled());
        $this->assertStringContainsString('excerpt was not observed', $result->summary);
    }

    public function test_changing_a_previously_cited_value_requires_explicit_conflict_review_and_can_recover(): void
    {
        $old = 'https://maker.example/specs';
        $new = 'https://shop.example/product';
        $draft = $this->seedDraft(['specifications' => [
            ['key' => 'ram', 'name' => 'RAM', 'value' => '64 GB', 'source_url' => $old, 'source_excerpt' => 'Installed RAM: 64 GB'],
        ]]);
        $this->seedEvidence($draft, $new, 'Installed RAM: 48 GB');
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use (&$calls, $new): array {
            $calls++;

            return $this->fakeReconciled([
                ['key' => 'ram', 'name' => 'RAM', 'value' => '48 GB', 'evidence' => 'corrected',
                    'reason' => 'The earlier document describes another regional revision; the selected offer explicitly installs 48 GB.',
                    'source_url' => $new, 'source_excerpt' => 'Installed RAM: 48 GB',
                    'considered_source_urls' => []],
            ]);
        })->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$calls, $old, $new): array {
            $calls++;
            $payload = json_decode($prompt, true);
            $this->assertStringContainsString('considered_source_urls', $payload['previous_assembly_feedback']['summary']);
            $this->assertSame('64 GB', $payload['current_specifications'][0]['value']);
            $this->assertSame('48 GB', $payload['repair']['affected_candidate_facts']['ram']['value']);

            return $this->fakeRepair($prompt, ['specifications.ram.considered_source_urls' => [$old, $new]]);
        })->preventStrayPrompts();
        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => $new], null);
        $this->assertSame(2, $calls);
        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame([$old, $new], $result->specifications[0]['considered_source_urls']);
        $this->assertSame([], $draft->fresh()->image_urls);
    }

    public function test_resolved_conflict_needs_only_one_call_without_repeating_the_selected_url(): void
    {
        $old = 'https://maker.example/family';
        $selected = 'https://shop.example/product';
        $draft = $this->seedDraft(['specifications' => [
            ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'source_url' => $old, 'source_excerpt' => 'Base RAM: 16 GB'],
        ]]);
        $this->seedEvidence($draft, $selected, 'Installed RAM: 32 GB');
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use ($old, $selected, &$calls): array {
            $calls++;

            return $this->fakeReconciled([
                ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB', 'evidence' => 'corrected',
                    'reason' => 'The former quote describes the base option, this exact offer specifies the installed RAM.',
                    'source_url' => $selected, 'source_excerpt' => 'Installed RAM: 32 GB',
                    'considered_source_urls' => [$old]],
            ]);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => $selected], null);
        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame(1, $calls);
        $this->assertSame('32 GB', $result->specifications[0]['value']);
        $this->assertSame($selected, $result->specifications[0]['source_url']);
        $this->assertSame([$old], $result->specifications[0]['considered_source_urls']);
    }

    public function test_evidence_fetched_during_this_call_can_support_several_corrections_from_the_same_sources(): void
    {
        $old = 'https://maker.example/family';
        $selected = 'https://shop.example/product';
        $draft = $this->seedDraft(['specifications' => [
            ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'source_url' => $old, 'source_excerpt' => 'Base RAM: 16 GB'],
            ['key' => 'storage', 'name' => 'Storage', 'value' => '512 GB', 'source_url' => $old, 'source_excerpt' => 'Base storage: 512 GB'],
        ]]);
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake(function () use ($draft, $old, $selected, &$calls): array {
            $calls++;
            // Stand in only for the model/tool exchange. The persisted evidence,
            // response validation and assembly are real application code.
            $this->seedEvidence($draft, $selected, 'Installed RAM: 32 GB. Installed storage: 1 TB.');

            return $this->fakeReconciled([
                ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB', 'evidence' => 'corrected',
                    'reason' => 'The former quote describes the base option, this offer specifies the installed RAM.',
                    'source_url' => $selected, 'source_excerpt' => 'Installed RAM: 32 GB',
                    'considered_source_urls' => [$old, $selected]],
                ['key' => 'storage', 'name' => 'Storage', 'value' => '1 TB', 'evidence' => 'corrected',
                    'reason' => 'The former quote describes the base option, this offer specifies installed storage.',
                    'source_url' => $selected, 'source_excerpt' => 'Installed storage: 1 TB',
                    'considered_source_urls' => [$old, $selected]],
            ]);
        })->preventStrayPrompts();

        $result = app(ProductCardAssembly::class)->complete($draft, ['url' => $selected], null);
        $this->assertTrue($result->isReconciled(), $result->summary ?? '');
        $this->assertSame(1, $calls);
        $this->assertSame(['32 GB', '1 TB'], array_column($result->specifications, 'value'));
        $this->assertSame([$old, $selected], $result->specifications[0]['considered_source_urls']);
        $this->assertSame([$old, $selected], $result->specifications[1]['considered_source_urls']);
    }

    /** @param array<string, mixed> $overrides */
    private function fakeRepair(string $prompt, array $changes, string $summary = 'Исправлено.'): array
    {
        $payload = json_decode($prompt, true);

        return [
            'base_hash' => $payload['repair']['base_hash'],
            'decision' => 'patch',
            'changes' => array_map(fn (string $path): array => [
                'path' => $path,
                'value_json' => json_encode($changes[$path], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ], array_keys($changes)),
            'unresolved_fields' => [],
            'summary' => $summary,
        ];
    }

    private function seedDraft(array $overrides = []): ProductDraft
    {
        $updateId = $this->telegramUpdateId();
        $run = AiRun::query()->create([
            'telegram_update_id' => $updateId,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'completed',
            'prompt' => 'test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return ProductDraft::query()->create([...[
            'telegram_update_id' => $updateId,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Test Product',
            'model' => 'Test Product',
            'description' => 'A test product description.',
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
        ], ...$overrides]);
    }

    private function seedEvidence(ProductDraft $draft, string $url, string $text): void
    {
        ProductSourcePageEvidence::query()->create([
            'product_draft_id' => $draft->id,
            'url_hash' => hash('sha256', ProductImageStorage::normalizeCandidateUrl($url)),
            'url' => $url,
            'specification_text' => $text,
            'captured_via' => 'http',
        ]);
    }

    /** @param array<int, array<string, mixed>> $specifications */
    private function fakeReconciled(
        array $specifications = [],
        string $title = 'Test Product',
        string $model = 'Test Product',
        ?string $color = null,
        string $description = 'A test product description.',
        string $summary = 'Подтверждено.',
    ): array {
        return [
            'overall_status' => 'reconciled',
            'summary' => $summary,
            'title' => $title,
            'model' => $model,
            'color' => $color,
            'description' => $description,
            'specifications' => $specifications,
        ];
    }

    public function test_it_treats_an_already_reconciled_source_as_free_and_never_calls_the_agent(): void
    {
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->fakeReconciled())
            ->preventStrayPrompts();

        $draft = new ProductDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
            'specifications_reconciled_source_url' => 'https://shop.example/product',
        ]);

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], null);

        $this->assertSame(SpecificationReconciliationStatus::AlreadyReconciled, $outcome->status);
        $this->assertTrue($outcome->isReconciled());
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_it_still_consults_the_agent_when_no_page_text_was_ever_captured(): void
    {
        // Point 2 of the follow-up audit: no ProductSourcePageEvidence row
        // must not itself block the agent - the agent gets an empty
        // source_specification_text and its own FetchProductSourcePageText
        // tool (scoped to this same URL's host) is exactly the mechanism for
        // bootstrapping evidence from nothing, including for sources a plain
        // re-fetch by this class itself could never usefully retry anyway.
        ProductSpecificationReconciliationAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertSame('', $payload['source_specification_text']);

            return $this->fakeReconciled(specifications: [
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'dropped', 'reason' => null],
            ]);
        })->preventStrayPrompts();

        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $baseline = AiRun::query()->count();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/never-crawled'], $this->telegramUpdateId());

        $this->assertSame(SpecificationReconciliationStatus::Reconciled, $outcome->status);
        $this->assertTrue($outcome->isReconciled());
        $this->assertSame($baseline + 1, AiRun::query()->count());
    }

    public function test_disabled_does_not_bypass_the_publish_block(): void
    {
        // "An unreconciled draft is not ready for publication" was agreed
        // without a carve-out for the checker being turned off - Disabled
        // gets its own status only so a caller could tell it apart from
        // other failures, it must not itself grant isReconciled().
        app(AiSettings::class)->saveSpecificationReconciliationEnabled(false);
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->fakeReconciled())
            ->preventStrayPrompts();

        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB, GPU: RTX 4050');
        $baseline = AiRun::query()->count();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], null);

        $this->assertSame(SpecificationReconciliationStatus::Disabled, $outcome->status);
        $this->assertFalse($outcome->isReconciled());
        $this->assertSame($baseline, AiRun::query()->count());
    }

    public function test_the_whole_card_is_still_reconciled_even_with_no_specifications_at_all(): void
    {
        // Point 2 of the agreed scope: the whole card (title/model/color/
        // description), not only specifications - a draft with none of the
        // latter must still go through a real check, not a free pass.
        ProductSpecificationReconciliationAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertSame([], $payload['current_specifications']);

            return $this->fakeReconciled(
                title: 'Corrected Title',
                model: 'Corrected Model',
                description: 'Corrected description matching this page.',
            );
        })->preventStrayPrompts();

        $draft = $this->seedDraft(['specifications' => []]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'This is the corrected model.');

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], null);

        $this->assertSame(SpecificationReconciliationStatus::Reconciled, $outcome->status);
        $this->assertTrue($outcome->isReconciled());
        $this->assertSame('Corrected Title', $outcome->title);
        $this->assertSame('Corrected Model', $outcome->model);
        $this->assertSame('Corrected description matching this page.', $outcome->description);
        $this->assertSame([], $outcome->specifications);
    }

    public function test_a_mixed_verdict_corrects_the_whole_card_and_drops_the_unaddressed_field(): void
    {
        ProductSpecificationReconciliationAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertSame('https://shop.example/product', $payload['source_url']);
            $this->assertStringContainsString('RAM: 32 GB', $payload['source_specification_text']);

            return $this->fakeReconciled(
                title: 'Test Laptop 32GB',
                model: 'Test Laptop',
                description: 'Now describing the 32 GB configuration.',
                specifications: [
                    // corrected: source says 32 GB, draft said 16 GB
                    ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB', 'evidence' => 'corrected', 'reason' => 'Источник указывает 32 ГБ.',
                        'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 32 GB'],
                    // dropped - the fake tries to sneak a changed value through anyway
                    ['key' => 'gpu', 'name' => 'GPU', 'value' => 'RTX 4090', 'evidence' => 'dropped', 'reason' => null],
                    // confirmed, value unchanged
                    ['key' => 'storage', 'name' => 'Storage', 'value' => '512 GB SSD', 'evidence' => 'confirmed', 'reason' => null,
                        'source_url' => 'https://shop.example/product', 'source_excerpt' => 'Storage: 512 GB SSD'],
                ],
            );
        })->preventStrayPrompts();

        $draft = $this->seedDraft([
            'title' => 'Test Laptop',
            'model' => 'Test Laptop',
            'description' => 'The 16 GB configuration.',
            'specifications' => [
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB'],
                ['key' => 'gpu', 'name' => 'GPU', 'value' => 'RTX 4050'],
                ['key' => 'storage', 'name' => 'Storage', 'value' => '512 GB SSD'],
            ],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 32 GB, Storage: 512 GB SSD');

        $outcome = app(ProductSpecificationReconciler::class)->reconcile(
            $draft,
            ['url' => 'https://shop.example/product'],
            $this->telegramUpdateId(),
        );

        $this->assertSame(SpecificationReconciliationStatus::Reconciled, $outcome->status);
        $this->assertTrue($outcome->isReconciled());
        $this->assertSame('https://shop.example/product', $outcome->reconciledSourceUrl);
        $this->assertSame('Test Laptop 32GB', $outcome->title);
        $this->assertSame('Now describing the 32 GB configuration.', $outcome->description);
        $bySpecKey = collect($outcome->specifications)->keyBy('key');
        $this->assertSame('32 GB', $bySpecKey['ram']['value']);
        $this->assertSame('512 GB SSD', $bySpecKey['storage']['value']);
        // Dropped, not kept at its old, now-unverified value.
        $this->assertFalse($bySpecKey->has('gpu'));
        $this->assertSame('completed', AiRun::query()->latest('id')->first()->status);
    }

    public function test_a_not_ready_verdict_applies_nothing_to_the_card(): void
    {
        ProductSpecificationReconciliationAgent::fake(fn (): array => [
            'overall_status' => 'not_ready',
            'summary' => 'Страница описывает другой товар.',
            'title' => 'Should not be applied',
            'model' => 'Should not be applied',
            'color' => null,
            'description' => 'Should not be applied',
            'specifications' => [
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'dropped', 'reason' => null],
            ],
        ])->preventStrayPrompts();

        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/wrong-product', 'A completely unrelated blender.');

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/wrong-product'], $this->telegramUpdateId());

        $this->assertSame(SpecificationReconciliationStatus::NotReady, $outcome->status);
        $this->assertFalse($outcome->isReconciled());
        $this->assertNull($outcome->specifications);
        $this->assertNull($outcome->title);
        $this->assertSame('Страница описывает другой товар.', $outcome->summary);
    }

    public function test_a_key_set_mismatch_degrades_to_unavailable_and_records_a_failed_ai_run(): void
    {
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->fakeReconciled(
            // Missing the "ram" key entirely - the agent must echo back
            // every key it was given.
            specifications: [],
        ))->preventStrayPrompts();

        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB');

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertSame(SpecificationReconciliationStatus::Unavailable, $outcome->status);
        $this->assertSame('invalid_response', $outcome->unavailableReason);
        $this->assertSame(['Missing input keys: ram'], $outcome->repairContext['validation_errors']['specifications']);
        $this->assertSame('failed', AiRun::query()->latest('id')->first()->status);
    }

    public function test_spent_money_still_assembles_the_card(): void
    {
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->fakeReconciled())
            ->preventStrayPrompts();
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('exceeded')->andReturn(true);
        $draft = $this->seedDraft();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled());
    }

    public function test_no_time_left_in_the_job_returns_unavailable_without_calling_the_agent(): void
    {
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->fakeReconciled())
            ->preventStrayPrompts();
        $this->mock(ProductSearchTimeBudget::class)->shouldReceive('assemblyCanStart')->andReturn(false);

        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB');
        $baseline = AiRun::query()->count();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertSame(SpecificationReconciliationStatus::Unavailable, $outcome->status);
        $this->assertTrue($outcome->isBudgetExhausted());
        $this->assertSame($baseline, AiRun::query()->count());
    }

    public function test_new_evidence_written_during_a_not_ready_attempt_is_reported_as_progress(): void
    {
        // Point 2 of the follow-up audit: new evidence the tool found this
        // attempt is progress even when the card overall is still not
        // ready - the caller's attempt counter must be able to tell this
        // apart from a repeat of the exact same failed work.
        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB');

        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($draft): array {
            // Stand-in for the agent's own FetchProductSourcePageText tool
            // having found and persisted something new during this call -
            // Agent::fake() does not execute real tools, so the effect a
            // real tool call would have (a new evidence row) is applied
            // directly here, at the same point in the exchange.
            ProductSourcePageEvidence::query()->create([
                'product_draft_id' => $draft->id,
                'url_hash' => hash('sha256', ProductImageStorage::normalizeCandidateUrl('https://shop.example/specs-tab')),
                'url' => 'https://shop.example/specs-tab',
                'specification_text' => 'GPU: RTX 4060',
                'captured_via' => 'browser',
            ]);

            return [
                'overall_status' => 'not_ready',
                'summary' => 'Требуемая характеристика пока не подтверждена.',
                'title' => 'Test Product', 'model' => 'Test Product', 'color' => null,
                'description' => 'A test product description.',
                'specifications' => [
                    ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed', 'reason' => null],
                ],
            ];
        })->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertSame(SpecificationReconciliationStatus::NotReady, $outcome->status);
        $this->assertTrue($outcome->madeProgress);
    }

    public function test_a_not_ready_attempt_with_no_new_evidence_reports_no_progress(): void
    {
        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB');

        ProductSpecificationReconciliationAgent::fake(fn (): array => [
            'overall_status' => 'not_ready',
            'summary' => 'Требуемая характеристика пока не подтверждена.',
            'title' => 'Test Product', 'model' => 'Test Product', 'color' => null,
            'description' => 'A test product description.',
            'specifications' => [
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed', 'reason' => null],
            ],
        ])->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertSame(SpecificationReconciliationStatus::NotReady, $outcome->status);
        $this->assertFalse($outcome->madeProgress);
    }

    public function test_touching_a_rows_timestamp_without_changing_its_content_is_not_progress(): void
    {
        // Regression for the exact gap found: progress used to be "row
        // count or max(updated_at) changed" - a write that bumps only the
        // housekeeping timestamp on otherwise identical content is not new
        // information and must not reset the attempt counter's cap.
        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB');

        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($draft): array {
            ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->first()->touch();

            return [
                'overall_status' => 'not_ready',
                'summary' => 'Требуемая характеристика пока не подтверждена.',
                'title' => 'Test Product', 'model' => 'Test Product', 'color' => null,
                'description' => 'A test product description.',
                'specifications' => [
                    ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed', 'reason' => null],
                ],
            ];
        })->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertFalse($outcome->madeProgress);
    }

    public function test_progress_is_noticed_even_when_an_existing_rows_content_changes_within_the_same_timestamp(): void
    {
        // The other direction of the same gap: two real, different writes
        // landing in the same second (a realistic case - a fast tool call
        // updating a row that was created moments earlier) must not be
        // invisible to a signature built from timestamps.
        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $this->seedEvidence($draft, 'https://shop.example/product', 'RAM: 16 GB');
        $evidence = ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->first();
        $frozenAt = $evidence->updated_at;

        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($draft, $frozenAt): array {
            $evidence = ProductSourcePageEvidence::query()->where('product_draft_id', $draft->id)->first();
            $evidence->specification_text = 'RAM: 16 GB, GPU: RTX 4060 (found mid-attempt)';
            $evidence->timestamps = false;
            $evidence->updated_at = $frozenAt;
            $evidence->save();

            return [
                'overall_status' => 'not_ready',
                'summary' => 'Требуемая характеристика пока не подтверждена.',
                'title' => 'Test Product', 'model' => 'Test Product', 'color' => null,
                'description' => 'A test product description.',
                'specifications' => [
                    ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed', 'reason' => null],
                ],
            ];
        })->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertSame($frozenAt->toDateTimeString(), $evidence->fresh()->updated_at->toDateTimeString());
        $this->assertTrue($outcome->madeProgress);
    }

    public function test_evidence_found_before_a_broken_agent_call_is_available_on_the_next_attempt(): void
    {
        // The exact scenario asked for: the tool got the specifications,
        // then the model call itself was interrupted. The next attempt must
        // see what was already found rather than paying to rediscover it.
        $draft = $this->seedDraft([
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
        ]);
        $otherPageUrl = 'https://shop.example/specs-tab';

        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($draft, $otherPageUrl): array {
            ProductSourcePageEvidence::query()->create([
                'product_draft_id' => $draft->id,
                'url_hash' => hash('sha256', ProductImageStorage::normalizeCandidateUrl($otherPageUrl)),
                'url' => $otherPageUrl,
                'specification_text' => 'GPU: RTX 4060; RAM: 16 GB',
                'captured_via' => 'browser',
                'open_selector' => '#specs',
            ]);

            throw new \RuntimeException('Simulated interruption after the tool already found something.');
        })->preventStrayPrompts();

        $firstOutcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertSame(SpecificationReconciliationStatus::Unavailable, $firstOutcome->status);
        $this->assertTrue($firstOutcome->madeProgress);

        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use ($otherPageUrl): array {
            $payload = json_decode($prompt, true);
            $this->assertSame(
                [$otherPageUrl],
                collect($payload['previously_fetched_pages'])->pluck('url')->all(),
            );
            $this->assertStringContainsString('RTX 4060', $payload['previously_fetched_pages'][0]['specification_text']);

            return $this->fakeReconciled(specifications: [
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed', 'reason' => null,
                    'source_url' => $otherPageUrl, 'source_excerpt' => 'RAM: 16 GB'],
            ]);
        })->preventStrayPrompts();

        $secondOutcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertTrue($secondOutcome->isReconciled());
    }
}
