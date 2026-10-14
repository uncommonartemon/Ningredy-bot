<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductIdentityMatcher;
use Tests\TestCase;

class ProductIdentityMatcherTest extends TestCase
{
    public function test_a_shared_family_does_not_hide_a_conflicting_explicit_model(): void
    {
        $draft = $this->explicitStealthDraft();
        $matcher = new ProductIdentityMatcher;
        $source = [
            'url' => 'https://shop.example/stealth-16-ai-a2hwgg-008it',
            'title' => 'MSI Stealth 16 AI A2HWGG-008IT',
        ];

        // A2HWGG is useful for discovery, never an excuse to ignore the
        // different whole code explicitly supplied by the operator.
        $this->assertTrue($matcher->supportsSource($draft, $source));
        $this->assertFalse($matcher->confirmsExactIdentifier($draft, $source));
        $this->assertTrue($matcher->conflictsSource($draft, $source));
    }

    public function test_a_different_regional_model_remains_unconfirmed_for_the_identity_judge(): void
    {
        $draft = $this->explicitStealthDraft();
        $matcher = new ProductIdentityMatcher;

        foreach (['MSI Stealth 16 AI A2HWGG-008US', 'MSI Stealth 16 AI A2HWGG'] as $title) {
            $source = ['url' => 'https://shop.example/product/1', 'title' => $title];

            $this->assertTrue($matcher->supportsSource($draft, $source));
            $this->assertFalse($matcher->confirmsExactIdentifier($draft, $source));
        }

        // Outside the conservative conflict rule, a different regional code
        // is unknown to this matcher, not permission to use another variant.
        $this->assertFalse($matcher->conflictsSource($draft, [
            'url' => 'https://shop.example/product/1',
            'title' => 'MSI Stealth 16 AI A2HWGG-008US',
        ]));
    }

    public function test_a_matching_mpn_does_not_hide_a_conflicting_explicit_model(): void
    {
        $draft = $this->explicitStealthDraft();
        $matcher = new ProductIdentityMatcher;
        $source = [
            'url' => 'https://shop.example/product/1',
            'title' => 'MSI Stealth 16 AI A2HWGG-008IT',
            '_preflight_identity_evidence' => 'MPN: 9S7-15F515-009',
        ];

        // Contradictory evidence must be inspected even when a different
        // dedicated identifier agrees. Callers check conflict before trust.
        $this->assertTrue($matcher->conflictsSource($draft, $source));
    }

    public function test_derived_repeated_family_tokens_do_not_invent_a_conflicting_identifier(): void
    {
        $draft = new ProductDraft([
            'model' => 'Stealth 16 AI A2HWGG-009IT',
            'specifications' => [['key' => 'mpn', 'value' => '9S7-15F515-009']],
        ]);
        $matcher = new ProductIdentityMatcher;

        // No original-request relation: the matcher considers the settled
        // values. It must not fabricate A2HWGG009ITA2HWGG by appending a
        // hyphenated code's own first atom, making it look more specific.
        $this->assertNull($matcher->conflictingIdentifierInSource($draft, [
            'url' => 'https://shop.example/product/123',
            '_preflight_identity_evidence' => 'Stealth 16 AI A2HWGG-008US',
        ]));
    }

    public function test_the_correct_full_model_without_the_dedicated_mpn_is_not_rejected(): void
    {
        $draft = $this->explicitStealthDraft();
        $matcher = new ProductIdentityMatcher;
        $source = [
            'url' => 'https://shop.example/product/1',
            'title' => 'MSI Stealth 16 AI A2HWGG-009IT',
        ];

        // A missing second identifier is not a contradiction. The correct
        // full model remains eligible for the identity judge.
        $this->assertFalse($matcher->conflictsSource($draft, $source));
        $this->assertFalse($matcher->confirmsExactIdentifier($draft, $source));

        $source['_preflight_identity_evidence'] = 'MPN: 9S7-15F515-009; SKU: BBU9285H32GXXDX11MA';
        $this->assertTrue($matcher->confirmsExactIdentifier($draft, $source));
        $this->assertFalse($matcher->conflictsSource($draft, $source));
    }

    public function test_one_matching_dedicated_code_does_not_confirm_other_unverified_operator_identifiers(): void
    {
        $draft = $this->explicitStealthDraft();
        $matcher = new ProductIdentityMatcher;

        foreach (['MSI Stealth 16 AI A2HWGG-008US', 'MSI Stealth 16 AI A2HWGG'] as $title) {
            $source = [
                'url' => 'https://shop.example/product/1',
                'title' => $title,
                '_preflight_identity_evidence' => 'MPN: 9S7-15F515-009; SKU: BBU9285H32GXXDX11MA',
            ];

            // The remaining explicit model is not confirmed. That removes
            // the free exact shortcut; it does not turn missing evidence
            // into a deterministic rejection.
            $this->assertFalse($matcher->confirmsExactIdentifier($draft, $source));
            $this->assertFalse($matcher->conflictsSource($draft, $source));
        }
    }

    public function test_a_single_explicit_mpn_can_still_confirm_without_other_inferred_identifiers(): void
    {
        $draft = $this->explicitStealthDraft();
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'найди 9S7-15F515-009',
        ]));
        $matcher = new ProductIdentityMatcher;
        $source = [
            'url' => 'https://shop.example/product/1',
            '_preflight_identity_evidence' => 'MPN: 9S7-15F515-009',
        ];

        $this->assertTrue($matcher->confirmsExactIdentifier($draft, $source));
        $this->assertFalse($matcher->conflictsSource($draft, $source));
    }

    public function test_an_explicit_code_inside_the_model_cannot_be_overridden_by_an_inferred_mpn(): void
    {
        $matcher = new ProductIdentityMatcher;
        foreach (['A2HWGG-009IT', '009IT'] as $request) {
            $draft = $this->explicitStealthDraft();
            $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => $request]));
            $source = [
                'url' => 'https://shop.example/product/1',
                'title' => 'MSI Stealth 16 AI A2HWGG-008US',
                '_preflight_identity_evidence' => 'MPN: 9S7-15F515-009',
            ];

            $this->assertFalse($matcher->confirmsExactIdentifier($draft, $source), $request);
            $source['title'] = 'MSI Stealth 16 AI A2HWGG-009IT';
            $this->assertTrue($matcher->confirmsExactIdentifier($draft, $source), $request);
        }
    }

    public function test_an_explicit_full_model_and_matching_mpn_do_not_require_an_inferred_sku(): void
    {
        $draft = $this->explicitStealthDraft();
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'MSI Stealth 16 AI A2HWGG-009IT ищи',
        ]));
        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->confirmsExactIdentifier($draft, [
            'url' => 'https://shop.example/product/1',
            'title' => 'MSI Stealth 16 AI A2HWGG-009IT',
            '_preflight_identity_evidence' => 'MPN: 9S7-15F515-009',
        ]));
    }

    public function test_a_generic_family_request_does_not_make_the_selected_regional_code_mandatory(): void
    {
        $draft = $this->explicitStealthDraft();
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'MSI Stealth 16 AI ищи',
        ]));
        $matcher = new ProductIdentityMatcher;
        $source = [
            'url' => 'https://shop.example/product/1',
            'title' => 'MSI Stealth 16 AI A2HWGG-008US',
        ];

        $this->assertFalse($matcher->conflictsSource($draft, $source));
        $this->assertFalse($matcher->confirmsExactIdentifier($draft, $source));
        $this->assertTrue($matcher->supportsSource($draft, $source));
        $this->assertFalse($matcher->requiresExactIdentifier($draft));
    }

    public function test_only_real_operator_code_tokens_make_identifier_confirmation_mandatory(): void
    {
        $matcher = new ProductIdentityMatcher;
        foreach (['A2HWGG-009IT', '009IT', '9S7-15F515-009'] as $request) {
            $draft = $this->explicitStealthDraft();
            $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'найди '.$request]));
            $this->assertTrue($matcher->requiresExactIdentifier($draft), $request);
        }

        $draft = new ProductDraft([
            'model' => 'MacBook Air 15 M4',
            'specifications' => [['key' => 'mpn', 'value' => 'MC7A4LL/A']],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'найди MC7A4LL/A']));
        $this->assertTrue($matcher->requiresExactIdentifier($draft));
    }

    public function test_a_number_is_mandatory_only_when_it_is_a_whole_explicit_dedicated_identifier(): void
    {
        $matcher = new ProductIdentityMatcher;
        $draft = new ProductDraft([
            'model' => 'Laptop 2025 16 GB',
            'specifications' => [['key' => 'gtin', 'value' => '0199291000008']],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'Laptop 2025 16 GB']));
        $this->assertFalse($matcher->requiresExactIdentifier($draft));
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'найди 0199291000008']));
        $this->assertTrue($matcher->requiresExactIdentifier($draft));
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'найди 01992910000081']));
        $this->assertFalse($matcher->requiresExactIdentifier($draft));
    }

    public function test_missing_original_text_preserves_the_conservative_identifier_requirement(): void
    {
        $draft = new ProductDraft(['model' => 'A2HWGG-009IT']);
        $this->assertTrue((new ProductIdentityMatcher)->requiresExactIdentifier($draft));
    }

    public function test_a_page_naming_the_requested_code_beside_its_siblings_is_for_the_judge(): void
    {
        // Request #94: the G14 (2024) spec page lists every configuration. It
        // was refused as "another product" and the card had nothing left to be
        // built from, although the requested GA403UV build was on it.
        $draft = new ProductDraft([
            'brand' => 'ASUS',
            'model' => 'ROG Zephyrus G14 GA403UV-G14.R94060',
            'specifications' => [['key' => 'sku', 'value' => 'GA403UV-G14.R94060']],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'ASUS ROG Zephyrus G14 GA403UV ищи']));
        $matcher = new ProductIdentityMatcher;
        $familyPage = [
            'url' => 'https://rog.asus.com/us/laptops/rog-zephyrus/rog-zephyrus-g14-2024/spec/',
            'title' => 'ROG Zephyrus G14 (2024) | Gaming Laptops',
            '_preflight_identity_evidence' => 'Tech Specs GA403UI-XS96 GA403UV-G14.R94060 GA403UI-G14.R94070',
        ];

        $this->assertFalse($matcher->conflictsSource($draft, $familyPage), 'the requested build is on the page');
        $this->assertFalse($matcher->confirmsExactIdentifier($draft, $familyPage), 'nor does the page confirm it is the one sold');

        // Only the sibling, without the requested code, is still another product.
        $this->assertTrue($matcher->conflictsSource($draft, [
            'url' => 'https://shop.example/rog-zephyrus-g14-ga403ui',
            'title' => 'ASUS ROG Zephyrus G14 GA403UI-XS96',
        ]));
        $this->assertTrue($matcher->confirmsExactIdentifier($draft, [
            'url' => 'https://shop.example/rog-zephyrus-g14',
            'title' => 'ASUS ROG Zephyrus G14 GA403UV-G14.R94060',
        ]));
    }

    public function test_short_regional_siblings_are_ambiguous_without_becoming_a_conflict(): void
    {
        $draft = new ProductDraft([
            'brand' => 'Acme', 'model' => 'Orbit Pro 14 Q14-512EU',
            'specifications' => [['key' => 'mpn', 'value' => 'Q14-512EU']],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'Orbit Pro 14 Q14-512EU']));
        $matcher = new ProductIdentityMatcher;
        foreach (['Orbit Pro 14', '14 inch Orbit Pro'] as $name) {
            $source = ['url' => 'https://shop.example/product', 'title' => $name.' Q14-512EU'];
            $this->assertTrue($matcher->confirmsExactIdentifier($draft, $source));
            $source['title'] .= ' or Q14-512US';
            $this->assertFalse($matcher->confirmsExactIdentifier($draft, $source));
            $this->assertFalse($matcher->conflictsSource($draft, $source));
        }
    }

    private function explicitStealthDraft(): ProductDraft
    {
        $draft = new ProductDraft([
            'brand' => 'MSI',
            'model' => 'Stealth 16 AI A2HWGG-009IT',
            'specifications' => [
                ['key' => 'sku', 'value' => 'BBU9285H32GXXDX11MA'],
                ['key' => 'mpn', 'value' => '9S7-15F515-009'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => "MSI Stealth 16 AI A2HWGG\nMKT: Stealth 16 AI A2HWGG-009IT\nSKU: BBU9285H32GXXDX11MA\n9S7-15F515-009",
        ]));

        return $draft;
    }

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

    public function test_an_agent_resolved_sku_can_confirm_a_source_when_the_operator_wrote_only_a_generic_request(): void
    {
        // Real production case (2026-09-10): the operator asked for "Lenovo
        // LOQ 15 - Luna Grey", the research agent resolved the exact
        // 83JE0013US onto the draft, and neither the operator's own words
        // nor any reordered atomic part of that SKU appear in them - both
        // the narrow (operator-typed) and, before this fix, the confirmation
        // identifier lists came back empty. supportsSource() then refused
        // every candidate page unconditionally, including one whose URL
        // spelled the exact SKU out, and the search paid for a full second
        // domain's training before a deferred Vision pass finally accepted
        // the very gallery this check should have confirmed immediately.
        $draft = new ProductDraft([
            'telegram_update_id' => 456,
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]));
        $matcher = new ProductIdentityMatcher;

        // The exact condition this covers, not a generalization: filtered
        // down to only what the operator actually typed, there is nothing
        // left to require.
        $this->assertFalse($matcher->requiresExactIdentifier($draft));

        $this->assertTrue($matcher->supportsSource($draft, [
            'title' => 'Lenovo LOQ 15IRX10 83JE0013US 15.6" Gaming Laptop',
            'url' => 'https://www.excaliberpc.com/818372/lenovo-loq-15irx10-83je0013us-15.6.html',
        ]));
        // Verification, not blind trust: a page that does not publish the
        // resolved SKU at all still cannot be confirmed by it.
        $this->assertFalse($matcher->supportsSource($draft, [
            'title' => 'Gaming Laptop Deals This Week',
            'url' => 'https://blog.example/best-gaming-laptop-deals',
        ]));
    }

    public function test_an_agent_resolved_sku_still_cannot_reject_a_different_regional_card(): void
    {
        // The other half of the same fix: widening confirmation to the
        // draft's own resolved identifiers must not also widen rejection.
        // requiresExactIdentifier()/conflicts() keep using only what the
        // operator actually typed, so an agent-inferred SKU here (regional,
        // possibly not the only valid one) still cannot reject a page
        // publishing a different one - the exact bug the surrounding
        // requestedIdentifiers() comment already protects against.
        $draft = new ProductDraft([
            'telegram_update_id' => 457,
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]));
        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->conflicts(
            $draft,
            'https://shop.example/lenovo-loq-15irx10-83je002kus-different-region.html',
        ));
    }

    public function test_a_shared_chassis_code_alone_is_plausible_but_not_an_exact_identifier_match(): void
    {
        // The concern raised after the 2026-09-10 live run: multiple SKUs of
        // one laptop line share a chassis code ("LOQ 15IRX10"), so a page
        // naming only that code - not the specific SKU - could otherwise
        // grant the same wholesale-gallery trust as an exact match, even
        // though it may describe a different RAM/GPU/storage configuration.
        $draft = new ProductDraft([
            'telegram_update_id' => 458,
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]));
        $matcher = new ProductIdentityMatcher;

        $exactSource = [
            'title' => 'Lenovo LOQ 15IRX10 83JE0013US 15.6" Gaming Laptop',
            'url' => 'https://www.excaliberpc.com/818372/lenovo-loq-15irx10-83je0013us-15.6.html',
        ];
        $chassisOnlySource = [
            'title' => 'Lenovo LOQ 15IRX10 Gaming Laptop - Other Configuration',
            'url' => 'https://shop.example/lenovo-loq-15irx10-16gb-rtx4050',
        ];

        // The exact SKU is present verbatim: this is the cheap, no-judge path.
        $this->assertTrue($matcher->confirmsExactIdentifier($draft, $exactSource));
        // The chassis code alone is still a plausible lead (supportsSource
        // stays true, used for ranking/fallback) ...
        $this->assertTrue($matcher->supportsSource($draft, $chassisOnlySource));
        // ... but it must not count as an exact, judge-free confirmation of
        // this specific configuration.
        $this->assertFalse($matcher->confirmsExactIdentifier($draft, $chassisOnlySource));
    }

    public function test_a_matching_model_does_not_confirm_a_page_naming_a_different_sku(): void
    {
        // Real reproduction (2026-09-11): model and sku live in separate
        // draft fields, unlike the embedded-in-one-string case above. A page
        // sharing the model verbatim but naming a different real sku is a
        // different configuration, not a confirmed one - model must not
        // stand in for sku once a dedicated identifier exists to disagree
        // with it.
        $draft = new ProductDraft([
            'telegram_update_id' => 459,
            'title' => 'Lenovo LOQ 15IRX10 - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]));
        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->confirmsExactIdentifier($draft, [
            'title' => 'Lenovo LOQ 15IRX10 - SKU 83JE0099US',
            'url' => 'https://shop.example/loq-15irx10-83je0099us',
        ]));
        $this->assertTrue($matcher->confirmsExactIdentifier($draft, [
            'title' => 'Lenovo LOQ 15IRX10 - SKU 83JE0013US',
            'url' => 'https://shop.example/loq-15irx10-83je0013us',
        ]));
    }

    public function test_supports_a_raw_url_requires_the_dedicated_sku_not_the_shared_model_word(): void
    {
        // The word-level sibling of the two tests above: supports() takes a
        // bare asset URL (no page title/text), so its strong-token pool
        // cannot be one compacted whole string the way confirmsExactIdentifier()'s
        // can - a real image URL keeps hyphens between words that page text
        // does not. "15irx10" is still a strong token by the letter+digit
        // test, but once a dedicated sku exists it must not stand in for it.
        $draft = new ProductDraft([
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->supports(
            $draft,
            'https://shop.example/loq-15irx10-other-config.jpg',
        ));
        $this->assertTrue($matcher->supports(
            $draft,
            'https://shop.example/loq-15irx10-83je0013us.jpg',
        ));
    }

    public function test_a_different_sku_is_not_rescued_by_its_own_family_words_once_a_strong_check_exists(): void
    {
        // Real reproduction (2026-09-11): the strong-token check correctly
        // fails on a genuinely different sku, but the old code then fell
        // through unconditionally to the weak ">= 3 words" rule - and "loq",
        // "15irx10", "luna", "grey" alone satisfied it without the sku ever
        // being part of the count. Once the draft has a specific code to
        // check, that check must be the whole answer for supports() too, the
        // same way confirmsExactIdentifier() already treats it.
        $draft = new ProductDraft([
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'color' => 'Luna Grey',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->supports(
            $draft,
            'https://example.com/loq-15irx10-luna-grey-83je0099us.jpg',
        ));
        $this->assertTrue($matcher->supports(
            $draft,
            'https://example.com/loq-15irx10-luna-grey-83je0013us.jpg',
        ));
    }

    public function test_a_longer_different_sku_does_not_confirm_the_requested_one_as_its_prefix(): void
    {
        // Real reproduction (2026-09-11): 83JE0013USX is a different, real,
        // longer sku that happens to start with the requested one. A plain
        // str_contains() on two fully compact()-ed strings cannot tell a
        // whole match from the first few characters of a longer code, since
        // compacting strips the very separators that would have marked
        // where one code ends.
        $draft = new ProductDraft([
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->confirmsExactIdentifier($draft, [
            'title' => 'Some Other Configuration 83JE0013USX',
            'url' => 'https://shop.example/83je0013usx',
        ]));
        $this->assertTrue($matcher->confirmsExactIdentifier($draft, [
            'title' => 'Some Other Configuration 83JE0013US',
            'url' => 'https://shop.example/83je0013us',
        ]));
    }

    public function test_supports_does_not_confirm_a_longer_different_sku_as_its_prefix_either(): void
    {
        // The word-level sibling of the confirmsExactIdentifier() test above:
        // supports() had its own, separate str_contains() call and needed
        // the same boundary check independently. Real reproduction
        // (2026-09-11): the strict check above already correctly rejects
        // this url, but supports() still returned true and could outvote
        // Vision's own exact_match=false through the source_supported
        // OR-gate.
        $draft = new ProductDraft([
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->supports(
            $draft,
            'https://shop.example/loq-15irx10-83je0013usx.jpg',
        ));
        $this->assertTrue($matcher->supports(
            $draft,
            'https://shop.example/loq-15irx10-83je0013us.jpg',
        ));
    }

    public function test_supports_requires_every_strong_word_of_a_multi_part_model_not_just_one(): void
    {
        // Real reproduction (2026-09-11): no dedicated sku, so the model
        // itself is the strong-token pool - but it splits into two
        // independently strong words ("x1504va", "bq4485"), and the old
        // contains() (any one word) let the shared "x1504va" chassis word
        // alone confirm a url whose own suffix ("bq9999") openly named a
        // different configuration than the requested "bq4485".
        $draft = new ProductDraft([
            'title' => 'ASUS Vivobook 15',
            'brand' => 'ASUS',
            'model' => 'X1504VA-BQ4485',
        ]);
        $matcher = new ProductIdentityMatcher;

        $this->assertFalse($matcher->supports(
            $draft,
            'https://shop.example/asus-vivobook-15-x1504va-bq9999.jpg',
        ));
        $this->assertTrue($matcher->supports(
            $draft,
            'https://93.184.216.34/products/asus-vivobook-15-x1504va-bq4485',
        ));
    }
}
