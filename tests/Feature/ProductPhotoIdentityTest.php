<?php

namespace Tests\Feature;

use App\Ai\Agents\GalleryFrameReviewAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductPhotoIdentity;
use App\Services\Products\SourceIdentityProvenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operator, 2026-11-04: a component is identified by the markings on it. Live
 * #379: a BC501 request published a PC801 drive from a reseller page whose address
 * carried the BC501 code; #380/#384: modules and drives of other models came from
 * "related products" of pages judged exact by their address.
 */
class ProductPhotoIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_categories_identify_by_label_and_search_galleries_like_laptops(): void
    {
        $components = Category::query()->where('slug', 'components')->firstOrFail();
        $this->assertSame(ProductPhotoIdentity::LABEL, $components->photoIdentity());
        $this->assertSame(Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST, $components->gallerySearchStrategy());
        $this->assertStringContainsString('наклейка', $components->photo_identity_hint);
        $this->assertSame(ProductPhotoIdentity::PAGE, Category::query()->where('slug', 'laptops')->firstOrFail()->photoIdentity());

        $this->assertTrue(ProductPhotoIdentity::fromLabel(new ProductDraft(['category' => 'components'])));
        $this->assertFalse(ProductPhotoIdentity::fromLabel(new ProductDraft(['category' => 'laptops', 'product_type' => 'laptop'])));
        // No category row yet: the product kind decides.
        $this->assertTrue(ProductPhotoIdentity::fromLabel(new ProductDraft(['product_type' => 'component'])));
    }

    public function test_a_marking_read_from_the_frame_is_compared_with_the_requested_codes(): void
    {
        $draft = new ProductDraft(['model' => 'BC501', 'specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => 'HFM512GDJTNG-8310A']]]);
        $this->assertSame('match', ProductPhotoIdentity::markingVerdict($draft, 'P/N HFM512GDJTNG-8310A BA'));
        $this->assertSame('conflict', ProductPhotoIdentity::markingVerdict($draft, 'HFS512GEJ9X125N'));
        $this->assertSame('none', ProductPhotoIdentity::markingVerdict($draft, ''));
        $this->assertSame('none', ProductPhotoIdentity::markingVerdict($draft, 'SK hynix'));

        $wd = new ProductDraft(['model' => 'PC SN530', 'specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => 'SDBPNPZ-256G-1002']]]);
        $this->assertSame('sibling', ProductPhotoIdentity::markingVerdict($wd, 'SDBPNPZ-256G-1014'));
        $samsung = new ProductDraft(['specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => 'M471A1K43DB1-CTD']]]);
        $this->assertSame('conflict', ProductPhotoIdentity::markingVerdict($samsung, 'M471A1K43CB1-CTD'));
        $pm991 = new ProductDraft(['specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => 'MZALQ512HALU-000L1']]]);
        $this->assertSame('sibling', ProductPhotoIdentity::markingVerdict($pm991, 'MZALQ512HALU-00000'));
    }

    public function test_a_readable_label_of_another_part_refuses_the_frame_even_from_a_page_with_the_code_in_its_address(): void
    {
        $draft = $this->componentDraft();
        $page = ['title' => 'SK hynix BC501 HFM512GDJTNG-8310A 512GB', 'url' => 'https://reseller.example/hfm512gdjtng-8310a', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact identifier in the page address.']);
        $prompts = [];
        ProductImageVisionAgent::fake(function (string $prompt, $attachments) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['images' => [
                $this->review(1, exactMatch: true, marking: 'P/N HFS512GEJ9X125N', reason: 'The page address carries the requested code; nothing visibly conflicts.'),
                $this->review(2, exactMatch: false, marking: '', reason: 'A clean M.2 drive without a readable label.'),
                $this->review(3, exactMatch: false, marking: 'HFM512GDJTNG-8310A BA', reason: 'The label names the requested part.'),
            ]];
        })->preventStrayPrompts();

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://reseller.example/photo-1.jpg', [...$page, '_photo_applicability' => $exact]),
            $this->frame('https://reseller.example/photo-2.jpg', [...$page, '_photo_applicability' => $exact]),
            $this->frame('https://reseller.example/photo-3.jpg', [...$page, '_photo_applicability' => $exact]),
        ], 4);

        $this->assertSame(['https://reseller.example/photo-3.jpg'], array_column($selected, 'source_url'));
        $this->assertSame('match', $selected[0]['photo_visual_review']['marking_verdict']);
        $this->assertStringContainsString('IDENTITY FROM MARKINGS', $prompts[0]);
        $this->assertStringContainsString('HFM512GDJTNG-8310A', $prompts[0]);
    }

    public function test_an_unlabelled_frame_is_taken_from_the_makers_own_page_or_record_but_not_from_a_reseller(): void
    {
        $draft = $this->componentDraft();
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => ['images' => $attachments->keys()
            ->map(fn (int $index): array => $this->review($index + 1, exactMatch: false, marking: '', reason: 'An M.2 drive, label not readable.'))->all()])
            ->preventStrayPrompts();
        $reseller = ['title' => 'BC501 512GB', 'url' => 'https://reseller.example/bc501', 'type' => 'retailer'];
        $maker = ['title' => 'SK hynix BC501', 'url' => 'https://maker.example/bc501', 'type' => 'manufacturer'];
        $record = ['title' => 'Icecat datasheet', 'url' => 'https://icecat.example/p/1', 'type' => 'web',
            SourceIdentityProvenance::KEY => SourceIdentityProvenance::codeMatch('icecat', ['HFM512GDJTNG-8310A'])];
        $exactFor = fn (array $page): array => ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://reseller.example/a.jpg', [...$reseller, '_photo_applicability' => $exactFor($reseller)]),
            $this->frame('https://maker.example/b.jpg', [...$maker, '_photo_applicability' => $exactFor($maker)]),
            $this->frame('https://icecat.example/c.jpg', [...$record, '_photo_applicability' => $exactFor($record)]),
        ], 4);

        $this->assertSame(['https://maker.example/b.jpg', 'https://icecat.example/c.jpg'], array_column($selected, 'source_url'));
        $this->assertStringContainsString('со страницы производителя', $selected[0]['vision_reason']);

        // With only reseller frames and no readable label, one clean frame goes out as the
        // unproven photo, said so (a card without a photo is a failure for the operator).
        $lastResort = app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://reseller.example/a.jpg', [...$reseller, '_photo_applicability' => $exactFor($reseller)]),
            $this->frame('https://reseller.example/b.jpg', [...$reseller, '_photo_applicability' => $exactFor($reseller)]),
        ], 4);
        $this->assertCount(1, $lastResort);
        $this->assertTrue($lastResort[0]['photo_visual_review']['identity_unproven']);
        $this->assertStringContainsString('без прочитанного кода', $lastResort[0]['vision_reason']);
    }

    public function test_a_reviewers_exact_without_a_readable_code_is_not_exact_unless_a_gallery_neighbour_carries_the_code(): void
    {
        // Live #390: «источник подтверждает модель, видимых противоречий нет» with an empty
        // marking went out as the exact photo of a memory module from a reseller's page.
        $draft = $this->componentDraft();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $this->review(1, exactMatch: true, marking: '', reason: 'The page confirms the model; nothing visible contradicts it.')]])
            ->preventStrayPrompts();
        $reseller = ['title' => 'BC501 512GB', 'url' => 'https://reseller.example/bc501', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $reseller, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://reseller.example/a.jpg', [...$reseller, '_photo_applicability' => $exact]),
        ], 4);

        $this->assertCount(1, $selected);
        $this->assertTrue($selected[0]['photo_visual_review']['identity_unproven']);
        $this->assertStringContainsString('без прочитанного кода', $selected[0]['vision_reason']);

        // The product's own gallery shows one item from several sides: the frame without a
        // readable code next to a frame whose code matched is that item.
        GalleryFrameReviewAgent::fake(fn (): array => ['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [], 'reason' => 'Clean'])
            ->preventStrayPrompts();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $this->review(1, exactMatch: true, marking: 'HFM512GDJTNG-8310A', reason: 'The label side.'),
            $this->review(2, exactMatch: true, marking: '', reason: 'The connector side of the same drive.'),
        ]])->preventStrayPrompts();
        $frames = [$this->frame('https://reseller.example/g1.jpg', $reseller), $this->frame('https://reseller.example/g2.jpg', $reseller)];

        $checked = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($frames, $draft);

        $this->assertSame(['https://reseller.example/g1.jpg', 'https://reseller.example/g2.jpg'], array_column($checked, 'source_url'));
        $this->assertStringContainsString('в той же галерее товара есть кадр с совпавшим кодом', $checked[1]['vision_reason']);
        $this->assertTrue($checked[1]['photo_visual_review']['labelled_neighbour']);
    }

    public function test_a_sibling_suffix_on_the_label_is_kept_with_a_notice(): void
    {
        $draft = $this->componentDraft('SDBPNPZ-256G-1002', 'WD PC SN530 256GB');
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $this->review(1, exactMatch: false, marking: 'SDBPNPZ-256G-1014', reason: 'The label shows another suffix of the same drive.')]])
            ->preventStrayPrompts();
        $page = ['title' => 'WD SN530 SDBPNPZ-256G-1002', 'url' => 'https://reseller.example/sn530', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://reseller.example/sn530.jpg', [...$page, '_photo_applicability' => $exact]),
        ], 4);

        $this->assertCount(1, $selected);
        $this->assertStringContainsString('родственный код SDBPNPZ-256G-1014', $selected[0]['vision_reason']);
    }

    public function test_a_components_playwright_gallery_is_read_for_labels_too(): void
    {
        $draft = $this->componentDraft();
        GalleryFrameReviewAgent::fake(fn (): array => ['non_product_frames' => [], 'uncertain_content_frames' => [], 'foreign_text_frames' => [], 'reason' => 'Clean'])
            ->preventStrayPrompts();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $this->review(1, exactMatch: true, marking: 'HFS512GEJ9X125N', reason: 'A drive from the same slider.'),
            $this->review(2, exactMatch: true, marking: 'HFM512GDJTNG-8310A', reason: 'The requested part.'),
        ]])->preventStrayPrompts();
        $page = ['title' => 'BC501', 'url' => 'https://reseller.example/bc501', 'type' => 'retailer'];
        $frames = [$this->frame('https://reseller.example/g1.jpg', $page), $this->frame('https://reseller.example/g2.jpg', $page)];

        $checked = app(ProductImageVisionVerifier::class)->checkedGalleryFrames($frames, $draft);

        $this->assertSame(['https://reseller.example/g2.jpg'], array_column($checked, 'source_url'));

        // A laptop's gallery is still checked for content and language only.
        $laptop = new ProductDraft(['title' => 'Laptop', 'category' => 'laptops', 'product_type' => 'laptop']);
        $this->assertCount(2, app(ProductImageVisionVerifier::class)->checkedGalleryFrames($frames, $laptop));
    }

    public function test_a_colourless_component_page_can_be_judged_exact(): void
    {
        $draft = $this->componentDraft();
        $laptop = new ProductDraft(['telegram_update_id' => $draft->telegram_update_id, 'title' => 'Laptop', 'model' => 'X1', 'category' => 'laptops', 'product_type' => 'laptop', 'specifications' => []]);
        $laptop->setRelation('telegramUpdate', $draft->telegramUpdate);
        $decision = fn (): array => ['verdict' => 'exact', 'reason' => 'Same part.', 'source_model' => 'BC501', 'differences' => [], 'evidence' => ['P/N on the page'],
            'plausible_target' => true, 'may_inspect_gallery' => true, 'physical_checks' => array_map(fn (string $key): array => ['status' => $key === 'color' ? 'unknown' : 'match', 'evidence' => $key === 'color' ? 'No colour.' : 'Same.'],
                array_combine(['platform', 'revision', 'construction', 'dimensions', 'ports', 'color'], ['platform', 'revision', 'construction', 'dimensions', 'ports', 'color']))];
        $guard = new \ReflectionMethod(ProductPhotoApplicability::class, 'guardVerdict');

        $this->assertSame('exact', $guard->invoke(app(ProductPhotoApplicability::class), $decision(), $draft)['verdict']);
        $this->assertSame('uncertain', $guard->invoke(app(ProductPhotoApplicability::class), $decision(), $laptop)['verdict']);
    }

    private function componentDraft(string $mpn = 'HFM512GDJTNG-8310A', string $title = 'SK hynix BC501 512GB NVMe SSD'): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => random_int(1000, 900000), 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => $title]);
        $draft = new ProductDraft(['telegram_update_id' => $update->id, 'title' => $title, 'brand' => explode(' ', $title)[0],
            'model' => explode(' ', $title)[1], 'category' => 'components', 'product_type' => 'component', 'color' => null,
            'specifications' => [['key' => 'mpn', 'name' => 'MPN', 'value' => $mpn], ['key' => 'form_factor', 'name' => 'Form factor', 'value' => 'M.2 2280']]]);
        $draft->setRelation('telegramUpdate', $update);

        return $draft;
    }

    private function frame(string $url, array $page): array
    {
        $image = imagecreatetruecolor(32, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        return ['image' => $image, 'source_url' => $url, 'page_source_context' => $page];
    }

    private function review(int $index, bool $exactMatch, string $marking, string $reason): array
    {
        return ['index' => $index, 'exact_match' => $exactMatch, 'color_match' => true, 'physical_match' => 'match', 'publishable' => true,
            'catalog_photo' => true, 'kind' => 'product', 'view' => 'front', 'gallery_rank' => $index, 'score' => 85,
            'observed_marking' => $marking, 'rejection_reason' => 'none', 'reason' => $reason];
    }
}
