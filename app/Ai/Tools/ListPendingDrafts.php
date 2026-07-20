<?php

namespace App\Ai\Tools;

use App\Models\ProductDraft;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListPendingDrafts implements Tool
{
    public function description(): Stringable|string
    {
        return 'List product drafts waiting to be added to the catalog.';
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = min(max($request->integer('limit', 10), 1), 20);
        $drafts = ProductDraft::query()->where('status', 'pending_review')->latest('id')->limit($limit)->get([
            'id', 'title', 'brand', 'model', 'color', 'confidence', 'created_at',
        ]);

        return json_encode(['count' => $drafts->count(), 'drafts' => $drafts], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()];
    }
}
