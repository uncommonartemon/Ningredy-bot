<?php

namespace Tests\Unit;

use App\Exceptions\InvalidGalleryRecipeException;
use App\Services\Products\ProductGalleryRecipeTrainer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Live on dell.com: one gallery block held two colourways, and the agent
 * separated them the only way it saw - by putting the product's own name in
 * the selector:
 *
 *     button[aria-label*="Notebook Dell 14 Premium con touch-screen"]
 *
 * It worked, on exactly one page of one shop in one language. Every other Dell
 * laptop would have trained a recipe of its own beside it, and the domain would
 * hold a recipe per product rather than one that opens the site. The page had
 * already marked the right group with aria-checked; that is the same fact in a
 * form that survives the next product.
 */
class RecipeMustNotNameTheProductTest extends TestCase
{
    private const DELL_TITLE = 'Notebook Dell 14 Premium da 14 pollici | Dell Italia';

    public function test_a_selector_built_from_the_product_name_is_rejected(): void
    {
        $this->expectException(InvalidGalleryRecipeException::class);

        $this->guard([
            'actions' => [[
                'selector' => 'button[aria-label*="Notebook Dell 14 Premium con touch-screen"]',
            ]],
        ], self::DELL_TITLE);
    }

    public function test_hiding_the_name_in_an_exclusion_does_not_help(): void
    {
        $this->expectException(InvalidGalleryRecipeException::class);

        $this->guard([
            'exclude_selectors' => [
                'button[aria-label^="Thumbnail "]:not([aria-label*="Notebook Dell 14 Premium touch"])',
            ],
        ], self::DELL_TITLE);
    }

    public function test_the_rejection_tells_the_agent_what_to_use_instead(): void
    {
        try {
            $this->guard([
                'collect_selectors' => ['img[alt="Notebook Dell 14 Premium touchscreen fronte"]'],
            ], self::DELL_TITLE);
            $this->fail('The selector names the product and should have been rejected.');
        } catch (InvalidGalleryRecipeException $exception) {
            $this->assertStringContainsString('aria-checked', $exception->englishMessage);
            $this->assertStringContainsString('data-group', $exception->englishMessage);
            $this->assertSame(['selector_names_the_product'], $exception->ruleSignature);
        }
    }

    public function test_the_structural_answer_passes(): void
    {
        // What the page itself marks, rather than what the product is called.
        $this->guard([
            'collect_selectors' => ['[data-group][aria-checked="true"] figure[id^="mgal-img-"]'],
            'actions' => [['selector' => 'button[aria-label^="Thumbnail "]']],
            'next_selectors' => ['button.next[aria-label="Next images"]'],
        ], self::DELL_TITLE);

        $this->assertTrue(true);
    }

    public function test_an_ordinary_gallery_selector_is_left_alone(): void
    {
        $this->guard([
            'collect_selectors' => [
                'div[data-testid="product-gallery-main-image"] img',
                '.media-gallery figure img',
            ],
        ], 'HP OmniBook 7 16-ay0087nr 16" Touchscreen Notebook - CDW.com');

        $this->assertTrue(true);
    }

    public function test_one_shared_word_is_a_coincidence_not_a_product_name(): void
    {
        // A brand appearing in a control's label is normal markup; it is naming
        // the whole product that makes a selector unusable elsewhere.
        $this->guard([
            'collect_selectors' => ['[aria-label*="Dell gallery viewer"] img'],
        ], self::DELL_TITLE);

        $this->assertTrue(true);
    }

    public function test_a_page_with_no_usable_title_cannot_accuse_anything(): void
    {
        $this->guard([
            'collect_selectors' => ['img[alt="Notebook Dell 14 Premium con touch-screen"]'],
        ], '');

        $this->assertTrue(true);
    }

    /** @param array<string, mixed> $recipe */
    private function guard(array $recipe, string $title): void
    {
        $method = new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'rejectProductSpecificSelectors');
        $method->invoke(app(ProductGalleryRecipeTrainer::class), $recipe, $title);
    }
}
