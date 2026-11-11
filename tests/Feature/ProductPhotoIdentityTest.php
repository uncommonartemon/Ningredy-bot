<?php

namespace Tests\Feature;

use App\Ai\Agents\GalleryFrameReviewAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Ai\Agents\ProductLabelReaderAgent;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductPhotoIdentity;
use App\Services\Products\SourceIdentityProvenance;
use Database\Seeders\StarterCatalogSeeder;
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

    /**
     * Operator, 2026-11-07: no rule about boxes on components. Only the rule's sentence leaves
     * a hint; what else is written there stays, and so does a rule written on another category.
     */
    public function test_the_box_rule_leaves_component_hints_and_nothing_else_does(): void
    {
        $hints = ['components' => 'Без фото коробки', 'cpu' => 'Без фото коробки (OEM/tray). На крышке процессора — модель.',
            'ssd' => 'Без фото коробки. Код на наклейке — P/N.', 'gpu' => 'Своя фраза. Без фото коробки. Ещё фраза.', 'laptops' => 'Без фото коробки'];
        foreach ($hints as $slug => $hint) {
            Category::query()->where('slug', $slug)->update(['product_search_hint' => $hint]);
        }

        (require database_path('migrations/2026_11_07_100000_drop_box_rule_from_component_hints.php'))->up();

        $left = Category::query()->whereIn('slug', array_keys($hints))->pluck('product_search_hint', 'slug');
        $this->assertNull($left['components']);
        $this->assertSame('На крышке процессора — модель.', $left['cpu']);
        $this->assertSame('Код на наклейке — P/N.', $left['ssd']);
        $this->assertSame('Своя фраза. Ещё фраза.', $left['gpu']);
        $this->assertSame('Без фото коробки', $left['laptops']);
        // A new install starts without it.
        $this->assertNull(StarterCatalogSeeder::CATEGORIES['components']['product_search_hint']);
        foreach (StarterCatalogSeeder::COMPONENT_KINDS as $kind) {
            $this->assertStringNotContainsString('коробк', $kind['product_search_hint']);
        }
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

    public function test_a_retail_parts_own_name_on_the_frame_is_not_another_code(): void
    {
        // Live #409: «WD Blue SN570 500GB» on the drive, refused at every store against WDS500G3B0C.
        $draft = new ProductDraft(['title' => 'Western Digital WD Blue SN570 500GB NVMe SSD (WDS500G3B0C)', 'brand' => 'Western Digital', 'model' => 'WDS500G3B0C',
            'specifications' => [['key' => 'capacity', 'name' => 'Capacity', 'value' => '500 GB'], ['key' => 'form_factor', 'name' => 'Form factor', 'value' => 'M.2 2280']]]);
        $this->assertSame('name', ProductPhotoIdentity::markingVerdict($draft, 'WD Blue SN570 NVMe SSD; 500GB'));
        $this->assertSame('name', ProductPhotoIdentity::markingVerdict($draft, 'WD Blue SN570'));
        $this->assertSame('match', ProductPhotoIdentity::markingVerdict($draft, 'WDS500G3B0C WD Blue SN570'));
        // Another model or capacity has a word of its own.
        $this->assertSame('conflict', ProductPhotoIdentity::markingVerdict($draft, 'WD_BLACK SN750'));
        $this->assertSame('conflict', ProductPhotoIdentity::markingVerdict($draft, 'WD Blue SN570 1TB'));
        // The name says «512 GB», the label prints 512GB.
        $samsung = new ProductDraft(['title' => 'Samsung PM9A1 512 GB M.2 2280 PCIe 4.0 NVMe SSD', 'model' => 'MZVL2512HCJQ-00B00']);
        $this->assertSame('name', ProductPhotoIdentity::markingVerdict($samsung, 'Samsung PM9A1 512GB'));
    }

    public function test_a_frame_showing_the_products_own_name_goes_out_from_a_reseller_and_another_model_does_not(): void
    {
        $draft = $this->componentDraft('WDS500G3B0C', 'WD Blue SN570 500GB NVMe SSD');
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $this->review(1, exactMatch: true, marking: 'WD Blue SN570 NVMe SSD; 500GB', reason: 'The requested drive under its own name.'),
            $this->review(2, exactMatch: true, marking: 'WD_BLACK SN750', reason: 'From the related products block.'),
        ]])->preventStrayPrompts();
        $page = ['title' => 'WD Blue SN570 WDS500G3B0C', 'url' => 'https://reseller.example/sn570', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://reseller.example/a.jpg', [...$page, '_photo_applicability' => $exact]),
            $this->frame('https://reseller.example/b.jpg', [...$page, '_photo_applicability' => $exact]),
        ], 4);

        $this->assertSame(['https://reseller.example/a.jpg'], array_column($selected, 'source_url'));
        $this->assertSame('name', $selected[0]['photo_visual_review']['marking_verdict']);
        $this->assertFalse($selected[0]['photo_visual_review']['identity_unproven']);
        $this->assertStringContainsString('название этой модели', $selected[0]['vision_reason']);
    }

    public function test_a_name_the_reviewer_is_unsure_of_is_the_last_resort_and_not_a_lost_frame(): void
    {
        $draft = $this->componentDraft('WDS500G3B0C', 'WD Blue SN570 500GB NVMe SSD');
        // Live #409, coolblue: «identifies the SN570 family but not the full part number; no visible contradiction».
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            [...$this->review(1, exactMatch: false, marking: 'WD Blue SN570 NVMe SSD', reason: 'The family, not the full part number.'),
                'publishable' => false, 'rejection_reason' => 'identity', 'physical_match' => 'conflicting'],
        ]])->preventStrayPrompts();
        $page = ['title' => 'WD Blue SN570 WDS500G3B0C', 'url' => 'https://reseller.example/sn570', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://reseller.example/a.jpg', [...$page, '_photo_applicability' => $exact]),
        ], 4);

        $this->assertCount(1, $selected);
        $this->assertTrue($selected[0]['photo_visual_review']['identity_unproven']);
    }

    public function test_a_sibling_label_the_reviewer_refused_goes_out_before_a_frame_with_no_code(): void
    {
        // Live #402: the drive's real label refused for one letter, its back with no code published.
        $draft = $this->componentDraft();
        $refused = ['publishable' => false, 'rejection_reason' => 'identity', 'physical_match' => 'conflicting'];
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            [...$this->review(1, exactMatch: false, marking: 'HFM512GDJTNG-8310 BA', reason: 'The readable marking differs from the requested one.'), ...$refused],
            $this->review(2, exactMatch: true, marking: '', reason: 'The back of an M.2 drive; the page names the model.'),
        ]])->preventStrayPrompts();
        $page = ['title' => 'SK hynix BC501 HFM512GDJTNG-8310A', 'url' => 'https://reseller.example/bc501', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);
        $frames = [
            $this->frame('https://reseller.example/label.jpg', [...$page, '_photo_applicability' => $exact]),
            $this->frame('https://reseller.example/back.jpg', [...$page, '_photo_applicability' => $exact]),
        ];

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $frames, 4);

        $this->assertSame(['https://reseller.example/label.jpg'], array_column($selected, 'source_url'));
        $this->assertSame('sibling', $selected[0]['photo_visual_review']['marking_verdict']);
        $this->assertStringContainsString('родственный код', $selected[0]['vision_reason']);

        // A different body is refused for what it is, whatever the label says.
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            [...$this->review(1, exactMatch: false, marking: 'HFM512GDJTNG-8310 BA', reason: 'A 2242 board, not 2280.'),
                'publishable' => false, 'rejection_reason' => 'body', 'physical_match' => 'conflicting'],
        ]])->preventStrayPrompts();
        $this->assertSame([], app(ProductImageVisionVerifier::class)->select($draft, [$frames[0]], 4));
    }

    /** #203 at the colleague's: the label shows the code with a letter and a digit swapped against the request. */
    public function test_a_label_with_the_code_read_with_a_slip_goes_out_although_the_reviewer_refused_it(): void
    {
        $draft = $this->componentDraft('M393A4K40BB1-CRCQ0', 'Samsung M393A4K40BB1-CRCQ0 32GB DDR4-2400 ECC Registered DIMM');
        $this->assertSame('match', ProductPhotoIdentity::markingVerdict($draft, 'M393A4K40BB1-CRC0Q'));
        $this->assertSame('conflict', ProductPhotoIdentity::markingVerdict($draft, 'M393A3K40CB2-CRC'));
        ProductImageVisionAgent::fake(fn (): array => ['images' => [[...$this->review(1, exactMatch: false, marking: 'M393A4K40BB1-CRC0Q',
            reason: 'На наклейке M393A4K40BB1-CRC0Q, отличный от запрошенного M393A4K40BB1-CRCQ0.'),
            'publishable' => false, 'rejection_reason' => 'identity', 'physical_match' => 'conflicting']]])->preventStrayPrompts();
        $page = ['title' => 'Samsung M393A4K40BB1-CRC0Q 32GB', 'url' => 'https://reseller.example/m393', 'type' => 'retailer'];

        $selected = app(ProductImageVisionVerifier::class)->select($draft, [$this->frame('https://reseller.example/label.jpg', $page)], 4);

        $this->assertSame(['https://reseller.example/label.jpg'], array_column($selected, 'source_url'));
        $this->assertSame('match', $selected[0]['photo_visual_review']['marking_verdict']);
    }

    /** Operator, 2026-11-11: a page the admin sent as the right one stands like the maker's own. */
    public function test_a_frame_from_the_page_the_operator_named_goes_out_without_a_code_but_not_with_another_one(): void
    {
        $draft = $this->componentDraft();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $this->review(1, exactMatch: true, marking: '', reason: 'A clean M.2 drive; no code is readable.'),
            [...$this->review(2, exactMatch: false, marking: 'HFS512GEJ9X125N', reason: 'Another part number on the label.'),
                'publishable' => false, 'rejection_reason' => 'identity', 'physical_match' => 'conflicting'],
        ]])->preventStrayPrompts();
        $page = ['title' => 'A shop page the bot could not judge', 'url' => 'https://reseller.example/any', 'type' => 'retailer'];
        $frames = array_map(fn (string $name): array => [...$this->frame("https://reseller.example/{$name}.jpg", $page),
            'operator_page' => true, 'source_identity_confirmed' => true], ['clean', 'other']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $frames, 4);

        $this->assertSame(['https://reseller.example/clean.jpg'], array_column($selected, 'source_url'));
        $this->assertStringContainsString('которую указал оператор', $selected[0]['vision_reason']);
    }

    public function test_a_code_the_reviewer_saw_counts_only_when_a_reader_not_told_it_finds_it_too(): void
    {
        // Live #420: «маркировка MQ04ABF100 видна на наклейке» on a drive labelled MDD.
        config(['product-images.blind_label_read' => true]);
        $draft = $this->componentDraft('MQ04ABF100', 'Toshiba MQ04ABF100 1TB 2.5-inch HDD');
        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => ['images' => $attachments->keys()
            ->map(fn (int $index): array => $this->review($index + 1, exactMatch: true, marking: 'MQ04ABF100', reason: 'Marking visible on the label.'))->all()])
            ->preventStrayPrompts();
        $asked = [];
        ProductLabelReaderAgent::fake(function (string $prompt, $attachments) use (&$asked): array {
            $asked[] = [$prompt, $attachments->count()];

            return ['images' => [
                ['index' => 1, 'brand' => 'MDD', 'marking' => 'MD1000GSA854'],
                ['index' => 2, 'brand' => 'MDD', 'marking' => '1TB Mobile HDD'],
                ['index' => 3, 'brand' => 'Toshiba', 'marking' => 'MQ04ABF100'],
            ]];
        })->preventStrayPrompts();
        $page = ['title' => 'Toshiba MQ04ABF100 1TB', 'url' => 'https://reseller.example/mq04', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);
        $frames = array_map(fn (string $name): array => $this->frame("https://reseller.example/{$name}.jpg", [...$page, '_photo_applicability' => $exact]), ['other-code', 'other-maker', 'real']);

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $frames, 4);

        $this->assertSame(['https://reseller.example/real.jpg'], array_column($selected, 'source_url'));
        $this->assertSame('match', $selected[0]['photo_visual_review']['marking_verdict']);
        $this->assertSame('MQ04ABF100', $selected[0]['photo_visual_review']['observed_marking']);
        // The refusal is in the journal, not only in the result.
        $journal = ProductSourceAttempt::query()->where('action', 'compare_labels')->sole();
        $this->assertStringContainsString('«MDD MD1000GSA854» — conflict', (string) $journal->message);
        $this->assertSame('MQ04ABF100', $journal->output['frames'][0]['reviewer_marking']);
        $this->assertSame('conflict', $journal->output['frames'][0]['verdict']);
        // The reader is told nothing about the wanted product.
        $this->assertCount(1, $asked);
        $this->assertSame(3, $asked[0][1]);
        $this->assertStringNotContainsString('MQ04ABF100', $asked[0][0]);
        $this->assertStringNotContainsString('Toshiba', $asked[0][0]);
    }

    /** One character in a name of short words is another product, for the reviewer and for the reader alike. */
    public function test_a_short_model_name_one_character_off_is_another_product(): void
    {
        $intel = new ProductDraft(['brand' => 'Intel', 'title' => 'Intel Core i5-12400F Processor', 'model' => 'Core i5-12400F']);
        $this->assertSame('match', ProductPhotoIdentity::blindVerdict($intel, 'Core i5-12400F', 'Intel'));
        $this->assertSame('conflict', ProductPhotoIdentity::blindVerdict($intel, 'Core i5-13400F', 'Intel'));
        $amd = new ProductDraft(['brand' => 'AMD', 'title' => 'AMD Ryzen 5 5600X Processor', 'model' => 'Ryzen 5 5600X']);
        foreach (['Ryzen 5 5600G', 'Ryzen 5 5500X'] as $another) {
            $this->assertSame('conflict', ProductPhotoIdentity::markingVerdict($amd, $another));
            $this->assertSame('conflict', ProductPhotoIdentity::blindVerdict($amd, $another, 'AMD'));
        }
        $msi = new ProductDraft(['brand' => 'MSI', 'title' => 'MSI GeForce RTX 4060 VENTUS 2X BLACK 8G OC', 'model' => 'RTX 4060 VENTUS 2X BLACK 8G OC']);
        $this->assertSame('conflict', ProductPhotoIdentity::blindVerdict($msi, 'RTX 4070 VENTUS 2X BLACK 8G OC', 'MSI'));
    }

    public function test_what_a_reader_not_told_the_product_found_is_compared_with_room_for_a_slip(): void
    {
        // Real readings of real frames, 2026-11-07.
        $toshiba = new ProductDraft(['brand' => 'Toshiba', 'title' => 'Toshiba MQ04ABF100 1TB 2.5-inch HDD', 'model' => 'MQ04ABF100']);
        $this->assertSame('conflict', ProductPhotoIdentity::blindVerdict($toshiba, 'MD1000GSA854', 'MDD'));
        $this->assertSame('conflict', ProductPhotoIdentity::blindVerdict($toshiba, '1TB Mobile HDD', 'MDD'));
        $this->assertSame('none', ProductPhotoIdentity::blindVerdict($toshiba, '', 'MDT'));
        $kioxia = new ProductDraft(['brand' => 'KIOXIA', 'title' => 'KIOXIA XG6 512GB NVMe SSD', 'model' => 'KXG60ZNV512G']);
        $this->assertSame('match', ProductPhotoIdentity::blindVerdict($kioxia, 'KXG80ZNV512G', 'KIOXIA'));
        $this->assertSame('conflict', ProductPhotoIdentity::blindVerdict($kioxia, 'KXG5AZNV512G', 'KIOXIA'));
        $hynix = new ProductDraft(['brand' => 'SK hynix', 'title' => 'SK hynix BC501 512GB NVMe SSD', 'model' => 'BC501',
            'specifications' => [['key' => 'mpn', 'value' => 'HFM512GDJTNG-8310A']]]);
        $this->assertSame('match', ProductPhotoIdentity::blindVerdict($hynix, 'BC501 NVMe; HFM512GDJNG-8310A BA', 'SK hynix'));
        $wd = new ProductDraft(['brand' => 'Western Digital', 'title' => 'Western Digital WD Blue SN570 500GB NVMe SSD', 'model' => 'WDS500G3B0C']);
        $this->assertSame('name', ProductPhotoIdentity::blindVerdict($wd, 'WD Blue SN570', 'Western Digital'));
        $this->assertSame('conflict', ProductPhotoIdentity::blindVerdict($wd, 'WD_BLACK SN750', 'Western Digital'));
        $crucial = new ProductDraft(['brand' => 'Crucial', 'title' => 'Crucial P3 Plus 1TB M.2 NVMe SSD', 'model' => 'CT1000P3PSSD8']);
        $this->assertSame('name', ProductPhotoIdentity::blindVerdict($crucial, 'P3 Plus', 'Crucial'));
    }

    public function test_a_label_the_reader_cannot_read_is_the_last_resort_and_a_failed_reading_leaves_the_reviewers(): void
    {
        config(['product-images.blind_label_read' => true]);
        $draft = $this->componentDraft('MQ04ABF100', 'Toshiba MQ04ABF100 1TB 2.5-inch HDD');
        ProductImageVisionAgent::fake(fn (): array => ['images' => [$this->review(1, exactMatch: true, marking: 'MQ04ABF100', reason: 'Marking visible.')]])->preventStrayPrompts();
        $page = ['title' => 'Toshiba MQ04ABF100 1TB', 'url' => 'https://reseller.example/mq04', 'type' => 'retailer'];
        $exact = ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);
        $frame = $this->frame('https://reseller.example/a.jpg', [...$page, '_photo_applicability' => $exact]);

        ProductLabelReaderAgent::fake(fn (): array => ['images' => [['index' => 1, 'brand' => '', 'marking' => '']]])->preventStrayPrompts();
        $unread = app(ProductImageVisionVerifier::class)->select($draft, [$frame], 4);
        $this->assertTrue($unread[0]['photo_visual_review']['identity_unproven']);

        // The helper failing is not a verdict on the frame.
        ProductLabelReaderAgent::fake(fn () => throw new \RuntimeException('Provider down'))->preventStrayPrompts();
        $unchecked = app(ProductImageVisionVerifier::class)->select($draft, [$frame], 4);
        $this->assertSame('match', $unchecked[0]['photo_visual_review']['marking_verdict']);
    }

    public function test_the_makers_own_page_and_a_frame_with_no_marking_are_not_read_again(): void
    {
        config(['product-images.blind_label_read' => true]);
        $draft = $this->componentDraft();
        ProductImageVisionAgent::fake(fn (): array => ['images' => [
            $this->review(1, exactMatch: true, marking: 'HFM512GDJTNG-8310A', reason: 'The maker\'s render.'),
            $this->review(2, exactMatch: false, marking: '', reason: 'No label in sight.'),
        ]])->preventStrayPrompts();
        ProductLabelReaderAgent::fake(fn () => throw new \RuntimeException('Must not be asked'))->preventStrayPrompts();
        $maker = ['title' => 'SK hynix BC501', 'url' => 'https://maker.example/bc501', 'type' => 'manufacturer'];
        $reseller = ['title' => 'BC501 512GB', 'url' => 'https://reseller.example/bc501', 'type' => 'retailer'];
        $exactFor = fn (array $page): array => ProductPhotoApplicability::bind($draft, $page, ['verdict' => 'exact', 'plausible_target' => true, 'reason' => 'Exact.']);

        app(ProductImageVisionVerifier::class)->select($draft, [
            $this->frame('https://maker.example/a.jpg', [...$maker, '_photo_applicability' => $exactFor($maker)]),
            $this->frame('https://reseller.example/b.jpg', [...$reseller, '_photo_applicability' => $exactFor($reseller)]),
        ], 4);

        ProductLabelReaderAgent::assertNeverPrompted();
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
