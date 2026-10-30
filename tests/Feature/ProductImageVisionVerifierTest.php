<?php

namespace Tests\Feature;

use App\Ai\Agents\GalleryFrameReviewAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Products\ProductPhotoApplicability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductImageVisionVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_gate_checks_all_45_frames_and_keeps_the_last_five_after_first_batch_rejection(): void
    {
        $batchSizes = [];
        GalleryFrameReviewAgent::fake(function (string $prompt, $attachments) use (&$batchSizes): array {
            $batchSizes[] = $attachments->count();
            $this->assertSame(count($batchSizes), json_decode($prompt, true)['batch']);

            return ['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => count($batchSizes) === 1 ? range(1, 40) : [], 'reason' => 'Checked'];
        })->preventStrayPrompts();
        $candidates = $this->languageFrames(45);
        $selected = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($candidates, new ProductDraft(['title' => 'Laptop']));

        $this->assertSame([40, 5], $batchSizes);
        $this->assertSame(array_slice($candidates, 40), $selected);
        $this->assertSame(2, AiRun::where('status', 'completed')->count());
    }

    public function test_language_gate_keeps_45_clean_frames_only_after_both_batches_were_checked(): void
    {
        $batchSizes = [];
        GalleryFrameReviewAgent::fake(function (string $prompt, $attachments) use (&$batchSizes): array {
            $batchSizes[] = $attachments->count();

            return ['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [], 'reason' => 'Clean'];
        })->preventStrayPrompts();
        $candidates = $this->languageFrames(45);
        $selected = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($candidates, new ProductDraft(['title' => 'Laptop']));

        $this->assertSame([40, 5], $batchSizes);
        $this->assertSame($candidates, $selected);
    }

    public function test_the_reviewers_cover_pick_leads_the_gallery_instead_of_a_promotional_first_frame(): void
    {
        GalleryFrameReviewAgent::fake(fn (): array => ['non_product_frames' => [], 'uncertain_content_frames' => [],
            'foreign_text_frames' => [], 'cover_frame' => 3, 'reason' => 'Frame 1 carries a sale badge; frame 3 is a clean shot.'])
            ->preventStrayPrompts();
        $candidates = $this->languageFrames(4);

        $selected = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($candidates, new ProductDraft(['title' => 'Laptop']));

        $this->assertSame([$candidates[2], $candidates[0], $candidates[1], $candidates[3]], $selected);
    }

    public function test_a_cover_pick_that_is_also_rejected_is_an_invalid_review_not_a_cover(): void
    {
        GalleryFrameReviewAgent::fake(fn (): array => ['non_product_frames' => [2], 'uncertain_content_frames' => [],
            'foreign_text_frames' => [], 'cover_frame' => 2, 'reason' => 'Contradictory.'])->preventStrayPrompts();

        $this->assertNull(app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(3), new ProductDraft(['title' => 'Laptop'])));
    }

    public function test_second_language_batch_failure_leaves_the_entire_gallery_unconfirmed(): void
    {
        $calls = 0;
        GalleryFrameReviewAgent::fake(function () use (&$calls): array {
            if (++$calls === 2) {
                throw new \RuntimeException('Second language batch unavailable');
            }

            return ['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [], 'reason' => 'Clean'];
        })->preventStrayPrompts();
        $selected = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(45), new ProductDraft);
        $this->assertNull($selected);
        $this->assertSame(2, $calls);
        $this->assertSame(1, AiRun::where('status', 'completed')->count());
        $this->assertDatabaseHas('ai_runs', ['status' => 'failed', 'error' => 'Second language batch unavailable']);
    }

    public function test_language_batches_use_current_run_and_stop_when_its_time_is_exhausted(): void
    {
        $update = $this->languageUpdate(801);
        $calls = 0;
        $this->mock(ProductSearchTimeBudget::class, function ($mock) use ($update): void {
            $mock->shouldReceive('canStart')->with($update->id, 15)->times(3)->andReturn(true, true, false);
            $mock->shouldReceive('timeoutFor')->with($update->id, \Mockery::any())->twice()->andReturn(30);
        });
        GalleryFrameReviewAgent::fake(function () use (&$calls): array {
            $calls++;

            return ['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [], 'reason' => 'Clean'];
        })->preventStrayPrompts();
        $selected = app(ProductImageVisionVerifier::class)->checkedGalleryFrames(
            $this->languageFrames(45), new ProductDraft(['telegram_update_id' => 9999]), $update->id,
        );

        $this->assertNull($selected);
        $this->assertSame(1, $calls);
        $this->assertSame([$update->id, $update->id], AiRun::orderBy('id')->pluck('telegram_update_id')->all());
        $this->assertSame('interrupted', AiRun::latest('id')->firstOrFail()->status);
    }

    public function test_cancellation_during_first_language_batch_prevents_a_second_batch(): void
    {
        $update = $this->languageUpdate(802);
        $calls = 0;
        GalleryFrameReviewAgent::fake(function () use ($update, &$calls): array {
            $calls++;
            $update->update(['cancel_requested_at' => now()]);

            return ['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [], 'reason' => 'Clean'];
        })->preventStrayPrompts();

        try {
            app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(45), new ProductDraft, $update->id);
            $this->fail('Cancellation must reach the caller.');
        } catch (ProductSearchCancelled) {
            $this->assertSame(1, $calls);
            $this->assertSame(1, AiRun::count());
            $this->assertSame('cancelled', AiRun::firstOrFail()->status);
            $this->assertNotNull(AiRun::firstOrFail()->usage);
        }
    }

    public function test_cancellation_while_waiting_for_the_language_provider_lock_prevents_the_prompt(): void
    {
        $update = $this->languageUpdate(803);
        GalleryFrameReviewAgent::fake()->preventStrayPrompts();
        $this->mock(OpenAiHeavyOperationGate::class)->shouldReceive('run')->once()->andReturnUsing(
            function ($provider, $timeout, $operation) use ($update) {
                $update->update(['status' => 'cancelled']);

                return $operation();
            },
        );

        try {
            app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(45), new ProductDraft, $update->id);
            $this->fail('Cancellation must reach the caller.');
        } catch (ProductSearchCancelled) {
            GalleryFrameReviewAgent::assertNeverPrompted();
            $this->assertSame('cancelled', AiRun::firstOrFail()->status);
        }
    }

    public static function invalidLanguageReplies(): array
    {
        return [
            'missing field' => [['reason' => 'Missing list']],
            'not an array' => [['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => 'none']],
            'zero index' => [['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [0]]],
            'out of batch' => [['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [2]]],
            'boolean index' => [['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [true]]],
            'negative index' => [['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [-1]]],
            'fractional index' => [['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [1.5]]],
            'not a list' => [['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [2 => 1]]],
        ];
    }

    #[DataProvider('invalidLanguageReplies')]
    public function test_malformed_language_reply_does_not_mean_all_frames_are_clean(array $reply): void
    {
        GalleryFrameReviewAgent::fake(fn (): array => $reply)->preventStrayPrompts();
        $selected = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(1), new ProductDraft);

        $this->assertNull($selected);
        $run = AiRun::firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Invalid foreign_text_frames', $run->error);
        $this->assertSame($reply, $run->response);
        $this->assertNotNull($run->usage);
    }

    private function languageFrames(int $count): array
    {
        $image = $this->jpeg();

        return array_map(fn (int $index): array => ['image' => $image, 'source_url' => 'https://cdn.example/frame-'.$index.'.jpg'], range(1, $count));
    }

    public static function logoPolicies(): array
    {
        return [
            'component' => ['component', 'components', true],
            'custom component category' => ['component', 'processors', true],
            'legacy component draft' => [null, 'components', true],
            'laptop' => ['laptop', 'laptops', false],
            'desktop' => ['computer', 'computers', false],
            'explicit kind wins over stale category' => ['laptop', 'components', false],
        ];
    }

    #[DataProvider('logoPolicies')]
    public function test_static_review_allows_logo_kind_only_for_components(?string $type, string $category, bool $allowed): void
    {
        ProductImageVisionAgent::fake(function (string $prompt) use ($allowed): array {
            $this->assertStringContainsString($allowed ? 'COMPONENT EXCEPTION' : 'NO LOGO EXCEPTION', $prompt);

            return ['images' => [[
                'index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true,
                'kind' => 'logo', 'view' => 'other', 'gallery_rank' => 1, 'score' => 90,
                'reason' => 'Source-supported matching brand/family logo.',
            ]]];
        })->preventStrayPrompts();
        $draft = new ProductDraft(['product_type' => $type, 'category' => $category]);
        $frames = $this->languageFrames(1);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $frames, 1);
        $this->assertCount($allowed ? 1 : 0, $selected);
    }

    public function test_a_rank_past_the_set_is_clamped_instead_of_failing_the_paid_review(): void
    {
        // Live #104, #166, #183: "gallery_rank must be between 1 and N" discarded the whole
        // review and bought it again; the rank only orders accepted frames.
        $calls = 0;
        ProductImageVisionAgent::fake(function () use (&$calls): array {
            $calls++;

            return ['images' => [
                ['index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true, 'kind' => 'product',
                    'view' => 'front', 'gallery_rank' => 1, 'score' => 95, 'reason' => 'Clean front shot.'],
                ['index' => 2, 'exact_match' => true, 'color_match' => true, 'publishable' => true, 'kind' => 'detail',
                    'view' => 'detail', 'gallery_rank' => 99, 'score' => 80, 'reason' => 'Ports close-up.'],
            ]];
        })->preventStrayPrompts();
        $draft = new ProductDraft(['product_type' => 'laptop', 'category' => 'laptops']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $this->languageFrames(2), 2);

        $this->assertCount(2, $selected);
        $this->assertSame(1, $calls);
    }

    #[DataProvider('logoPolicies')]
    public function test_gallery_review_receives_the_same_component_exception(?string $type, string $category, bool $allowed): void
    {
        GalleryFrameReviewAgent::fake(function (string $prompt) use ($allowed): array {
            $this->assertSame($allowed, json_decode($prompt, true)['allow_component_logos']);

            return ['foreign_text_frames' => [], 'non_product_frames' => $allowed ? [] : [1],
                'uncertain_content_frames' => [], 'reason' => 'Applied product type policy.'];
        })->preventStrayPrompts();
        $draft = new ProductDraft(['product_type' => $type, 'category' => $category]);
        $selected = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(1), $draft);

        $this->assertCount($allowed ? 1 : 0, $selected);
    }

    public function test_laptop_logo_is_not_a_product_even_when_its_text_is_allowed(): void
    {
        GalleryFrameReviewAgent::fake(fn () => [
            'foreign_text_frames' => [], 'non_product_frames' => [1], 'uncertain_content_frames' => [],
            'reason' => 'Only a manufacturer logo, no laptop.',
        ])->preventStrayPrompts();

        $this->assertSame([], app(ProductImageVisionVerifier::class)->checkedGalleryFrames(
            $this->languageFrames(1), new ProductDraft(['title' => 'Laptop', 'product_type' => 'laptop']),
        ));
        $this->assertSame(1, AiRun::count());
        $this->assertSame('check_gallery_frames', AiRun::firstOrFail()->activity['vision_operation']);
    }

    public function test_product_frames_keep_source_order_after_logo_removal_in_one_call(): void
    {
        GalleryFrameReviewAgent::fake(fn () => [
            'foreign_text_frames' => [], 'non_product_frames' => [1], 'uncertain_content_frames' => [],
            'reason' => 'Logo first; real box and product detail next.',
        ])->preventStrayPrompts();
        $frames = $this->languageFrames(3);

        $this->assertSame(array_slice($frames, 1), app(ProductImageVisionVerifier::class)->checkedGalleryFrames($frames, new ProductDraft));
        $this->assertSame(1, AiRun::count());
    }

    public function test_language_only_reply_cannot_approve_current_product_content(): void
    {
        GalleryFrameReviewAgent::fake(fn () => ['foreign_text_frames' => [], 'reason' => 'Only brand markings.'])->preventStrayPrompts();
        $this->assertNull(app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(1), new ProductDraft));
        $this->assertStringContainsString('Invalid non_product_frames', AiRun::firstOrFail()->error);
    }

    public function test_content_uncertainty_keeps_frames_pending_instead_of_approving_or_discarding(): void
    {
        GalleryFrameReviewAgent::fake(fn () => [
            'foreign_text_frames' => [], 'non_product_frames' => [], 'uncertain_content_frames' => [1],
            'reason' => 'Cannot distinguish a physical box from a flat graphic.',
        ])->preventStrayPrompts();
        $frames = $this->languageFrames(1);
        $this->assertNull(app(ProductImageVisionVerifier::class)->checkedGalleryFrames($frames, new ProductDraft));
        $this->assertGreaterThan(0, imagesx($frames[0]['image']));
    }

    public function test_conflicting_content_verdict_is_not_an_approval(): void
    {
        GalleryFrameReviewAgent::fake(fn () => [
            'foreign_text_frames' => [], 'non_product_frames' => [1], 'uncertain_content_frames' => [1], 'reason' => 'Contradiction',
        ])->preventStrayPrompts();
        $this->assertNull(app(ProductImageVisionVerifier::class)->checkedGalleryFrames($this->languageFrames(1), new ProductDraft));
        $this->assertSame('failed', AiRun::firstOrFail()->status);
    }

    public function test_digit_string_language_indices_and_duplicate_entries_are_unambiguous(): void
    {
        GalleryFrameReviewAgent::fake(fn (): array => [
            'non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => array_fill(0, 45, '1'), 'reason' => 'Same frame repeated in reply',
        ])->preventStrayPrompts();

        $this->assertSame([], app(ProductImageVisionVerifier::class)->checkedGalleryFrames(
            $this->languageFrames(1), new ProductDraft,
        ));
        $this->assertSame('completed', AiRun::firstOrFail()->status);
    }

    private function languageUpdate(int $updateId): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => $updateId, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'processing', 'text' => 'Find laptop']);
    }

    public function test_equal_ranks_do_not_discard_a_valid_batch(): void
    {
        $calls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$calls): array {
            $calls++;

            return ['images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true,
                'kind' => 'product', 'view' => 'front', 'gallery_rank' => 1, 'score' => 90, 'reason' => 'Good product photo.',
            ])->all()];
        })->preventStrayPrompts();
        $selected = app(ProductImageVisionVerifier::class)->select(new ProductDraft(['model' => 'Laptop']), [
            ['image' => $this->jpeg(), 'source_url' => 'https://cdn.example/one.jpg'],
            ['image' => $this->jpeg(), 'source_url' => 'https://cdn.example/two.jpg'],
        ], 2);
        $this->assertCount(2, $selected);
        $this->assertSame(1, $calls);
    }

    public function test_low_score_with_positive_verdict_is_repaired_not_silently_dropped_or_multiplied(): void
    {
        $calls = 0;
        ProductImageVisionAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;
            if ($calls === 2) {
                $this->assertStringContainsString('Conflicting Vision verdict for image 1', $prompt);
            }

            return ['images' => [[
                'index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true,
                'kind' => 'product', 'view' => 'front', 'gallery_rank' => 1,
                'score' => $calls === 1 ? 9 : 90, 'reason' => 'Good product photo.',
            ]]];
        })->preventStrayPrompts();
        $selected = app(ProductImageVisionVerifier::class)->select(new ProductDraft(['model' => 'Laptop']), [
            ['image' => $this->jpeg(), 'source_url' => 'https://cdn.example/one.jpg'],
        ], 1);
        $this->assertCount(1, $selected);
        $this->assertSame(2, $calls);
        $this->assertSame(90, $selected[0]['vision_score']);
        $this->assertDatabaseHas('ai_runs', ['status' => 'failed']);
    }

    public function test_a_shared_chassis_code_alone_does_not_grant_source_identity_confirmed(): void
    {
        // A candidate whose page only names the shared "LOQ 15IRX10" chassis
        // code - not the specific 83JE0013US sku - used to still earn
        // source_identity_confirmed here via the broad supportsSource()
        // check, independent of whatever ProductImageStorage's own (already
        // strict) gate had decided. With exact_match left false by Vision
        // itself, that flag is the only thing that could let this candidate
        // qualify - so it must not, on this evidence alone.
        $update = TelegramUpdate::query()->create([
            'update_id' => 460001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]);
        $draft = new ProductDraft([
            'telegram_update_id' => $update->id,
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'color' => 'Luna Grey',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', $update);

        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => false,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 90,
                'reason' => 'Same chassis, sku not confirmed by the model alone.',
            ])->all(),
        ])->preventStrayPrompts();

        $candidates = [[
            'image' => $this->jpeg(),
            'source_url' => 'https://shop.example/loq-15irx10-other-config',
            'page_source_context' => [
                'title' => 'Lenovo LOQ 15IRX10 Gaming Laptop - Other Configuration',
                'url' => 'https://shop.example/loq-15irx10-other-config',
            ],
        ]];

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $candidates, 4);

        $this->assertSame(
            [],
            $selected,
            'A shared chassis code alone must not stand in for source_identity_confirmed here - the candidate has no other way to qualify.',
        );
    }

    public function test_a_page_whose_text_names_the_line_lets_vision_skip_the_exact_suffix(): void
    {
        // Operator, 2026-10-29: memory and storage are invisible in a photograph.
        // Live #318: six shops named the Tecra A40-K line with nothing conflicting,
        // the scout could not prove the exact suffix, and Vision refused ~60 frames.
        $update = TelegramUpdate::query()->create([
            'update_id' => 460002, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Dynabook Tecra A40-K',
        ]);
        $draft = new ProductDraft([
            'telegram_update_id' => $update->id, 'title' => 'Dynabook Tecra A40-K', 'brand' => 'Dynabook',
            'model' => 'Tecra A40-K', 'color' => 'Mystic Blue',
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'A40-K-14M']],
        ]);
        $draft->setRelation('telegramUpdate', $update);
        $page = ['title' => 'Dynabook Tecra A40-K 14" Mystic Blue', 'url' => 'https://shop.example/tecra-a40-k'];
        $plausible = ProductPhotoApplicability::bind($draft, $page, [
            'verdict' => 'uncertain', 'plausible_target' => true, 'reason' => 'The page sells the Tecra A40-K line in Mystic Blue; the exact configuration is not stated.',
        ]);
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1, 'exact_match' => false, 'color_match' => true, 'publishable' => true,
                'kind' => 'product', 'view' => 'front', 'gallery_rank' => $index + 1, 'score' => 90,
                'reason' => 'The laptop is the subject; the suffix cannot be read from the picture.',
            ])->all(),
        ])->preventStrayPrompts();

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [[
            'image' => $this->jpeg(), 'source_url' => 'https://shop.example/tecra-front.jpg',
            'page_source_context' => [...$page, '_photo_applicability' => $plausible],
        ]], 4);

        $this->assertCount(1, $selected);

        // The same frame from a page whose verdict is "conflicting" still needs Vision's exact match.
        $conflicting = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'conflicting', 'plausible_target' => true, 'reason' => 'Another chassis.']);
        $this->assertSame([], app(ProductImageVisionVerifier::class)->select($draft, [[
            'image' => $this->jpeg(), 'source_url' => 'https://shop.example/tecra-front.jpg',
            'page_source_context' => [...$page, '_photo_applicability' => $conflicting],
        ]], 4));
    }

    public function test_vision_doubt_about_the_suffix_alone_does_not_refuse_a_frame_of_a_page_that_names_the_line(): void
    {
        // Live #362: pcnation sold the CB514-4HT line; Vision found four clean silver frames with
        // nothing visible against them and still set publishable=false, "applicability to -375Z
        // unconfirmed". The card ended with one photo. The doubt now arrives as data
        // (rejection_reason=identity) and the page's evidence decides it; every other refusal stands.
        $update = TelegramUpdate::query()->create(['update_id' => 460003, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Acer Chromebook Plus 514 CB514-4HT']);
        $draft = new ProductDraft(['telegram_update_id' => $update->id, 'title' => 'Acer Chromebook Plus 514 CB514-4HT-375Z',
            'brand' => 'Acer', 'model' => 'CB514-4HT-375Z', 'color' => 'Silver']);
        $draft->setRelation('telegramUpdate', $update);
        $page = ['title' => 'Acer Chromebook Plus 514 CB514-4HT-359X Silver', 'url' => 'https://shop.example/cb514-4ht'];
        $plausible = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'uncertain', 'plausible_target' => true,
            'reason' => 'The page sells another configuration of the CB514-4HT line; nothing conflicts.']);
        $review = fn (int $index, string $rejection, string $physical = 'not_observable', bool $color = true): array => [
            'index' => $index, 'exact_match' => false, 'color_match' => $color, 'physical_match' => $physical, 'publishable' => false,
            'rejection_reason' => $rejection, 'kind' => 'product', 'view' => 'angle', 'gallery_rank' => $index, 'score' => 88,
            'reason' => 'Clean frame of the CB514-4HT; the exact suffix is not confirmed by the picture.'];
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $review(1, 'identity'), // the doubt alone: the page decides, the frame stands
            $review(2, 'quality'), // a real quality refusal stays a refusal
            $review(3, 'identity', 'conflicting'), // a visible body conflict is never overruled
            $review(4, 'identity', 'not_observable', false), // nor a colour conflict
        ]])->preventStrayPrompts();
        $frame = fn (int $i): array => ['image' => $this->jpeg(), 'source_url' => 'https://shop.example/cb514-'.$i.'.jpg',
            'page_source_context' => [...$page, '_photo_applicability' => $plausible]];

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [$frame(1), $frame(2), $frame(3), $frame(4)], 4);

        $this->assertSame(['https://shop.example/cb514-1.jpg'], array_column($selected, 'source_url'));
        $this->assertStringStartsWith('Принят по странице-источнику', (string) $selected[0]['vision_reason']);

        // Without the page's word for the line, the same doubt still refuses the frame.
        $unsupported = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'uncertain', 'plausible_target' => false, 'reason' => 'Unclear page.']);
        $this->assertSame([], app(ProductImageVisionVerifier::class)->select($draft, [[...$frame(1),
            'page_source_context' => [...$page, '_photo_applicability' => $unsupported]]], 4));
    }

    public function test_repair_can_reject_the_image_and_does_not_force_a_positive_answer(): void
    {
        $calls = 0;
        ProductImageVisionAgent::fake(function () use (&$calls): array {
            return ['images' => [[
                'index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => ++$calls === 1,
                'kind' => 'product', 'view' => 'front', 'gallery_rank' => 1, 'score' => 9,
                'reason' => 'The resolution is genuinely poor.',
            ]]];
        })->preventStrayPrompts();
        $selected = app(ProductImageVisionVerifier::class)->select(new ProductDraft(['model' => 'Laptop']), [
            ['image' => $this->jpeg(), 'source_url' => 'https://cdn.example/one.jpg'],
        ], 1);
        $this->assertSame([], $selected);
        $this->assertSame(2, $calls);
    }

    public function test_repeated_contradictory_vision_is_interrupted_not_reported_as_zero_matching_photos(): void
    {
        $calls = 0;
        ProductImageVisionAgent::fake(function () use (&$calls): array {
            $calls++;

            return ['images' => [[
                'index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true,
                'kind' => 'uncertain', 'view' => 'front', 'gallery_rank' => 1, 'score' => 86,
                'reason' => 'Good product angle.',
            ]]];
        })->preventStrayPrompts();
        try {
            app(ProductImageVisionVerifier::class)->select(new ProductDraft(['model' => 'Laptop']), [
                ['image' => $this->jpeg(), 'source_url' => 'https://cdn.example/one.jpg'],
            ], 1);
            $this->fail('Contradictory classification must not become an empty completed review.');
        } catch (\UnexpectedValueException $error) {
            $this->assertStringContainsString('Conflicting Vision verdict', $error->getMessage());
            $this->assertSame(2, $calls);
            $this->assertSame(2, AiRun::where('status', 'failed')->count());
            $this->assertNotNull(AiRun::latest('id')->first()->response);
        }
    }

    public function test_the_exact_sku_still_grants_source_identity_confirmed(): void
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 461001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]);
        $draft = new ProductDraft([
            'telegram_update_id' => $update->id,
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'color' => 'Luna Grey',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', $update);

        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => false,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 90,
                'reason' => 'Exact sku confirmed by the page.',
            ])->all(),
        ])->preventStrayPrompts();

        $candidates = [[
            'image' => $this->jpeg(),
            'source_url' => 'https://shop.example/loq-15irx10-83je0013us',
            'page_source_context' => [
                'title' => 'Lenovo LOQ 15IRX10 83JE0013US',
                'url' => 'https://shop.example/loq-15irx10-83je0013us',
            ],
        ]];

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $candidates, 4);

        $this->assertCount(1, $selected, 'The exact sku on the page must still qualify the candidate.');
    }

    private function jpeg(): \GdImage
    {
        $image = imagecreatetruecolor(32, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        return $image;
    }
}
