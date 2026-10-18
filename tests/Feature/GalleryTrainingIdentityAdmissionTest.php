<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourcePageRule;
use App\Models\TelegramUpdate;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryTrainingIdentityAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://shop.example/product/123';

    private const EXACT_REQUEST = 'ASUS GA403U, model GA403UV-NEBULA021W, P/N 90NR0I02-M00460';

    protected function setUp(): void
    {
        parent::setUp();

        ProductGalleryRecipeTrainerAgent::fake()->preventStrayPrompts();
        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'no_gallery', 'page_kind' => 'product_card',
            'gallery_likely' => false, 'hidden_images_likely' => false,
            'interaction_required' => false, 'expected_image_count' => 0,
            'evidence' => [], 'confidence' => 0.9, 'reason' => 'Fixture contains no gallery.',
        ])->preventStrayPrompts();
    }

    public function test_confirmed_model_and_explicit_part_number_allow_preflight(): void
    {
        $draft = $this->draft();
        $this->scout(['mpn' => '90NR0I02-M00460']);

        $this->train($draft);

        ProductSourceIdentityAgent::assertNeverPrompted();
        ProductGalleryPreflightAgent::assertPrompted(fn (): bool => true);
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id,
            'action' => 'validate_scout_product_identity', 'decision' => 'exact',
        ]);
        $this->assertDatabaseMissing('product_source_attempts', [
            'product_draft_id' => $draft->id, 'action' => 'admit_product_before_training',
        ]);
    }

    public function test_full_model_without_part_number_can_still_admit_the_gallery(): void
    {
        $draft = $this->draft();
        $this->scout();

        $this->train($draft);

        ProductSourceIdentityAgent::assertNeverPrompted();
        ProductGalleryPreflightAgent::assertPrompted(fn (): bool => true);
        $this->assertDatabaseMissing('product_source_attempts', [
            'product_draft_id' => $draft->id, 'action' => 'admit_product_before_training',
        ]);
    }

    public function test_unavailable_identity_check_interrupts_before_training_without_a_product_conflict(): void
    {
        $draft = $this->draft();
        $recipe = $this->existingRecipe();
        $before = $recipe->fresh()->getAttributes();
        $this->scout(title: 'ASUS ROG Zephyrus G14');
        $this->photoAssessment('unavailable');

        $this->assertSame([], $this->train($draft));

        ProductSourceIdentityAgent::assertNeverPrompted();
        $this->assertAdmissionDeferred($draft, $recipe, $before,
            'interrupted', 'source_identity_unavailable', 'defer_unavailable_identity_before_training');
    }

    public function test_broad_operator_request_can_still_observe_gallery_when_researched_codes_are_unconfirmed(): void
    {
        $draft = $this->draft('ASUS ROG Zephyrus G14');
        $this->scout(title: 'ASUS ROG Zephyrus G14');
        $this->photoAssessment('uncertain', inspect: true);

        $this->train($draft);

        ProductSourceIdentityAgent::assertNeverPrompted();
        ProductGalleryPreflightAgent::assertPrompted(fn (): bool => true);
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
        $this->assertDatabaseMissing('product_source_attempts', [
            'product_draft_id' => $draft->id, 'action' => 'admit_product_before_training',
        ]);
    }

    public function test_deferred_identity_admission_does_not_hide_earlier_interrupted_training_progress(): void
    {
        $draft = $this->draft();
        $recipe = $this->existingRecipe();
        $before = $recipe->fresh()->getAttributes();
        $priorPlan = ['collect_selectors' => ['.gallery img'], 'thumbnail_selectors' => ['.gallery button']];
        $priorAttempts = [[
            'attempt' => 1, 'selectors_tried' => $priorPlan, 'candidate_count' => 2,
            'error' => 'The third thumbnail did not open before the browser closed.',
        ]];
        $olderVersion = $recipe->versions()->create([
            'domain' => 'shop.example', 'product_url' => self::PAGE, 'trigger' => 'automatic',
            'status' => 'interrupted', 'provider' => 'fake', 'model' => 'fake',
            'result' => ['failure_kind' => 'browser_unavailable', 'attempts' => $priorAttempts],
            'error' => 'Browser closed during the third thumbnail.',
        ]);
        $this->scout(title: 'ASUS ROG Zephyrus G14');
        $this->photoAssessment('uncertain');

        $this->assertSame([], $this->train($draft));

        $this->assertAdmissionDeferred($draft, $recipe, $before,
            'deferred', 'source_identity_unconfirmed', 'defer_unconfirmed_product_before_training');
        $latestVersion = $recipe->versions()->latest('id')->firstOrFail();
        $this->assertGreaterThan($olderVersion->id, $latestVersion->id);
        $this->assertArrayNotHasKey('attempts', $latestVersion->result);
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $progress = (new \ReflectionMethod($trainer, 'interruptedTrainingProgress'))->invoke($trainer, $recipe);
        $this->assertSame($priorAttempts, $progress['attempts']);
        $this->assertSame($priorAttempts, $progress['size_history']);
        $this->assertSame($priorPlan, $progress['feedback']['rejected_recipe']);
        $this->assertSame(2, $progress['feedback']['candidate_count']);
        $this->assertStringContainsString($olderVersion->error, $progress['feedback']['error']);
    }

    public function test_compatible_different_sku_reaches_preflight_and_preserves_photo_verdict(): void
    {
        $draft = $this->draft();
        $this->scout(title: 'ASUS ROG Zephyrus G14 GA403UV-OTHER-REGION');
        $this->photoAssessment('compatible', inspect: true);

        $this->train($draft);

        ProductGalleryPreflightAgent::assertPrompted(fn (): bool => true);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id, 'action' => 'validate_scout_product_identity', 'decision' => 'compatible',
        ]);
        $attempt = ProductSourceAttempt::query()->where('action', 'validate_scout_product_identity')->firstOrFail();
        $this->assertSame('photos', $attempt->output['purpose']);
        $this->assertSame(['Regional keyboard differs'], $attempt->output['_photo_applicability']['differences']);
    }

    public function test_incompatible_chassis_stops_before_gallery_training(): void
    {
        $draft = $this->draft();
        $this->scout(title: 'ASUS Different chassis OTHER-999');
        $this->photoAssessment('conflicting');

        $this->assertSame([], $this->train($draft));

        ProductGalleryPreflightAgent::assertNeverPrompted();
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id, 'decision' => 'reject_conflicting_product_before_training',
        ]);
    }

    private function photoAssessment(string $verdict, bool $inspect = false): void
    {
        $this->mock(ProductPhotoApplicability::class)->shouldReceive('assess')->once()->andReturn([
            'verdict' => $verdict, 'may_inspect_gallery' => $inspect, 'reason' => 'Fixture photo applicability.',
            'differences' => $verdict === 'compatible' ? ['Regional keyboard differs'] : [], 'evidence' => ['Fixture source evidence'],
        ]);
    }

    private function assertAdmissionDeferred(
        ProductDraft $draft,
        ProductGalleryRecipe $recipe,
        array $before,
        string $status,
        string $failureKind,
        string $decision,
    ): void {
        ProductGalleryPreflightAgent::assertNeverPrompted();
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
        $this->assertSame($before, $recipe->fresh()->getAttributes());
        $version = ProductGalleryRecipeVersion::query()->latest('id')->firstOrFail();
        $this->assertSame($status, $version->status);
        $this->assertSame($failureKind, $version->result['failure_kind']);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id, 'action' => 'admit_product_before_training',
            'status' => 'interrupted', 'decision' => $decision,
        ]);
        $this->assertDatabaseMissing('product_source_attempts', [
            'product_draft_id' => $draft->id, 'decision' => 'reject_conflicting_product_before_training',
        ]);
        $this->assertSame(0, ProductSourcePageRule::query()->count());
        $this->assertSame(0, ProductGalleryRecipe::query()->where('source_blocked', true)->count());
    }

    private function existingRecipe(): ProductGalleryRecipe
    {
        return ProductGalleryRecipe::create([
            'domain' => 'shop.example', 'path_pattern' => '*', 'status' => 'active',
            'recipe' => ['collect_selectors' => ['.gallery img']],
            'success_count' => 5, 'failure_count' => 2,
            'consecutive_hard_blocks' => 1, 'hard_block_urls' => ['https://shop.example/old'],
            'last_failure_kind' => 'recipe_mismatch', 'last_error' => 'Previous layout differed.',
        ]);
    }

    private function scout(array $identifiers = [], string $title = 'ASUS ROG Zephyrus G14 GA403UV-NEBULA021W'): void
    {
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('rememberSourceIdentityAssessment')->byDefault();
        $browser->shouldReceive('scout')->once()->andReturn([
            'product_page_url' => self::PAGE,
            'product_identity' => ['name' => $title, 'identifiers' => $identifiers],
            'scout' => ['final_url' => self::PAGE, 'title' => $title,
                'product_headings' => [$title], 'fragments' => ['<main>Product</main>']],
        ]);
        $browser->shouldNotReceive('executeRecipe');
    }

    private function train(ProductDraft $draft): array
    {
        return app(ProductGalleryRecipeTrainer::class)->train(self::PAGE, force: true,
            telegramUpdateId: $draft->telegram_update_id,
            context: [ProductRequestContext::CONTEXT_KEY => $draft->id]);
    }

    private function draft(string $request = self::EXACT_REQUEST): ProductDraft
    {
        $update = TelegramUpdate::create([
            'update_id' => 981237, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'processing', 'text' => $request,
        ]);
        $run = AiRun::create([
            'telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'research', 'started_at' => now(), 'completed_at' => now(),
        ]);

        return ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'ASUS ROG Zephyrus G14 GA403UV-NEBULA021W', 'model' => 'GA403UV-NEBULA021W',
            'brand' => 'ASUS', 'category' => 'laptops', 'sources' => [], 'image_urls' => [],
            'specifications' => [
                ['key' => 'sku', 'value' => 'GA403UV-NEBULA021W'],
                ['key' => 'mpn', 'value' => '90NR0I02-M00460'],
            ],
        ]);
    }
}
