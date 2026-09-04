<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductIdentityMatcher;
use Tests\TestCase;

class ProductIdentityMatcherTest extends TestCase
{
    public function test_it_rejects_a_generic_asset_that_only_shares_two_incidental_words(): void
    {
        // Real production bug (2026-07-23): an Apple color-swatch banner
        // (not a photo of the product at all) matched "imac" + "pink" and
        // was fast-tracked past Vision entirely as a "source-verified" image.
        $draft = new ProductDraft([
            'title' => 'Apple iMac 24-inch (M4, 10-core GPU, 256GB, Pink)',
            'brand' => 'Apple',
            'model' => 'iMac',
            'color' => 'Pink',
        ]);

        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->supports(
            $draft,
            'https://images.apple.com/v/imac/specs/a/images/specs/colors_pink__e506dm1lok6e_large.jpg',
        ));
    }

    public function test_one_letter_separates_the_helios_line_from_the_helios_neo_line(): void
    {
        // Live case (draft #96): a shop listed "Acer Predator Helios 18 AI
        // PHN18-73" while the request was PH18-73. Its own words said Helios,
        // its own code said Neo - and PHN is what our catalog calls the Neo
        // line (draft #88 was "Predator Helios Neo 16 AI (PHN16-73)"). The code
        // is the identifier, so this page is another machine and 22 photographs
        // of it must not become this card's gallery.
        $draft = new ProductDraft([
            'title' => 'Acer Predator Helios 18 AI (PH18-73-90M0)',
            'brand' => 'Acer',
            'model' => 'PH18-73',
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Acer Predator Helios 18 AI (PH18-73) ищи',
        ]));

        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->conflictsSource($draft, [
            'url' => 'https://www.scan.co.uk/products/18-acer-predator-helios-18-ai-250hz-intel-core-ultra-9-275hx-32gb-ddr5-1tb-ssd',
            '_preflight_identity_evidence' => 'Acer Predator Helios 18 AI PHN18-73 18" WQXGA IPS 250Hz Core Ultra 9 RTX 5080 Gaming Laptop',
        ]));

        // The requested machine on the same shop is still welcome.
        $this->assertFalse($matcher->conflictsSource($draft, [
            'url' => 'https://www.scan.co.uk/products/18-acer-predator-helios-18-ai-250hz-core-ultra-9',
            '_preflight_identity_evidence' => 'Acer Predator Helios 18 AI PH18-73 18" WQXGA IPS 250Hz Gaming Laptop',
        ]));
    }

    public function test_it_accepts_a_url_containing_the_exact_alphanumeric_model_code(): void
    {
        $draft = new ProductDraft([
            'title' => 'ASUS Vivobook 15',
            'brand' => 'ASUS',
            'model' => 'X1504VA-BQ4485',
        ]);

        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->supports(
            $draft,
            'https://93.184.216.34/products/asus-vivobook-15-x1504va-bq4485',
        ));
    }

    public function test_it_accepts_a_url_containing_a_distinctive_standalone_number(): void
    {
        $draft = new ProductDraft([
            'title' => 'Intel Core i7-14700 Processor',
            'brand' => 'Intel',
            'model' => 'Core i7-14700',
        ]);

        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->supports($draft, 'https://93.184.216.34/intel-core-i7-14700.jpg'));
    }

    public function test_it_accepts_three_corroborating_generic_words_when_no_strong_token_exists(): void
    {
        $draft = new ProductDraft([
            'title' => 'Skytech Gaming Azure 3 Desktop PC',
            'brand' => 'Skytech Gaming',
            'model' => 'Azure 3',
            'color' => 'White',
        ]);

        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->supports(
            $draft,
            'https://skytechai.s3.us-west-002.backblazeb2.com/q12_azure3white360mmaiopicture1.webp',
        ));
    }

    public function test_it_rejects_an_empty_or_unrelated_url(): void
    {
        $draft = new ProductDraft([
            'title' => 'ASUS Vivobook 15',
            'brand' => 'ASUS',
            'model' => 'X1504VA-BQ4485',
        ]);

        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->supports($draft, ''));
        $this->assertFalse($matcher->supports($draft, 'https://example.com/unrelated-banner.jpg'));
    }

    public function test_it_confirms_an_exact_source_and_rejects_a_nearby_but_different_sku(): void
    {
        $draft = new ProductDraft([
            'title' => 'Origin Storage 256GB DDR4-3200 ECC RDIMM',
            'brand' => 'Origin Storage',
            'model' => 'OM256G43200R8RX4E12',
        ]);
        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->supportsSource($draft, [
            'title' => 'Origin Storage OM256G43200R8RX4E12',
            'url' => 'https://shop.example/products/om256g43200r8rx4e12',
        ]));
        $this->assertTrue($matcher->conflicts(
            $draft,
            'https://origin.example/server/origin-storage-om256g43200lr8rx4e12/',
        ));
        $this->assertFalse($matcher->conflicts(
            $draft,
            'https://shop.example/products/memory-module',
        ));
    }

    public function test_inferred_regional_sku_does_not_reject_an_exact_card_requested_by_model(): void
    {
        $draft = new ProductDraft([
            'telegram_update_id' => 123,
            'title' => 'Razer Blade 14 2025 Ryzen AI 9 365 RTX 5070',
            'brand' => 'Razer',
            'model' => 'Razer Blade 14 (2025)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => 'RZ09-05306ES3-R3U1'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Razer Blade 14 (2025), Ryzen AI 9 365, RTX 5070',
        ]));

        $source = [
            'url' => 'https://www.pbtech.com/product/NBKRAZ530633/Razer-Blade-14-GeForce-RTX-5070',
            'title' => 'Razer Blade 14 GeForce RTX 5070',
            '_preflight_identity_evidence' => implode(' ', [
                'Razer Blade 14 GeForce RTX 5070 Gaming Laptop',
                'AMD Ryzen AI 9 365',
                'RZ09-05306ES3-R3B1',
            ]),
        ];

        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->supportsSource($draft, $source));
        $this->assertFalse($matcher->conflicts($draft, $source['_preflight_identity_evidence']));
    }

    public function test_exact_hyphenated_sku_is_not_conflicted_by_its_derived_token_pair(): void
    {
        $draft = new ProductDraft([
            'telegram_update_id' => 123,
            'title' => 'HP OMEN MAX 16-ah0097nr',
            'brand' => 'HP',
            'model' => 'OMEN MAX 16-ah0097nr',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '16-ah0097nr'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'HP OMEN MAX 16-ah0097nr ищи',
        ]));
        $evidence = 'https://shop.example/product/1881718 HP OMEN MAX 16-ah0097nr Gaming Laptop';
        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->supportsEvidence($draft, $evidence));
        $this->assertFalse($matcher->conflicts($draft, $evidence));
    }

    public function test_it_confirms_a_source_whose_url_reorders_the_model_words(): void
    {
        // Real production bug (2026-08-26): a genuine B&H Photo Video
        // listing for the exact requested SKU (MC7A4LL/A) was rejected as
        // "unconfirmed identifier" purely because its URL slug puts the
        // screen size before the model name
        // ("apple_mc7a4ll_a_15_macbook_air_m4"), while the researched model
        // string is "MacBook Air 15 (M4)" - every distinguishing word is
        // genuinely present, just in a different order, so the old
        // concatenated-substring check never matched.
        $draft = new ProductDraft([
            'telegram_update_id' => 198,
            'title' => 'Apple MacBook Air 15-inch (M4) — 16GB / 256GB (Sky Blue)',
            'brand' => 'Apple',
            'model' => 'MacBook Air 15 (M4)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => 'MC7A4LL/A'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Apple MacBook Air 15" (M4) ищи',
        ]));

        $source = [
            'url' => 'https://www.bhphotovideo.com/c/product/1883955-REG/apple_mc7a4ll_a_15_macbook_air_m4.html',
            'title' => 'Apple 15" MacBook Air (M4, Sky Blue) — B&H Photo Video product page',
        ];

        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->supportsSource($draft, $source));
    }
}
