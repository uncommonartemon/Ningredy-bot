<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Jobs\ProcessDraftPhotoActions;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftPhotoSearch;
use App\Services\Telegram\DraftQueuedWork;
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
            .'Supports mixed commands in one request: enhance, remove a watermark (a shop\'s mark, logo or caption laid over the photo; '
            .'the product and its own markings stay), replace with a different non-duplicate internet photo, and delete. '
            .'Resolve every position against the original album before processing, so mixed operations are safe. '
            .'find_more_photos: the operator asks for more photos or to go on searching; a search stopped by a limit or with nothing found continues, a finished one is topped up and keeps its photos. '
            .'find_other_photos: the operator asks for other photos instead of these; the gallery is searched again without its old sources. Both are asynchronous: leave draft_id null, the card comes back by itself.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draftId = $request->integer('draft_id');
        $requested = [
            'enhance' => $request->array('enhance_positions'),
            'unmark' => $request->array('remove_watermark_positions'),
            'replace' => $request->array('replace_positions'),
            'delete' => $request->array('delete_positions'),
        ];
        $draft = ProductDraft::query()->with('media')->find($draftId);
        throw_if(! $draft, ModelNotFoundException::class, 'Draft not found.');
        throw_unless(app(DraftAccess::class)->belongsToContext($draft, $this->update), RuntimeException::class,
            'Draft belongs to a different chat or user.');
        throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Draft is no longer pending review.');
        if ($request->boolean('find_other_photos')) {
            return $this->json($this->searchPhotos($draft, 'restage'));
        }
        if ($request->boolean('find_more_photos')) {
            return $this->json($this->searchPhotos($draft, app(DraftPhotoSearch::class)->wasStopped($draft) ? 'continue-search' : 'findmore'));
        }
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
        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'manage_draft_photos',
            ['draft_id' => $draft->id, 'actions' => $actions],
            function () use ($draft, $actions): array {
                $queuedKey = "draft-photo-actions:{$draft->id}:queued";
                throw_unless(Cache::add($queuedKey, $this->update->id, now()->addMinutes(12)), RuntimeException::class, 'Draft photos are already being processed.');
                try {
                    ProcessDraftPhotoActions::dispatch(
                        $draft->id,
                        $actions,
                        $this->update->id,
                        (string) $this->update->chat_id,
                        expectedDraftTelegramUpdateId: $draft->telegram_update_id,
                    );
                } catch (\Throwable $exception) {
                    if (Cache::get($queuedKey) === $this->update->id) {
                        Cache::forget($queuedKey);
                    }
                    throw $exception;
                }

                return ['ok' => true, 'queued' => true, 'draft_id' => $draft->id, 'actions' => $actions];
            },
            ProductDraft::class,
            $draft->id,
        );

        return $this->json($result);
    }

    /** @return array<string, mixed> */
    private function searchPhotos(ProductDraft $draft, string $button): array
    {
        $origin = $draft->telegramUpdate()->first();
        if (DraftQueuedWork::has($draft->id) || ($origin !== null && $origin->processed_at === null)) {
            return ['ok' => false, 'draft_id' => $draft->id, 'error' => 'A search for this draft is still running; its card comes when it ends.'];
        }

        return $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'search_draft_photos',
            ['draft_id' => $draft->id, 'button' => $button],
            function () use ($draft, $button): array {
                $search = app(DraftPhotoSearch::class);
                $press = $search->pressFor($this->update, "draft:{$button}:{$draft->id}");
                $chatId = (string) $this->update->chat_id;
                $outcome = match ($button) {
                    'continue-search' => $search->continue($draft, $chatId, $press->id),
                    'findmore' => $search->topUp($draft, $chatId, $press->id),
                    default => $search->other($draft, $chatId, $press->id),
                };

                return ['ok' => $outcome === DraftPhotoSearch::STARTED, 'queued' => $outcome === DraftPhotoSearch::STARTED, 'draft_id' => $draft->id,
                    'search' => $button, 'outcome' => $outcome];
            },
            ProductDraft::class,
            $draft->id,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->required(),
            'enhance_positions' => $schema->array()->items($schema->integer()),
            'remove_watermark_positions' => $schema->array()->items($schema->integer()),
            'replace_positions' => $schema->array()->items($schema->integer()),
            'delete_positions' => $schema->array()->items($schema->integer()),
            'find_more_photos' => $schema->boolean(),
            'find_other_photos' => $schema->boolean(),
        ];
    }
}
