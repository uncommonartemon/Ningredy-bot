<?php

namespace App\Services\Products;

/**
 * A tool (AbandonGalleryTrainingAttempt) is constructed deep inside
 * ProductGalleryRecipeTrainerAgent::tools() and has no other way to tell
 * ProductGalleryRecipeTrainer::train()'s round loop that the model decided
 * to stop - train() creates one instance per round, passes it to the agent,
 * and the tool mutates it in place when invoked. The loop then defers to
 * the SAME recordFailure() call every other stuck-loop breaker already
 * uses, rather than the tool recording the failure itself and risking a
 * double count.
 */
class GalleryTrainingAbandonSignal
{
    public bool $abandoned = false;

    public ?string $reason = null;

    public ?string $failureKind = null;
}
