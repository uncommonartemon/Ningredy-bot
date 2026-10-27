<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageVisionAgent;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\PhotoSourceNotice;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Products\ProductInputContext;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductPhotoKeyboardContext;
use App\Services\Products\ProductSourceIdentityGate;
use App\Services\Products\ProductSpecificationReconciler;
use App\Services\Products\SpecificationReconciliationOutcome;
use App\Services\ProductSearch\Recovery\CardOfferSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPhotoSearchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://93.184.216.34/products/laptop-foreign-sku';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        AppSetting::put('ai.fallback_sources_enabled', 'false');
        AppSetting::put('ai.card_assembly_fallback_model', null);
        config()->set('product-images.browser_fallback.enabled', false);
        Http::fake(function (Request $request) {
            if ($request->url() === self::PAGE) {
                return Http::response('<html><title>Laptop OTHER-16 16GB black</title></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
        });
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $i): array => [
                'index' => $i + 1, 'exact_match' => false, 'color_match' => true,
                'publishable' => true, 'kind' => 'product', 'view' => 'front',
                'gallery_rank' => $i + 1, 'score' => 98, 'reason' => 'Same chassis and black colour; internal RAM is invisible.',
            ])->all(),
        ])->preventStrayPrompts();
    }

    public function test_compatible_gallery_survives_a_ram_difference_and_keeps_its_provenance(): void
    {
        $draft = $this->draft();
        $this->mock(ProductPhotoApplicability::class)->shouldReceive('assess')->andReturnUsing(
            fn (ProductDraft $target, array $source): array => $this->compatible($target, $source),
        );
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(function (ProductDraft $target, array $source) {
                $this->assertSame('compatible', $source['_photo_applicability']['verdict']);
                $this->assertSame('32 GB', $target->specifications[1]['value']);

                return SpecificationReconciliationOutcome::alreadyReconciled(self::PAGE);
            });

        $this->assertSame(1, app(ProductImageStorage::class)->stage($draft));
        $fresh = $draft->fresh();
        $this->assertSame('TARGET-32', $fresh->model);
        $this->assertSame('32 GB', $fresh->specifications[1]['value']);
        $this->assertSame('compatible', $fresh->photo_source_context['_photo_applicability']['verdict']);
        $this->assertSame($fresh->photo_source_context, $fresh->media()->first()->photo_source_context);
        Storage::disk('public')->assertExists($fresh->media()->first()->path);
    }

    public function test_a_text_gap_does_not_restart_photo_discovery_for_a_saved_compatible_gallery(): void
    {
        $draft = $this->draft();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = $this->compatible($draft, $source);
        $draft->update(['primary_source_url' => self::PAGE, 'photo_source_context' => $source,
            'gallery_confirmed_sufficient' => true, 'gallery_status' => 'partial',
            'gallery_search_stop_reason' => 'specifications_unreconciled']);
        Storage::disk('public')->put('drafts/retained.webp', 'existing verified photo');
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/retained.webp',
            'source_url' => self::PAGE.'/image.jpg', 'checksum' => hash('sha256', 'retained'),
            'verification_status' => 'verified', 'photo_source_context' => $source]);
        AppSetting::put('ai.fallback_sources_enabled', 'true');
        $this->mock(ProductImageCandidateDiscovery::class)->shouldNotReceive('find');
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->twice()
            ->andReturn(SpecificationReconciliationOutcome::notReady('One frequency remains unknown.', repairContext: [
                'recovery_action' => 'retry_current_source', 'photo_result_preserved' => true,
                'unresolved_fields' => [['key' => 'cpu_frequency', 'status' => 'unknown']],
            ]));

        $this->assertSame(1, app(ProductImageStorage::class)->stage($draft));
        $this->assertSame(1, app(ProductImageStorage::class)->stage($draft->fresh()));
        $this->assertCount(1, $draft->fresh()->media);
        $this->assertNotEmpty($draft->fresh()->card_repair_state['last_failure']);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_draft_id' => $draft->id, 'action' => 'preserve_gallery_after_text_repair',
        ]);
        Http::assertNothingSent();
    }

    public function test_a_compatible_photo_decision_cannot_adopt_another_offer(): void
    {
        $draft = $this->draft();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = $this->compatible($draft, $source);
        $this->assertSame(['retain_selected_offer'], app(CardOfferSelection::class)->context($draft, $source)['available_actions']);
        $this->expectException(ValidationException::class);
        app(CardOfferSelection::class)->validate($draft, $source, [
            'offer_selection' => ['action' => 'adopt_source_offer', 'source_url' => self::PAGE],
        ], [], $draft->telegram_update_id);
    }

    public function test_assembly_cannot_silently_change_the_colour_covered_by_a_compatible_gallery(): void
    {
        $draft = $this->draft();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = $this->compatible($draft, $source);
        $this->expectException(ValidationException::class);
        app(CardOfferSelection::class)->validate($draft, $source, [
            'model' => $draft->model, 'color' => 'Silver',
            'specifications' => array_map(fn (array $fact): array => [...$fact, 'evidence' => 'confirmed'], $draft->specifications),
        ], [], $draft->telegram_update_id);
    }

    public function test_current_observed_conflict_overrides_a_cached_compatible_verdict(): void
    {
        $draft = $this->draft();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = $this->compatible($draft, $source);
        $source['_observed_identity'] = ['purpose' => 'photos', 'verdict' => 'conflicting',
            'product_draft_id' => $draft->id, 'telegram_update_id' => $draft->telegram_update_id,
            'source' => ['url' => self::PAGE, 'title' => $source['title']]];
        $this->assertSame('conflicting', app(ProductSourceIdentityGate::class)
            ->assessPhotos($draft, $source, $draft->telegram_update_id)['verdict']);
    }

    public function test_new_cited_physical_conflict_revokes_a_saved_gallery_without_deleting_files(): void
    {
        $draft = $this->draft();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = $this->compatible($draft, $source);
        $draft->update(['primary_source_url' => self::PAGE, 'photo_source_context' => $source,
            'gallery_confirmed_sufficient' => true, 'gallery_status' => 'partial']);
        Storage::disk('public')->put('drafts/retained.webp', 'existing photo');
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/retained.webp',
            'source_url' => self::PAGE.'/image.jpg', 'checksum' => hash('sha256', 'retained'),
            'verification_status' => 'verified', 'photo_source_context' => $source]);
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturn(SpecificationReconciliationOutcome::notReady('Different shell.', repairContext: [
                'recovery_action' => 'try_other_source', 'photo_result_preserved' => false,
                'photo_applicability_conflict' => ['physical_feature' => 'chassis', 'reason' => 'Different shell.',
                    'source_url' => self::PAGE, 'source_excerpt' => 'Revised chassis'],
            ]));

        app(ProductImageStorage::class)->stage($draft);
        $fresh = $draft->fresh();
        $this->assertSame('conflicting', $fresh->photo_source_context['_photo_applicability']['verdict']);
        $this->assertSame('rejected', $fresh->media()->first()->verification_status);
        $this->assertFalse($fresh->gallery_confirmed_sufficient);
        $this->assertSame([], $fresh->verifiedPhotoPaths());
        Storage::disk('public')->assertExists('drafts/retained.webp');
    }

    public function test_compatible_complete_gallery_still_checks_visible_colour_with_vision(): void
    {
        $draft = $this->draft();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = $this->compatible($draft, $source);
        $calls = 0;
        ProductImageVisionAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;
            $this->assertStringContainsString('compatible', $prompt);

            return ['images' => [['index' => 1, 'exact_match' => true, 'color_match' => false,
                'publishable' => false, 'kind' => 'product', 'view' => 'front', 'gallery_rank' => 1,
                'score' => 95, 'reason' => 'Photo shows silver, target is black.']]];
        })->preventStrayPrompts();
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/image.jpg',
            'confirmed_gallery' => true, 'source_identity_confirmed' => true, 'page_source_context' => $source]];
        $method = new \ReflectionMethod(ProductImageStorage::class, 'checkedGalleryFrames');
        $this->assertSame([], $method->invoke(app(ProductImageStorage::class), $frames, $draft,
            $draft->telegram_update_id, 1, null));
        $this->assertSame(1, $calls);
    }

    public function test_exact_page_does_not_allow_auction_phone_photos_through_the_gallery_gate(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [[
            'index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true,
            'catalog_photo' => false, 'kind' => 'product', 'view' => 'front', 'gallery_rank' => 1,
            'score' => 95, 'reason' => 'Same model, but an improvised auction phone photo on a kitchen table.',
        ]]])->preventStrayPrompts();
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/image.jpg',
            'confirmed_gallery' => true, 'source_identity_confirmed' => true]];
        $this->assertSame([], (new \ReflectionMethod(ProductImageStorage::class, 'checkedGalleryFrames'))
            ->invoke(app(ProductImageStorage::class), $frames, $draft, $draft->telegram_update_id, 1, null));
    }

    public static function permittedKeyboardTargets(): array
    {
        return ['label layout' => ['Italian', 'evidence', false],
            'requested product layout' => ['Czech', 'operator', true]];
    }

    #[DataProvider('permittedKeyboardTargets')]
    public function test_other_readable_keyboard_for_the_requested_product_keeps_a_factual_notice(string $targetLayout, string $origin, bool $operatorRequested): void
    {
        $draft = $this->draft();
        $draft->update(['specifications' => [...$draft->specifications,
            ['key' => 'keyboard_layout', 'name' => 'Keyboard layout', 'value' => $targetLayout]]]);
        if ($operatorRequested) {
            $request = 'Laptop TARGET-32 with a Czech keyboard. The Czech product layout is required.';
            $draft->telegramUpdate()->update(['text' => $request, 'payload' => [ProductInputContext::PAYLOAD_KEY => [
                'version' => 1, 'original_operator_request' => $request,
                'input_evidence' => [['key' => 'keyboard_layout', 'value' => 'Czech', 'source_kind' => 'user_text']],
            ]]]);
            $draft->refresh();
        }
        $note = $operatorRequested
            ? 'На фото американская клавиатура; у предлагаемого товара чешская.'
            : 'На фото американская клавиатура; у предлагаемого товара итальянская.';
        ProductImageVisionAgent::fake(function (string $prompt) use ($note, $targetLayout, $origin): array {
            $this->assertStringContainsString($targetLayout, $prompt);

            return ['images' => [['index' => 1, 'exact_match' => true, 'color_match' => true,
                'publishable' => true, 'catalog_photo' => true, 'kind' => 'product', 'view' => 'front',
                'gallery_rank' => 1, 'score' => 95, 'reason' => 'Same black body; readable US ANSI keyboard.',
                'keyboard_visibility' => 'readable', 'keyboard_layout_match' => 'different',
                'keyboard_requirement_source' => $origin, 'keyboard_requirement_scope' => 'product',
                'keyboard_layout' => 'US ANSI', 'keyboard_layout_note' => $note]]];
        })->preventStrayPrompts();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = ProductPhotoApplicability::bind($draft, $source, [
            'verdict' => 'exact', 'source_model' => $draft->model,
        ]);
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/image.jpg',
            'confirmed_gallery' => true, 'source_identity_confirmed' => true, 'page_source_context' => $source]];
        $result = (new \ReflectionMethod(ProductImageStorage::class, 'confirmedGalleryResult'))->invoke(
            app(ProductImageStorage::class), $frames, $draft, $draft->telegram_update_id, 1, 10, $source, 'playwright', 'Checked', null,
        );
        $this->assertTrue($result->isComplete());
        $this->assertSame([$note], PhotoSourceNotice::fromContext($result->source)['differences']);
        $this->assertStringContainsString($note, PhotoSourceNotice::russian($result->source));
    }

    public static function requiredPhotoKeyboardScopes(): array
    {
        return ['photos' => ['photos', true], 'photos text only' => ['photos', false],
            'misclassified product' => ['product', true], 'unknown' => ['unknown', true],
            'legacy missing' => [null, true]];
    }

    #[DataProvider('requiredPhotoKeyboardScopes')]
    public function test_explicit_photo_keyboard_requirement_rejects_visible_us_even_if_publishable(?string $reportedScope, bool $hasStructuredEvidence): void
    {
        $draft = $this->draft();
        $request = 'Laptop TARGET-32 black. Фотографии должны показывать чешскую клавиатуру; другая раскладка на фото не разрешена.';
        $draft->telegramUpdate()->update(['text' => $request, 'payload' => [ProductInputContext::PAYLOAD_KEY => [
            'version' => 1, 'original_operator_request' => $request,
            'input_evidence' => $hasStructuredEvidence ? [['key' => 'photo_keyboard_layout', 'value' => 'Czech', 'source_kind' => 'user_text',
                'source_excerpt' => 'Фотографии должны показывать чешскую клавиатуру.']] : [],
        ]]]);
        $draft->refresh();
        ProductImageVisionAgent::fake(function (string $prompt) use ($reportedScope): array {
            $this->assertStringContainsString('другая раскладка на фото не разрешена', $prompt);

            return ['images' => [[
                'index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true,
                'catalog_photo' => true, 'kind' => 'product', 'view' => 'front', 'gallery_rank' => 1,
                'score' => 99, 'reason' => 'Same laptop, but readable US ANSI legends.',
                'keyboard_visibility' => 'readable', 'keyboard_layout_match' => 'different',
                'keyboard_layout' => 'US ANSI',
                'keyboard_requirement_source' => 'operator',
                'keyboard_layout_note' => 'На фото американская клавиатура; запрошена чешская.',
                ...($reportedScope !== null ? ['keyboard_requirement_scope' => $reportedScope] : []),
            ]]];
        })->preventStrayPrompts();
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/image.jpg',
            'source_identity_confirmed' => true]];

        $this->assertSame([], app(ProductImageVisionVerifier::class)->reviewCompleteGallery($frames, $draft, $draft->telegram_update_id));
    }

    public function test_invisible_keyboard_is_not_reported_as_a_match_and_unreadable_visible_keyboard_is_not_published(): void
    {
        $draft = $this->draft();
        $draft->telegramUpdate()->update(['text' => 'Laptop TARGET-32 with a Czech keyboard, required.']);
        $draft->refresh();
        $draft->update(['specifications' => [['key' => 'keyboard_layout', 'name' => 'Keyboard layout', 'value' => 'Czech']]]);
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            ['index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true, 'kind' => 'product',
                'view' => 'back', 'gallery_rank' => 1, 'score' => 95, 'reason' => 'Closed lid; no keyboard.',
                'keyboard_visibility' => 'not_visible', 'keyboard_layout_match' => 'unknown'],
            ['index' => 2, 'exact_match' => true, 'color_match' => true, 'publishable' => true, 'kind' => 'product',
                'view' => 'front', 'gallery_rank' => 2, 'score' => 95, 'reason' => 'Keyboard visible, legends too small.',
                'keyboard_visibility' => 'visible_unreadable', 'keyboard_layout_match' => 'unknown'],
        ]])->preventStrayPrompts();
        $frames = array_map(fn (int $i): array => ['image' => imagecreatefromstring($this->jpeg()),
            'source_url' => self::PAGE.'/image-'.$i.'.jpg', 'source_identity_confirmed' => true], [1, 2]);
        $kept = app(ProductImageVisionVerifier::class)->reviewCompleteGallery($frames, $draft, $draft->telegram_update_id);
        $this->assertCount(1, $kept);
        $this->assertSame('not_visible', $kept[0]['photo_visual_review']['keyboard_visibility']);
        $this->assertSame('unknown', $kept[0]['photo_visual_review']['keyboard_layout_match']);
        $this->assertSame('', $kept[0]['photo_visual_review']['keyboard_layout_note']);
    }

    public function test_a_processor_sticker_keeps_the_frame_and_is_recorded_on_its_review(): void
    {
        // Live #214: seven renders were dropped over a Core i7 badge; the badge is now recorded, the frame kept.
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            ['index' => 1, 'exact_match' => true, 'color_match' => true, 'publishable' => true, 'kind' => 'product',
                'view' => 'front', 'gallery_rank' => 1, 'score' => 95, 'reason' => 'Front render; Core i7 sticker on the panel.',
                'keyboard_visibility' => 'not_applicable', 'keyboard_layout_match' => 'not_applicable',
                'configuration_marking' => ' Intel Core i7 '],
        ]])->preventStrayPrompts();
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/front.jpg', 'source_identity_confirmed' => true]];

        $kept = app(ProductImageVisionVerifier::class)->reviewCompleteGallery($frames, $draft, $draft->telegram_update_id);

        $this->assertCount(1, $kept);
        $this->assertSame('Intel Core i7', $kept[0]['photo_visual_review']['configuration_marking']);
    }

    public function test_exact_source_cannot_override_a_visible_body_conflict(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [[
            'index' => 1, 'exact_match' => false, 'color_match' => true, 'publishable' => true,
            'catalog_photo' => true, 'physical_match' => 'conflicting', 'kind' => 'product', 'view' => 'front',
            'gallery_rank' => 1, 'score' => 95, 'reason' => 'A different shell/port layout despite the exact product URL.',
        ]]])->preventStrayPrompts();
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/image.jpg',
            'confirmed_gallery' => true, 'source_identity_confirmed' => true]];
        $this->assertSame([], app(ProductImageVisionVerifier::class)->reviewCompleteGallery($frames, $draft, $draft->telegram_update_id));
    }

    public function test_complete_gallery_keeps_professional_feature_composition_without_an_aesthetic_score_gate(): void
    {
        $draft = $this->draft();
        $base = ['exact_match' => false, 'color_match' => true, 'publishable' => true, 'catalog_photo' => true,
            'physical_match' => 'match', 'kind' => 'product', 'view' => 'detail', 'score' => 99];
        $reviews = [
            [...$base, 'exact_match' => true, 'score' => 10,
                'reason' => 'Professional feature collage showing the actual product, with English graphics; aesthetically busy but clear.'],
            [...$base, 'exact_match' => true, 'kind' => 'banner', 'score' => 10,
                'reason' => 'Professional product feature banner with a prominent actual product and English text.'],
            [...$base, 'catalog_photo' => false, 'reason' => 'Auction phone photograph, not catalog quality.'],
            [...$base, 'physical_match' => 'conflicting', 'reason' => 'A different shell with incompatible ports.'],
            [...$base, 'color_match' => false, 'reason' => 'Silver product, requested black.'],
            [...$base, 'kind' => 'logo', 'reason' => 'Standalone logo without the product.'],
            [...$base, 'publishable' => false, 'reason' => 'Prominent Italian marketing text.'],
        ];
        $calls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use ($reviews, &$calls): array {
            $calls++;

            return ['images' => $attachments->keys()->map(fn (int $i): array => [
                ...$reviews[$i], 'index' => $i + 1, 'gallery_rank' => $i + 1,
            ])->all()];
        })->preventStrayPrompts();
        $frames = array_map(fn (int $i): array => ['image' => imagecreatefromstring($this->jpeg()),
            'source_url' => self::PAGE.'/frame-'.$i.'.jpg', 'confirmed_gallery' => true,
            'source_identity_confirmed' => true], range(0, 6));
        $verifier = app(ProductImageVisionVerifier::class);

        $kept = $verifier->reviewCompleteGallery($frames, $draft, $draft->telegram_update_id);

        $this->assertSame(1, $calls, 'A low aesthetic score must not cause a second full-gallery Vision call.');
        $this->assertCount(2, $kept);
        $this->assertSame($frames[0]['source_url'], $kept[0]['source_url']);
        $this->assertSame($frames[1]['source_url'], $kept[1]['source_url']);
        $this->assertSame(10, $kept[0]['vision_score']);
        // Separate, unconfirmed candidate selection keeps its ordinary score threshold.
        ProductImageVisionAgent::fake([['images' => [[
            ...$reviews[0], 'exact_match' => false, 'index' => 1, 'gallery_rank' => 1,
        ]]]])->preventStrayPrompts();
        $this->assertSame([], $verifier->select($draft, [$frames[0]], 1, $draft->telegram_update_id));
    }

    public function test_full_gallery_receives_high_detail_larger_pixels_without_changing_the_original(): void
    {
        $draft = $this->draft();
        config()->set('product-images.vision_detail', 'low');
        $original = imagecreate(2400, 1800);
        $color = imagecolorallocate($original, 30, 60, 90);
        imagefill($original, 0, 0, $color);
        $pixel = imagecolorat($original, 10, 10);
        $calls = 0;
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$calls): array {
            $attachment = $attachments->first();
            $size = getimagesizefromstring($attachment->content());
            $this->assertSame($calls === 0 ? [2048, 1536] : [640, 480], [$size[0], $size[1]]);
            $this->assertSame($calls === 0 ? 'high' : 'low', $attachment->providerOptions('openai')['detail']);
            $calls++;

            return ['images' => [['index' => 1, 'exact_match' => true, 'color_match' => true,
                'publishable' => true, 'kind' => 'product', 'view' => 'front', 'gallery_rank' => 1,
                'score' => 95, 'reason' => 'Same actual full product.']]];
        })->preventStrayPrompts();
        $frames = [['image' => $original, 'source_url' => self::PAGE.'/large.jpg', 'source_identity_confirmed' => true]];
        $verifier = app(ProductImageVisionVerifier::class);
        $this->assertCount(1, $verifier->reviewCompleteGallery($frames, $draft, $draft->telegram_update_id));
        $this->assertCount(1, $verifier->select($draft, $frames, 1, $draft->telegram_update_id));
        $this->assertSame([2400, 1800], [imagesx($original), imagesy($original)]);
        $this->assertSame($pixel, imagecolorat($original, 10, 10));
        $this->assertFalse(imageistruecolor($original));
        imagedestroy($original);
    }

    public static function unreadableKeyboardPolicies(): array
    {
        return ['product with notice' => ['product', true, 'match', true, '', true],
            'explicit photos requirement' => ['photos', true, 'match', true, '', false],
            'unknown scope' => ['unknown', true, 'match', true, '', false],
            'missing notice' => ['product', false, 'match', true, '', false],
            'wrong body' => ['product', true, 'conflicting', true, '', false],
            'wrong color' => ['product', true, 'match', false, '', false],
            'invented regional layout' => ['product', true, 'match', true, 'US ANSI', false]];
    }

    #[DataProvider('unreadableKeyboardPolicies')]
    public function test_unreadable_product_keyboard_retains_uncertainty_with_a_notice(string $scope, bool $hasNotice, string $body, bool $color, string $layout, bool $accepted): void
    {
        $draft = $this->draft();
        $note = 'Раскладка на фотографии неразборчива; у предлагаемого товара чешская клавиатура.';
        ProductImageVisionAgent::fake(fn (): array => ['images' => [[
            'index' => 1, 'exact_match' => true, 'color_match' => $color, 'physical_match' => $body,
            'publishable' => true, 'catalog_photo' => true, 'kind' => 'product', 'view' => 'front',
            'gallery_rank' => 1, 'score' => 90, 'reason' => 'Current pixels show the body, not readable key legends.',
            'keyboard_requirement_source' => 'operator', 'keyboard_requirement_scope' => $scope,
            'keyboard_visibility' => 'visible_unreadable', 'keyboard_layout_match' => 'unknown',
            'keyboard_layout' => $layout, 'keyboard_layout_note' => $hasNotice ? $note : '',
        ]]])->preventStrayPrompts();
        $source = $draft->sources[0];
        $source['_photo_applicability'] = ProductPhotoApplicability::bind($draft, $source, ['verdict' => 'exact', 'source_model' => $draft->model]);
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/keyboard.jpg',
            'source_identity_confirmed' => true, 'page_source_context' => $source]];
        $result = (new \ReflectionMethod(ProductImageStorage::class, 'confirmedGalleryResult'))->invoke(
            app(ProductImageStorage::class), $frames, $draft, $draft->telegram_update_id, 1, 10, $source, 'playwright', 'Checked', null,
        );
        $this->assertSame($accepted, $result->isComplete());
        $this->assertCount($accepted ? 1 : 0, $result->candidates);
        if ($accepted) {
            $this->assertSame('unknown', $result->candidates[0]['photo_visual_review']['keyboard_layout_match']);
            $this->assertSame('', $result->candidates[0]['photo_visual_review']['keyboard_layout']);
            $this->assertSame([$note], PhotoSourceNotice::fromContext($result->source)['differences']);
        }
    }

    public function test_interrupted_full_pixel_review_keeps_the_original_gallery_pending(): void
    {
        $draft = $this->draft();
        ProductImageVisionAgent::fake(fn () => throw new \RuntimeException('Vision temporarily unavailable'))
            ->preventStrayPrompts();
        $source = $draft->sources[0];
        $frames = [['image' => imagecreatefromstring($this->jpeg()), 'source_url' => self::PAGE.'/image.jpg',
            'confirmed_gallery' => true, 'source_identity_confirmed' => true, 'page_source_context' => $source]];
        $result = (new \ReflectionMethod(ProductImageStorage::class, 'confirmedGalleryResult'))->invoke(
            app(ProductImageStorage::class), $frames, $draft, $draft->telegram_update_id, 1, 10, $source, 'playwright', 'Checked', null,
        );
        $this->assertFalse($result->isComplete());
        $this->assertCount(1, $result->candidates);
        $this->assertSame('pending', $result->candidates[0]['verification_status']);
        $this->assertSame(720, imagesx($result->candidates[0]['image']));
        $this->assertSame($source, $result->source);
    }

    private function compatible(ProductDraft $draft, array $source): array
    {
        return ProductPhotoApplicability::bind($draft, $source, [
            'verdict' => 'compatible', 'reason' => 'Same chassis, different internal RAM.',
            'source_model' => 'OTHER-16', 'differences' => ['Source has 16 GB RAM; target has 32 GB.'],
            'evidence' => ['Matching platform, shell, ports and black finish.'], 'may_inspect_gallery' => true,
        ]);
    }

    public function test_initial_stage_attaches_a_late_product_keyboard_notice_without_an_extra_vision_call(): void
    {
        config()->set('product-images.max_images_by_type.laptop', 1);
        $draft = $this->draft();
        $calls = 0;
        $this->keyboardVision($calls);
        $this->mock(ProductPhotoApplicability::class)->shouldReceive('assess')->andReturnUsing(
            fn (ProductDraft $target, array $source): array => $this->compatible($target, $source),
        );
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(fn (ProductDraft $target) => $this->italianOutcome($target));

        $this->assertSame(1, app(ProductImageStorage::class)->stage($draft));
        $fresh = $draft->fresh();
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('не сверена', PhotoSourceNotice::russian($fresh->photo_source_context));
        $this->assertStringContainsString('Italian', PhotoSourceNotice::russian($fresh->photo_source_context));
        $this->assertSame($fresh->photo_source_context, $fresh->media()->first()->photo_source_context);
        $this->assertSame('not_applicable', $fresh->photo_source_context['_photo_visual_review']['frames'][0]['keyboard_layout_match']);
    }

    public static function savedKeyboardPaths(): array
    {
        return ['resume facts' => [false], 'resume pending pixels' => [true]];
    }

    #[DataProvider('savedKeyboardPaths')]
    public function test_late_keyboard_notice_is_saved_when_resuming_facts_or_pixels(bool $pending): void
    {
        config()->set('product-images.max_images_by_type.laptop', 1);
        $draft = $this->savedKeyboardDraft($pending);
        $calls = 0;
        $this->keyboardVision($calls);
        $this->mock(ProductImageCandidateDiscovery::class)->shouldNotReceive('find');
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(fn (ProductDraft $target) => $this->italianOutcome($target));
        $before = Storage::disk('public')->get('drafts/keyboard-front.webp');

        $this->assertSame(1, app(ProductImageStorage::class)->stage($draft));
        $fresh = $draft->fresh();
        $this->assertSame($pending ? 1 : 0, $calls);
        $this->assertStringContainsString('Italian', PhotoSourceNotice::russian($fresh->photo_source_context));
        $this->assertSame($fresh->photo_source_context, $fresh->media()->first()->photo_source_context);
        $this->assertSame($before, Storage::disk('public')->get('drafts/keyboard-front.webp'));
        Http::assertNothingSent();
    }

    public function test_strict_late_keyboard_requirement_preserves_side_views_and_resumes_only_local_pixels(): void
    {
        config()->set('product-images.max_images_by_type.laptop', 2);
        $draft = $this->savedKeyboardDraft(false, true);
        $this->mock(ProductImageCandidateDiscovery::class)->shouldNotReceive('find');
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->twice()
            ->andReturnUsing(fn (ProductDraft $target) => $this->italianOutcome($target));
        $this->assertSame(2, app(ProductImageStorage::class)->stage($draft));
        $fresh = $draft->fresh();
        $this->assertSame('pending', $fresh->media()->where('source_url', self::PAGE.'/front.jpg')->first()->verification_status);
        $this->assertSame('verified', $fresh->media()->where('source_url', self::PAGE.'/closed.jpg')->first()->verification_status);
        $this->assertSame('partial', $fresh->gallery_status);
        $calls = 0;
        $this->keyboardVision($calls, true);
        // The confirmed gallery already has a closed frame; only one frame needs a new pixel verdict.
        config()->set('product-images.max_images_by_type.laptop', 1);
        $this->assertSame(2, app(ProductImageStorage::class)->stage($fresh));
        $fresh = $fresh->fresh();
        $this->assertSame(1, $calls);
        $this->assertFalse($fresh->media()->where('verification_status', 'pending')->exists());
        $this->assertCount(2, $fresh->photo_source_context['_photo_visual_review']['frames']);
        foreach ($fresh->media as $media) {
            $this->assertSame($fresh->photo_source_context, $media->photo_source_context);
            Storage::disk('public')->assertExists($media->path);
        }
        Http::assertNothingSent();
    }

    public function test_interrupted_late_keyboard_review_does_not_reopen_the_source(): void
    {
        config()->set('product-images.max_images_by_type.laptop', 1);
        $draft = $this->savedKeyboardDraft(false, true);
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(fn (ProductDraft $target) => $this->italianOutcome($target));
        app(ProductImageStorage::class)->stage($draft);
        $this->mock(ProductImageCandidateDiscovery::class)->shouldNotReceive('find');
        $this->mock(ProductImageVisionVerifier::class)->shouldReceive('reviewCompleteGallery')->once()->andReturn(null);

        $this->assertSame(2, app(ProductImageStorage::class)->stage($draft->fresh()));
        $this->assertTrue($draft->media()->where('verification_status', 'pending')->exists());
        Http::assertNothingSent();
    }

    public function test_strict_review_of_a_frame_outside_the_retained_album_does_not_block_closed_photos(): void
    {
        $draft = $this->savedKeyboardDraft(false, true);
        $source = $draft->photo_source_context;
        $source['_photo_visual_review']['frames'][0]['keyboard_visibility'] = 'not_visible';
        $source['_photo_visual_review']['frames'][] = ['source_url' => self::PAGE.'/not-retained.jpg',
            'keyboard_visibility' => 'readable', 'keyboard_requirement_scope' => 'none'];
        $draft->update(['photo_source_context' => $source]);
        $this->mock(ProductSpecificationReconciler::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(fn (ProductDraft $target) => $this->italianOutcome($target));

        $this->assertSame(2, app(ProductImageStorage::class)->stage($draft));
        $fresh = $draft->fresh();
        $this->assertSame('complete', $fresh->gallery_status);
        $this->assertFalse($fresh->media()->where('verification_status', 'pending')->exists());
        $this->assertSame([], ProductPhotoKeyboardContext::pendingSources($fresh->photo_source_context));
        Http::assertNothingSent();
    }

    private function keyboardVision(int &$calls, bool $strict = false): void
    {
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$calls, $strict): array {
            $calls++;

            return ['images' => $attachments->keys()->map(fn (int $i): array => [
                'index' => $i + 1, 'gallery_rank' => $i + 1, 'exact_match' => true, 'color_match' => true,
                'physical_match' => 'match', 'color_observability' => 'clear', 'catalog_photo' => true,
                'publishable' => true, 'kind' => 'product', 'view' => 'front', 'score' => 95,
                'keyboard_visibility' => 'readable', 'keyboard_layout_match' => $strict ? 'match' : 'not_applicable',
                'keyboard_requirement_source' => $strict ? 'operator' : 'none',
                'keyboard_requirement_scope' => $strict ? 'photos' : 'none',
                'keyboard_layout' => $strict ? 'Italian' : '', 'keyboard_layout_note' => '',
                'reason' => 'Current body and colour match; keyboard result follows the request available at review time.',
            ])->all()];
        })->preventStrayPrompts();
    }

    private function italianOutcome(ProductDraft $draft): SpecificationReconciliationOutcome
    {
        $specs = array_values(array_filter($draft->specifications, fn (array $fact): bool => $fact['key'] !== 'keyboard_layout'));

        return SpecificationReconciliationOutcome::reconciled(self::PAGE, $draft->title, $draft->model, $draft->color,
            $draft->description ?? 'Laptop.', [...$specs, ['key' => 'keyboard_layout', 'name' => 'Keyboard layout', 'value' => 'Italian']], 'Verified final layout.');
    }

    private function savedKeyboardDraft(bool $pending, bool $strict = false): ProductDraft
    {
        $draft = $this->draft();
        if ($strict) {
            $draft->telegramUpdate->update(['payload' => [ProductInputContext::PAYLOAD_KEY => [
                'version' => 1, 'original_operator_request' => 'Photos must show the Italian keyboard.',
                'input_evidence' => [['key' => 'photo_keyboard_layout', 'value' => 'Italian', 'source_kind' => 'user_text']],
            ]]]);
        }
        $source = $draft->sources[0];
        $source['_photo_applicability'] = $this->compatible($draft, $source);
        $source['_photo_visual_review'] = ['scope' => 'current_downloaded_frames', 'keyboard_target' => [], 'differences' => [],
            'photo_requirement' => ProductPhotoKeyboardContext::photoRequirement(ProductInputContext::forDraft($draft)),
            'frames' => [['source_url' => self::PAGE.'/front.jpg', 'physical_match' => 'match', 'color_match' => true,
                'keyboard_visibility' => 'readable', 'keyboard_layout_match' => 'not_applicable', 'keyboard_requirement_scope' => 'none']]];
        if ($strict) {
            $source['_photo_visual_review']['frames'][] = ['source_url' => self::PAGE.'/closed.jpg',
                'keyboard_visibility' => 'not_visible', 'keyboard_requirement_scope' => 'none'];
        }
        $draft->update(['primary_source_url' => self::PAGE, 'photo_source_context' => $source,
            'gallery_confirmed_sufficient' => true, 'gallery_status' => 'partial']);
        foreach ($strict ? ['front', 'closed'] : ['front'] as $view) {
            $path = 'drafts/keyboard-'.$view.'.webp';
            Storage::disk('public')->put($path, $this->jpeg());
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => self::PAGE.'/'.$view.'.jpg',
                'checksum' => hash('sha256', $view), 'width' => 720, 'height' => 600,
                'verification_status' => $pending ? 'pending' : 'verified', 'gallery_traversal_complete' => true,
                'photo_source_context' => $source]);
        }

        return $draft;
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 881010, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => 'Laptop TARGET-32 black, 32 GB RAM', 'payload' => [], 'status' => 'processing']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review', 'title' => 'Laptop TARGET-32',
            'model' => 'TARGET-32', 'color' => 'black', 'product_type' => 'laptop',
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'TARGET-32'],
                ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB']],
            'sources' => [['url' => self::PAGE, 'title' => 'Laptop OTHER-16 16GB black', 'type' => 'retailer',
                'image_urls' => ['https://93.184.216.34/laptop-front.jpg']]], 'image_urls' => []]);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(720, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 230, 230));
        imagefilledrectangle($image, 60, 100, 660, 520, imagecolorallocate($image, 40, 40, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
