<?php

namespace App\Services\GalleryRecipes;

use App\Exceptions\InvalidGalleryRecipeException;

/** Portability checks and removal of facts belonging only to the training page. */
class RecipePortability
{
    /**
     * Every selector the recipe will run, wherever it is stored.
     *
     * @param  array<string, mixed>  $recipe
     * @return array<int, string>
     */
    private function selectorsIn(array $recipe): array
    {
        $selectors = [];

        foreach (['active_image_selector', 'position_selector', 'gallery_scope_selector'] as $key) {
            if (is_string($recipe[$key] ?? null) && $recipe[$key] !== '') {
                $selectors[] = $recipe[$key];
            }
        }

        foreach (['collect_selectors', 'exclude_selectors', 'thumbnail_selectors', 'next_selectors', 'pre_click_selectors', 'open_selectors', 'frame_selectors'] as $key) {
            foreach (is_array($recipe[$key] ?? null) ? $recipe[$key] : [] as $selector) {
                if (is_string($selector)) {
                    $selectors[] = $selector;
                }
            }
        }

        foreach (is_array($recipe['actions'] ?? null) ? $recipe['actions'] : [] as $action) {
            foreach ($action['frame_selectors'] ?? [] as $selector) {
                if (is_string($selector)) {
                    $selectors[] = $selector;
                }
            }
            foreach (['selector', 'after_each_selector'] as $key) {
                if (is_string($action[$key] ?? null)) {
                    $selectors[] = $action[$key];
                }
            }
        }

        return $selectors;
    }

    /**
     * The words a phrase is made of, for comparing one against another.
     *
     * @return array<int, string>
     */
    private function significantWords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 3,
        )));
    }

    /**
     * A recipe is how to open a gallery, so it must not name the product.
     *
     * Live on dell.com: a page held two colourways in one gallery block, and
     * the agent separated them the only way it saw - by putting the product's
     * own name inside the selector:
     *
     *     button[aria-label*="Notebook Dell 14 Premium con touch-screen"]
     *
     * That matches on exactly one page of one shop in one language. Every
     * other Dell laptop trains a second recipe beside it, and the domain
     * accumulates a recipe per product instead of one that opens the site.
     * The page already marked the right group structurally - aria-checked on
     * the selected swatch - which is the same fact expressed in a way that
     * survives the next product.
     *
     * Detected by comparing against the page's own title rather than against
     * any list of shops or models: a literal inside a selector that shares
     * three or more words with the product's title is describing this product,
     * whatever the shop or the language.
     *
     * @param  array<string, mixed>  $recipe
     */
    public function rejectProductSpecificSelectors(array $recipe, string $productPageTitle): void
    {
        $titleWords = $this->significantWords($productPageTitle);

        if (count($titleWords) < 3) {
            return;
        }

        foreach ($this->selectorsIn($recipe) as $selector) {
            // Quotes are paired by kind rather than by proximity. Matching any
            // quote to any other let a short literal earlier in the selector
            // swallow the opening quote of the long one after it, so
            // [aria-label^="Thumbnail "]:not([aria-label*="<the product>"])
            // read as one harmless fragment and the name inside the exclusion
            // was never seen.
            preg_match_all('/"([^"]*)"|\'([^\']*)\'/u', $selector, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $literal = $match[2] ?? '';
                $literal = $literal !== '' ? $literal : ($match[1] ?? '');

                if (mb_strlen($literal) < 12) {
                    continue;
                }

                $literalWords = $this->significantWords($literal);
                $shared = array_intersect($literalWords, $titleWords);

                if (count($literalWords) >= 3 && count($shared) >= 3) {
                    throw new InvalidGalleryRecipeException(
                        'Селектор описывает конкретный товар, а не устройство галереи: "'.mb_substr($literal, 0, 80).'".',
                        'The selector '.mb_substr($selector, 0, 200).' matches on this product\'s own name ('
                            .implode(', ', array_slice($shared, 0, 5)).'), which exists on this page and no other. '
                            .'A recipe is how to open and walk this shop\'s gallery, so it must survive the next '
                            .'product. When a gallery block holds more than one variant, select the active group by '
                            .'the state the page itself marks it with - aria-checked="true", aria-selected="true", '
                            .'[data-group] on the chosen swatch, a class the page adds to the selected group - and '
                            .'never by the words naming the product.',
                        ['selector_names_the_product'],
                    );
                }
            }
        }
    }

    /**
     * The recipe as it leaves training, with what it counted left behind.
     *
     * expected_image_count is the agent saying "this page shows seven photos".
     * That is true, useful, and checked - on the page it was learned on. It is
     * meaningless on the next product, and the validator has to be told so
     * through a boolean at every call site. It was told wrong once already, and
     * a recipe that carries a number is a recipe someone will read the number
     * from.
     *
     * So the domain's stored recipe does not carry it at all. The version row
     * still does - that is the audit trail of what the agent believed while it
     * was learning, and it is not what later products are opened with.
     *
     * The traversal bounds stay. They are ceilings rather than targets - see
     * traversalCeiling() in the browser runner - and zero among them is a
     * structural statement ("this gallery has no carousel") that must survive.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function withoutTrainingCounts(array $candidate): array
    {
        unset($candidate['expected_image_count'], $candidate['recipe_scope'], $candidate['scope_reason']);

        return $candidate;
    }
}
