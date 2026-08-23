<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\TelegramUpdate;
use App\Services\Products\GalleryTrainingAbandonSignal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class AbandonGalleryTrainingAttempt implements Tool
{
    use RecordsOperations;

    public function __construct(
        private readonly ProductGalleryRecipeVersion $version,
        private readonly ProductGalleryRecipe $recipe,
        private readonly string $url,
        private readonly ?TelegramUpdate $update,
        private readonly GalleryTrainingAbandonSignal $signal,
    ) {}

    public function description(): Stringable|string
    {
        return 'Deliberately end this training session for the current URL when you have concrete evidence '
            .'(from GetSourceAttemptHistory, GetCandidateRejectionDetail, GetRecipeHealth, or repeated identical '
            .'failures already visible in attempt_history) that further rounds will not succeed - e.g. a '
            .'download-layer problem no recipe change can fix, or materially different approaches already tried '
            .'without progress. This ends the whole training session for this URL, not just the current round - '
            .'use only when confident, never as a first resort, and never merely because one round failed.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reason = trim((string) $request->string('reason'));
        throw_if($reason === '', RuntimeException::class, 'reason is required.');
        $reason = mb_substr($reason, 0, 1000);
        $failureKind = (string) $request->string('failure_kind', 'agent_abandoned');
        $failureKind = in_array($failureKind, ['agent_abandoned', 'recipe_mismatch', 'dom_unusable'], true)
            ? $failureKind
            : 'agent_abandoned';

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'abandon_training_attempt',
            ['url' => $this->url, 'reason' => $reason, 'failure_kind' => $failureKind],
            function () use ($reason, $failureKind): array {
                $this->signal->abandoned = true;
                $this->signal->reason = $reason;
                $this->signal->failureKind = $failureKind;

                return ['abandoned' => true];
            },
            ProductGalleryRecipeVersion::class,
            $this->version->id,
        );

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->max(1000)->required()->description(
                'Concrete evidence for why this URL cannot be trained further this session.',
            ),
            'failure_kind' => $schema->string()->enum(['agent_abandoned', 'recipe_mismatch', 'dom_unusable'])
                ->description(
                    'Defaults to agent_abandoned. Only override with recipe_mismatch/dom_unusable if that is a '
                    .'more precise match for the evidence.',
                ),
        ];
    }
}
