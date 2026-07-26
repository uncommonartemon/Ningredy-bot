<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Jobs\ProcessDraftPhotoActions;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class ManageDraftPhotos implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Queue one or several photo operations for a pending product draft. Positions are 1-based album positions. '
            .'Supports mixed commands in one request: enhance, replace with a different non-duplicate internet photo, and delete. '
            .'Resolve every position against the original album before processing, so mixed operations are safe.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draftId = $request->integer('draft_id');
        $requested = [
            'enhance' => $request->array('enhance_positions'),
            'replace' => $request->array('replace_positions'),
            'delete' => $request->array('delete_positions'),
        ];
        $draft = ProductDraft::query()->with('media')->find($draftId);
        throw_if(! $draft, ModelNotFoundException::class, 'Draft not found.');
        throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Draft is no longer pending review.');
        $ordered = $draft->media->sortBy([
            ['is_primary', 'desc'],
            ['sort_order', 'asc'],
        ])->values();
        $actions = [];

        foreach ($requested as $action => $positions) {
            foreach (array_values(array_unique(array_map('intval', $positions))) as $position) {
                $media = $ordered->get($position - 1);
                throw_unless($media, RuntimeException::class, "Photo position {$position} does not exist.");
                $actions[] = ['action' => $action, 'media_id' => $media->id, 'position' => $position];
            }
        }

        throw_if($actions === [], RuntimeException::class, 'No photo operations were provided.');
        $queuedKey = "draft-photo-actions:{$draft->id}:queued";
        throw_unless(Cache::add($queuedKey, $this->update->id, now()->addMinutes(12)), RuntimeException::class, 'Draft photos are already being processed.');

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'manage_draft_photos',
            ['draft_id' => $draft->id, 'actions' => $actions],
            function () use ($draft, $actions): array {
                ProcessDraftPhotoActions::dispatch(
                    $draft->id,
                    $actions,
                    $this->update->id,
                    (string) $this->update->chat_id,
                );

                return ['ok' => true, 'queued' => true, 'draft_id' => $draft->id, 'actions' => $actions];
            },
            ProductDraft::class,
            $draft->id,
        );

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->required(),
            'enhance_positions' => $schema->array()->items($schema->integer()),
            'replace_positions' => $schema->array()->items($schema->integer()),
            'delete_positions' => $schema->array()->items($schema->integer()),
        ];
    }
}
