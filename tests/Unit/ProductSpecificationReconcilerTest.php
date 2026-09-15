<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use App\Services\Products\SpecificationReconciliationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSpecificationReconcilerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_assembly_does_not_start_recovery_when_shared_money_is_spent(): void
    {
        $draft = $this->seedDraft();
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturn(SpecificationReconciliationOutcome::unavailable('technical_error'));
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('exceeded')->once()->andReturnTrue();

        $outcome = app(ProductCardAssembly::class)
            ->complete($draft, ['url' => 'https://shop.example/product'], $draft->telegram_update_id);

        $this->assertTrue($outcome->isBudgetExhausted());
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

    /** @param array<string, mixed> $overrides */
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
                    ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB', 'evidence' => 'corrected', 'reason' => 'Источник указывает 32 ГБ.'],
                    // dropped - the fake tries to sneak a changed value through anyway
                    ['key' => 'gpu', 'name' => 'GPU', 'value' => 'RTX 4090', 'evidence' => 'dropped', 'reason' => null],
                    // confirmed, value unchanged
                    ['key' => 'storage', 'name' => 'Storage', 'value' => '512 GB SSD', 'evidence' => 'confirmed', 'reason' => null],
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
        $this->assertSame('technical_error', $outcome->unavailableReason);
        $this->assertSame('failed', AiRun::query()->latest('id')->first()->status);
    }

    public function test_budget_exhaustion_returns_unavailable_without_calling_the_agent(): void
    {
        ProductSpecificationReconciliationAgent::fake(fn (): array => $this->fakeReconciled())
            ->preventStrayPrompts();
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('exceeded')->andReturn(true);

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
                'specification_text' => 'GPU: RTX 4060',
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
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB', 'evidence' => 'confirmed', 'reason' => null],
            ]);
        })->preventStrayPrompts();

        $secondOutcome = app(ProductSpecificationReconciler::class)
            ->reconcile($draft, ['url' => 'https://shop.example/product'], $this->telegramUpdateId());

        $this->assertTrue($secondOutcome->isReconciled());
    }
}
