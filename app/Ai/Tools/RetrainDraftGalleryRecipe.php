<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class RetrainDraftGalleryRecipe implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Retrain the safe Playwright gallery recipe for the primary source of a pending product draft. '
            .'Use when the user says the source gallery is incomplete, low-resolution, duplicated, or asks to improve/retrain the source recipe. '
            .'If the user describes a concrete problem they noticed (wrong model mixed in, wrong color picked up, a specific element to avoid, ...), pass it as hint. '
            .'The existing recipe and photos remain until the AI-generated candidate recipe passes a live comparison.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draft = ProductDraft::query()->find($request->integer('draft_id'));
        throw_unless($draft, RuntimeException::class, 'Draft not found.');
        throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Draft is no longer pending review.');
        throw_if(blank($draft->primary_source_url), RuntimeException::class, 'Draft has no primary product page URL.');

        $hint = trim((string) $request->string('hint'));
        $hint = $hint === '' ? null : mb_substr($hint, 0, 1000);

        $queuedKey = "draft-gallery-retrain:{$draft->id}:queued";
        throw_unless(
            Cache::add($queuedKey, $this->update->id, now()->addMinutes(10)),
            RuntimeException::class,
            'This source is already being retrained.',
        );

        try {
            $result = $this->recordOperation(
                $this->update,
                class_basename(self::class),
                'retrain_draft_gallery_recipe',
                ['draft_id' => $draft->id, 'url' => $draft->primary_source_url, 'hint' => $hint],
                function () use ($draft, $hint): array {
                    TrainDraftGalleryRecipe::dispatch(
                        $draft->id,
                        $this->update->id,
                        (string) $this->update->chat_id,
                        $hint,
                    );

                    return ['ok' => true, 'queued' => true, 'draft_id' => $draft->id];
                },
                ProductDraft::class,
                $draft->id,
            );
        } catch (\Throwable $exception) {
            Cache::forget($queuedKey);

            throw $exception;
        }

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->required(),
            'hint' => $schema->string()->description(
                'Optional concrete note from the user about what is wrong with the source/gallery (e.g. "mixes photos of different models", "picks the wrong color variant").',
            ),
        ];
    }
}
