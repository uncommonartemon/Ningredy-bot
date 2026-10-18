<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Agents\ProductSpecificationRepairAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CardRepairReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://shop.example/product';

    public function test_assembly_repairs_only_the_bad_url_and_audits_patch_and_merged_card(): void
    {
        [$draft, $candidate] = $this->fixture();
        ProductSpecificationReconciliationAgent::fake(fn (): array => $candidate)->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use ($candidate): array {
            $payload = json_decode($prompt, true);
            $this->assertSame(['specifications.ram.source_url'], $payload['repair']['allowed_paths']);
            $this->assertSame($candidate['specifications'][0], $payload['repair']['affected_candidate_facts']['ram']);
            $this->assertSame('16 GB', $payload['current_specifications'][0]['value']);
            $this->assertArrayNotHasKey('previous_candidate', $payload['previous_assembly_feedback']['repair_context']);

            return $this->response($payload, ['specifications.ram.source_url' => self::URL]);
        })->preventStrayPrompts();

        $outcome = app(ProductCardAssembly::class)->complete($draft, ['url' => self::URL], $draft->telegram_update_id);

        $this->assertTrue($outcome->isReconciled(), $outcome->summary ?? '');
        $this->assertSame($candidate['description'], $outcome->description);
        $this->assertSame(self::URL, $outcome->specifications[0]['source_url']);
        $this->assertSame('Baseline description.', $draft->fresh()->description);
        $this->assertSame(['https://shop.example/photo.jpg'], $draft->fresh()->image_urls);
        $run = AiRun::query()->latest('id')->first();
        $this->assertSame('completed', $run->status);
        $this->assertSame('patch', $run->response['decision']);
        $this->assertArrayNotHasKey('specifications', $run->response);
        $this->assertSame(self::URL, $run->activity['card_repair']['merged_candidate']['specifications'][0]['source_url']);
        $this->assertDatabaseCount('ai_runs', 3);
    }

    public function test_bad_patch_retains_exact_base_and_scope_for_the_next_attempt(): void
    {
        [$draft, $candidate] = $this->fixture();
        $source = $this->sourceWithFailure($candidate);
        $hashes = [];
        ProductSpecificationReconciliationAgent::fake([])->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use (&$hashes): array {
            $payload = json_decode($prompt, true);
            $hashes[] = $payload['repair']['base_hash'];

            return $this->response($payload, count($hashes) === 1
                ? ['specifications.ram.value' => '64 GB'] : ['specifications.ram.source_url' => self::URL]);
        })->preventStrayPrompts();
        $first = app(ProductSpecificationReconciler::class)->reconcile($draft, $source, $draft->telegram_update_id);
        $this->assertSame('patch_contract', $first->repairContext['failure_kind']);
        $this->assertSame($candidate, $first->repairContext['previous_candidate']);
        $source['_previous_assembly_feedback'] = ['reason' => $first->unavailableReason, 'repair_context' => $first->repairContext];
        $second = app(ProductSpecificationReconciler::class)->reconcile($draft->fresh(), $source, $draft->telegram_update_id);
        $this->assertTrue($second->isReconciled(), $second->summary ?? '');
        $this->assertSame($hashes[0], $hashes[1]);
        $this->assertSame('16 GB', $second->specifications[0]['value']);
        $this->assertSame('16 GB', $draft->fresh()->specifications[0]['value']);
    }

    public function test_closed_refusal_retains_invalid_base_without_treating_it_as_a_new_schema_failure(): void
    {
        [$draft, $candidate] = $this->fixture();
        ProductSpecificationReconciliationAgent::fake([])->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);

            return ['base_hash' => $payload['repair']['base_hash'], 'decision' => 'not_ready', 'changes' => [],
                'unresolved_fields' => [['key' => 'ram', 'status' => 'unknown', 'reason' => 'A valid citation is not available.']],
                'summary' => 'Cannot verify this citation with the retained evidence.'];
        })->preventStrayPrompts();

        $outcome = app(ProductSpecificationReconciler::class)->reconcile($draft, $this->sourceWithFailure($candidate), $draft->telegram_update_id);

        $this->assertSame(SpecificationReconciliationStatus::NotReady, $outcome->status);
        $this->assertFalse($outcome->isReconciled());
        $this->assertSame($candidate, $outcome->repairContext['previous_candidate']);
        $this->assertNotEmpty($outcome->repairContext['patch_repair']['paths']);
        $this->assertSame('completed', AiRun::query()->latest('id')->first()->status);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    #[DataProvider('baselineChanges')]
    public function test_concurrent_baseline_change_prevents_applying_the_patch(string $field): void
    {
        [$draft, $candidate] = $this->fixture();
        $calls = 0;
        ProductSpecificationReconciliationAgent::fake([])->preventStrayPrompts();
        ProductSpecificationRepairAgent::fake(function (string $prompt) use ($draft, $field, &$calls): array {
            $calls++;
            if ($field === 'request') {
                $draft->telegramUpdate()->update(['text' => 'A different requested configuration']);
            } else {
                $draft->update([$field => $field === 'status' ? 'rejected' : 'Different brand']);
            }

            return $this->response(json_decode($prompt, true), ['specifications.ram.source_url' => self::URL]);
        })->preventStrayPrompts();
        try {
            app(ProductCardAssembly::class)->complete($draft, $this->sourceWithFailure($candidate), $draft->telegram_update_id);
            $this->fail('An obsolete patch must cancel this assembly rather than buy another repair.');
        } catch (ProductSearchCancelled) {
            $this->assertSame(1, $calls);
        }
        $run = AiRun::query()->latest('id')->first();
        $this->assertSame('cancelled', $run->status);
        $this->assertStringContainsString('baseline changed', $run->activity['obsolete_repair']);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public static function baselineChanges(): array
    {
        return [['brand'], ['status'], ['request']];
    }

    private function response(array $payload, array $changes): array
    {
        return ['base_hash' => $payload['repair']['base_hash'], 'decision' => 'patch',
            'changes' => collect($changes)->map(fn ($value, $path): array => ['path' => $path, 'value_json' => json_encode($value)])->values()->all(),
            'unresolved_fields' => [], 'summary' => 'Corrected only the rejected field.'];
    }

    private function sourceWithFailure(array $candidate): array
    {
        return ['url' => self::URL, '_previous_assembly_feedback' => ['reason' => 'invalid_response',
            'repair_context' => ['previous_candidate' => $candidate, 'validation_errors' => [
                'specifications.0.source_url' => ['The source URL must have an HTTP scheme.'],
            ]]]];
    }

    private function fixture(): array
    {
        $update = TelegramUpdate::query()->create(['update_id' => random_int(1_000_000, 9_000_000),
            'telegram_user_id' => '12345', 'chat_id' => '12345', 'text' => 'Laptop with 16 GB RAM', 'payload' => [], 'status' => 'received']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'Test Laptop', 'model' => 'Test Laptop',
            'description' => 'Baseline description.', 'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
            'sources' => [], 'image_urls' => ['https://shop.example/photo.jpg']]);
        ProductSourcePageEvidence::query()->create(['product_draft_id' => $draft->id, 'url_hash' => hash('sha256', self::URL),
            'url' => self::URL, 'specification_text' => 'Installed RAM: 16 GB', 'captured_via' => 'http']);
        $candidate = ['overall_status' => 'reconciled', 'summary' => 'Verified', 'title' => 'Test Laptop',
            'model' => 'Test Laptop', 'color' => null, 'description' => 'An unchanged proposed description.',
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed',
                'source_url' => 'shop.example/product', 'source_excerpt' => 'Installed RAM: 16 GB']]];

        return [$draft, $candidate];
    }
}
