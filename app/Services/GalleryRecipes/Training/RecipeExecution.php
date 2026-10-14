<?php

namespace App\Services\GalleryRecipes\Training;

use App\Exceptions\ProductSearchCancelled;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\ProductSearch\Recovery\RecoveryContext;
use Throwable;

/** A failed browser operation becomes an observation, never a successful empty gallery. */
final class RecipeExecution
{
    public function run(BrowserProductGalleryExtractor $browser, string $url, array $recipe, ?callable $debug, ?int $updateId, array $context): array
    {
        app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
        try {
            // The trainer checks cancellation after assigning this result, so
            // bytes/URLs returned concurrently with cancellation are retained.
            return $browser->executeRecipe($url, $recipe, 20, $debug, $updateId, $context);
        } catch (ProductSearchCancelled $error) {
            throw $error;
        } catch (Throwable $error) {
            app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
            $message = mb_substr($error->getMessage(), 0, 2000);
            $recovery = new RecoveryContext('recipe_execution', 'execution_interrupted', $message,
                ['ReadGalleryPageObservation', 'GetCandidateRejectionDetail', 'propose_recipe', 'abandon_page'],
                ['attempted_recipe' => $recipe], ['exception_type' => $error::class]);

            // Do not label an older browser snapshot as the result of this run.
            // The trainer owns and retains its last actual observation/photos.
            return ['images' => [], 'error' => $message, 'failure_kind' => 'execution_interrupted',
                'diagnostics' => ['partial' => true], 'recovery' => $recovery->toArray()];
        }
    }
}
