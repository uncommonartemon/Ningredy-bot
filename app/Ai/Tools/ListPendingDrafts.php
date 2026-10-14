<?php

namespace App\Ai\Tools;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListPendingDrafts implements Tool
{
    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'List product drafts waiting to be added to the catalog.';
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = min(max($request->integer('limit', 10), 1), 20);
        $drafts = ProductDraft::query()->where('status', 'pending_review')
            ->where('requested_by_telegram_user_id', $this->update->telegram_user_id)
            ->whereHas('telegramUpdate', fn ($query) => $query->where('chat_id', $this->update->chat_id))
            ->latest('id')->limit($limit)->get([
                'id', 'title', 'brand', 'model', 'color', 'confidence', 'created_at',
            ]);

        return json_encode(['count' => $drafts->count(), 'drafts' => $drafts], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()];
    }
}
