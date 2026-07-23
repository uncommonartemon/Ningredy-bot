<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
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
}
