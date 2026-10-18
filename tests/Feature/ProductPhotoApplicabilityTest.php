<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductPhotoApplicabilityAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductPhotoApplicability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductPhotoApplicabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductPhotoApplicabilityAgent::fake()->preventStrayPrompts();
    }

    public function test_full_model_without_ean_or_part_number_can_supply_exact_photos(): void
    {
        $draft = $this->draft();
        $source = $this->source('MSI Vector A16 HX A8WHG-018, Cosmos Gray');
        ProductPhotoApplicabilityAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertSame('photo_applicability', $payload['verification_stage']);
            $this->assertStringNotContainsString('9S7', json_encode($payload['source_evidence']));
            $this->assertSame('Vector A16 HX A8WHG-018', $payload['request_context']['selected_product']['model']);

            return $this->decision('exact');
        })->preventStrayPrompts();

        $result = app(ProductPhotoApplicability::class)->assess($draft, $source, $draft->telegram_update_id);

        $this->assertSame('exact', $result['verdict']);
        $this->assertTrue(ProductPhotoApplicability::isAccepted($draft, $source, $result));
        $this->assertSame('source_metadata_not_pixels', $result['assessment_scope']);
        $this->assertDatabaseHas('ai_runs', ['id' => $result['ai_run_id'], 'status' => 'completed']);
    }

    public function test_internal_frequency_and_regional_sku_difference_preserve_compatible_photo_source(): void
    {
        $draft = $this->draft();
        $source = $this->source('A8WHG-019, same Cosmos Gray chassis, same ports; CPU 1 GHz slower, US keyboard');
        $decision = $this->decision('compatible');
        $decision['source_model'] = 'Vector A16 HX A8WHG-019';
        $decision['differences'] = ['Частота процессора ниже на 1 ГГц; корпус не меняется.',
            'На фото американская клавиатура; у выбранного товара немецкая.'];
        ProductPhotoApplicabilityAgent::fake(fn (): array => $decision)->preventStrayPrompts();

        $before = $draft->fresh()->getAttributes();
        $result = app(ProductPhotoApplicability::class)->assess($draft, $source, $draft->telegram_update_id);

        $this->assertSame('compatible', $result['verdict']);
        $this->assertCount(2, $result['differences']);
        $this->assertTrue(ProductPhotoApplicability::isAccepted($draft, $source, $result));
        $this->assertSame($before, $draft->fresh()->getAttributes(), 'Photo applicability must not adopt the source offer.');
    }

    public function test_positive_exterior_conflict_cannot_be_overruled_by_an_accepting_verdict(): void
    {
        $draft = $this->draft();
        $decision = $this->decision('compatible');
        $decision['physical_checks']['construction'] = ['status' => 'conflicting', 'evidence' => 'Источник показывает трансформер, выбранный товар — обычный ноутбук.'];
        ProductPhotoApplicabilityAgent::fake(fn (): array => $decision)->preventStrayPrompts();

        $result = app(ProductPhotoApplicability::class)->assess($draft, $this->source('Convertible chassis'), $draft->telegram_update_id);

        $this->assertSame('conflicting', $result['verdict']);
        $this->assertFalse($result['may_inspect_gallery']);
    }

    public function test_unknown_color_keeps_candidate_inspectable_but_never_accepted(): void
    {
        $draft = $this->draft();
        $decision = $this->decision('compatible');
        $decision['physical_checks']['color'] = ['status' => 'unknown', 'evidence' => 'Цвет источника не указан.'];
        ProductPhotoApplicabilityAgent::fake(fn (): array => $decision)->preventStrayPrompts();
        $source = $this->source('A8WHG-019, same chassis, unspecified color');

        $result = app(ProductPhotoApplicability::class)->assess($draft, $source, $draft->telegram_update_id);

        $this->assertSame('uncertain', $result['verdict']);
        $this->assertTrue($result['may_inspect_gallery']);
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $source, $result));
    }

    public function test_family_resemblance_and_missing_body_evidence_are_not_compatibility_proof(): void
    {
        $draft = $this->draft();
        $decision = $this->decision('compatible');
        $decision['physical_checks']['ports'] = ['status' => 'match', 'evidence' => ''];
        $decision['physical_checks']['revision'] = ['status' => 'unknown', 'evidence' => 'Известно только семейство.'];
        ProductPhotoApplicabilityAgent::fake(fn (): array => $decision)->preventStrayPrompts();

        $result = app(ProductPhotoApplicability::class)->assess($draft, $this->source('Vector A16 HX family'), $draft->telegram_update_id);

        $this->assertSame('uncertain', $result['verdict']);
        $this->assertTrue($result['may_inspect_gallery']);
    }

    public function test_agent_cannot_claim_a_color_match_when_target_color_is_unknown(): void
    {
        $draft = $this->draft();
        $draft->color = null;
        ProductPhotoApplicabilityAgent::fake(fn (): array => $this->decision('compatible'))->preventStrayPrompts();

        $result = app(ProductPhotoApplicability::class)->assess($draft, $this->source('A8WHG-019, Cosmos Gray'), $draft->telegram_update_id);

        $this->assertSame('uncertain', $result['verdict']);
        $this->assertSame('unknown', $result['physical_checks']['color']['status']);
        $this->assertTrue($result['may_inspect_gallery']);
    }

    public function test_invalid_paid_response_retains_usage_without_becoming_an_accepted_result(): void
    {
        $draft = $this->draft();
        ProductPhotoApplicabilityAgent::fake(fn (): array => ['verdict' => 'compatible'])->preventStrayPrompts();

        $result = app(ProductPhotoApplicability::class)->assess($draft, $this->source('A8WHG-019'), $draft->telegram_update_id);
        $run = AiRun::findOrFail($result['ai_run_id']);

        $this->assertSame('unavailable', $result['verdict']);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->usage);
        $this->assertSame('compatible', $run->response['verdict']);
    }

    public function test_cancellation_during_provider_call_retains_its_cost_but_does_not_accept_output(): void
    {
        $draft = $this->draft();
        ProductPhotoApplicabilityAgent::fake(function () use ($draft): array {
            $draft->telegramUpdate()->update(['status' => 'cancelled']);

            return $this->decision('exact');
        })->preventStrayPrompts();

        try {
            app(ProductPhotoApplicability::class)->assess($draft, $this->source('A8WHG-018'), $draft->telegram_update_id);
            $this->fail('Cancellation must propagate.');
        } catch (ProductSearchCancelled) {
            $run = AiRun::latest('id')->firstOrFail();
            $this->assertSame('cancelled', $run->status);
            $this->assertNotNull($run->usage);
        }
    }

    public function test_provider_failure_is_not_product_rejection_or_cached_evidence(): void
    {
        $draft = $this->draft();
        $calls = 0;
        ProductPhotoApplicabilityAgent::fake(function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('Fixture provider unavailable');
            }

            return $this->decision('exact');
        })->preventStrayPrompts();
        $service = app(ProductPhotoApplicability::class);
        $source = $this->source('MSI Vector A16 HX A8WHG-018, Cosmos Gray');

        $failed = $service->assess($draft, $source, $draft->telegram_update_id);
        $retried = $service->assess($draft, $source, $draft->telegram_update_id);

        $this->assertSame('unavailable', $failed['verdict']);
        $this->assertFalse($failed['may_inspect_gallery']);
        $this->assertSame('exact', $retried['verdict']);
        $this->assertSame(2, $calls);
        $this->assertDatabaseHas('ai_runs', ['id' => $failed['ai_run_id'], 'status' => 'failed']);
    }

    public function test_identical_evidence_reuses_paid_result_but_changed_source_target_or_request_does_not(): void
    {
        $draft = $this->draft();
        $calls = 0;
        ProductPhotoApplicabilityAgent::fake(function () use (&$calls): array {
            $calls++;

            return $this->decision('exact');
        })->preventStrayPrompts();
        $service = app(ProductPhotoApplicability::class);
        $source = $this->source('MSI Vector A16 HX A8WHG-018, Cosmos Gray');
        $first = $service->assess($draft, $source, $draft->telegram_update_id);
        $cached = $service->assess($draft, [...$source, '_photo_applicability' => $first], $draft->telegram_update_id);
        $this->assertSame(1, $calls);
        $this->assertSame($first['ai_run_id'], $cached['ai_run_id']);
        $this->assertTrue($cached['cache_hit']);
        $this->assertNotNull(AiRun::find($first['ai_run_id'])->usage);

        $changedSource = [...$source, '_preflight_identity_evidence' => 'Updated real page evidence'];
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $changedSource, $first));
        $service->assess($draft, $changedSource, $draft->telegram_update_id);
        $this->assertSame(2, $calls);
        $draft->color = 'Black';
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $source, $first));
        $service->assess($draft, $source, $draft->telegram_update_id);
        $this->assertSame(3, $calls);
        $otherUpdate = $this->update(902);
        $service->assess($draft, $source, $otherUpdate->id);
        $this->assertSame(4, $calls);
    }

    public function test_budget_exhaustion_and_disabled_agent_make_no_paid_call(): void
    {
        $draft = $this->draft();
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('exceeded')->once()->andReturn(true);
        $result = app(ProductPhotoApplicability::class)->assess($draft, $this->source('A8WHG-018'), $draft->telegram_update_id);
        $this->assertSame('unavailable', $result['verdict']);
        AppSetting::put('ai.source_identity_agent_enabled', '0');
        $result = app(ProductPhotoApplicability::class)->assess($draft, $this->source('A8WHG-018'), $draft->telegram_update_id);
        $this->assertSame('unavailable', $result['verdict']);
        ProductPhotoApplicabilityAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_runs', 1); // Only the input research fixture.
    }

    public function test_duplicate_browser_evidence_preserves_binding_while_a_changed_observation_invalidates_it(): void
    {
        $draft = $this->draft();
        $source = $this->source('MSI Vector A16 HX A8WHG-018, Cosmos Gray');
        $assessment = ProductPhotoApplicability::bind($draft, $source, ['verdict' => 'exact']);
        $withObservation = [...$source, '_observed_identity' => ['source' => $source, 'verdict' => 'confirmed']];

        $this->assertSame(ProductPhotoApplicability::sourceFingerprint($source), ProductPhotoApplicability::sourceFingerprint($withObservation));
        $this->assertTrue(ProductPhotoApplicability::isAccepted($draft, $withObservation, $assessment));
        $newRuntime = [...$withObservation, '_preflight_identity_evidence' => 'Runtime changed to black convertible'];
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $newRuntime, $assessment));
        $clearedRuntime = [...$withObservation, 'title' => null, '_preflight_identity_evidence' => ''];
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $clearedRuntime, $assessment));
        $withObservation['_observed_identity']['source']['_preflight_identity_evidence'] = 'Actual page changed to a black convertible';
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $withObservation, $assessment));
    }

    public function test_text_repairs_keep_photo_proof_but_changed_model_color_or_body_invalidates_it(): void
    {
        $draft = $this->draft();
        $draft->specifications = [...$draft->specifications, ['key' => 'body_revision', 'value' => 'Revision 1']];
        $source = $this->source('MSI Vector A16 HX A8WHG-018, Cosmos Gray');
        $assessment = ProductPhotoApplicability::bind($draft, $source, ['verdict' => 'exact']);
        $draft->title = 'A richer product title';
        $draft->description = 'A corrected product description';
        $draft->specifications = [...$draft->specifications,
            ['key' => 'ram', 'value' => '64 GB'], ['key' => 'ram_frequency', 'value' => '6400 MHz'],
            ['key' => 'storage', 'value' => '2 TB'], ['key' => 'ean', 'value' => '4711377339612'],
            ['key' => 'usb_ports', 'value' => '3']];
        $this->assertTrue(ProductPhotoApplicability::isAccepted($draft, $source, $assessment));
        $draft->model = ' vector A16 HX A8WHG-018 ';
        $draft->color = 'COSMOS   GRAY';
        $this->assertTrue(ProductPhotoApplicability::matchesTarget($draft, $assessment));
        $draft->color = 'Black';
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $source, $assessment));
        $draft->color = 'Cosmos Gray';
        $draft->model = 'Vector A18 HX';
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $source, $assessment));
        $draft->model = 'Vector A16 HX A8WHG-018';
        $draft->specifications = collect($draft->specifications)->map(fn (array $fact): array => $fact['key'] === 'body_revision'
            ? [...$fact, 'value' => 'Revision 2'] : $fact)->all();
        $this->assertFalse(ProductPhotoApplicability::isAccepted($draft, $source, $assessment));
    }

    public function test_cancellation_is_not_swallowed_as_uncertainty_or_unavailability(): void
    {
        $draft = $this->draft();
        $draft->telegramUpdate()->update(['status' => 'cancelled']);
        $this->expectException(ProductSearchCancelled::class);
        app(ProductPhotoApplicability::class)->assess($draft, $this->source('A8WHG-018'), $draft->telegram_update_id);
    }

    public function test_elapsed_working_time_prevents_a_new_call(): void
    {
        $draft = $this->draft();
        $this->mock(ProductSearchTimeBudget::class)->shouldReceive('canStart')->once()->andReturn(false);
        $result = app(ProductPhotoApplicability::class)->assess($draft, $this->source('A8WHG-018'), $draft->telegram_update_id);
        $this->assertSame('unavailable', $result['verdict']);
        ProductPhotoApplicabilityAgent::assertNeverPrompted();
    }

    private function decision(string $verdict): array
    {
        $checks = [];
        foreach (['platform', 'revision', 'construction', 'dimensions', 'ports', 'color'] as $key) {
            $checks[$key] = ['status' => 'match', 'evidence' => 'Наблюдение спецификации корпуса: '.$key.' совпадает у выбранного товара и источника.'];
        }

        return ['verdict' => $verdict, 'reason' => 'Страница относится к подходящему корпусу.',
            'source_model' => 'Vector A16 HX A8WHG-018', 'differences' => [],
            'evidence' => ['Полная модель указана в H1, Cosmos Gray указан в карточке.'],
            'physical_checks' => $checks, 'plausible_target' => true, 'may_inspect_gallery' => true];
    }

    private function source(string $text): array
    {
        return ['url' => 'https://shop.example/vector', 'title' => $text, '_preflight_identity_evidence' => $text];
    }

    private function update(int $id = 901): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => $id, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'processing', 'text' => 'MSI Vector A16 HX A8WHG-018 Cosmos Gray']);
    }

    private function draft(): ProductDraft
    {
        $update = $this->update();
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'research fixture', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'MSI Vector A16 HX A8WHG-018', 'brand' => 'MSI',
            'model' => 'Vector A16 HX A8WHG-018', 'color' => 'Cosmos Gray', 'category' => 'laptops',
            'sources' => [], 'image_urls' => [], 'specifications' => [
                ['key' => 'mpn', 'value' => '9S7-15MM72-018'], ['key' => 'ean', 'value' => '4711377000000'],
                ['key' => 'cpu_frequency', 'value' => '5 GHz'], ['key' => 'keyboard_layout', 'value' => 'German'],
            ]]);
    }
}
