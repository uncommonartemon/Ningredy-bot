<?php

namespace App\Ai\Tools;

use App\Jobs\ProposeTelegramDraftEdit;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftQueuedWork;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDraftEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The operator corrects a pending draft in words - a field, a characteristic
 * the bot missed or got wrong, the condition - and the bot prepares exactly
 * those changes (module 72). It is the draft's own editor with its signed
 * preview and confirmation button: nothing changes until the operator presses it.
 */
class EditProductDraft implements Tool
{
    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Change a pending product draft by the operator\'s words: a field (title, brand, model, colour, condition, description, category, product type), '
            .'a characteristic to add, correct or remove, the price, quantity or warranty of a manually uploaded product. Pass their request as instruction, verbatim. '
            .'The bot works out the exact changes, shows them in the chat with a confirmation button and returns status=confirmation_required: '
            .'nothing changes until the operator presses it. Never change a draft\'s fields or characteristics through the database tools.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draft = ProductDraft::query()->find($request->integer('draft_id'));
        $instruction = trim((string) $request->string('instruction'));
        if ($draft === null) {
            return $this->json(['ok' => false, 'error' => 'Draft not found.']);
        }
        if (! app(DraftAccess::class)->belongsToContext($draft, $this->update)) {
            return $this->json(['ok' => false, 'error' => 'Draft belongs to another operator or chat.']);
        }
        if ($draft->status !== 'pending_review') {
            return $this->json(['ok' => false, 'draft_id' => $draft->id, 'product_id' => $draft->approved_product_id,
                'error' => "Draft already processed: {$draft->status}. A product in the catalog is changed with the product tools."]);
        }
        if ($instruction === '') {
            return $this->json(['ok' => false, 'draft_id' => $draft->id, 'error' => 'instruction is required: the operator\'s words about the change.']);
        }
        if (DraftQueuedWork::has($draft->id)) {
            return $this->json(['ok' => false, 'draft_id' => $draft->id,
                'error' => 'The draft is being processed right now; its result arrives by itself - ask for the change after that.']);
        }
        $marker = "draft-fields:{$draft->id}:queued";
        Cache::put($marker, $this->update->id, 240);
        try {
            $proposal = app(TelegramDraftEditor::class)->propose($draft, $this->update, $instruction);
            (new ProposeTelegramDraftEdit($draft->id, $this->update->id, (int) $draft->telegram_update_id, $instruction))
                ->presentProposal($draft, $this->update, $proposal, app(TelegramClient::class), app(DraftTelegramPresenter::class));

            return $this->json(['ok' => true, 'draft_id' => $draft->id, 'status' => 'confirmation_required',
                'summary' => (string) ($proposal['summary'] ?? ''), 'preview' => (string) ($proposal['preview'] ?? '')]);
        } catch (Throwable $exception) {
            return $this->json(['ok' => false, 'draft_id' => $draft->id, 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
        } finally {
            if (Cache::get($marker) === $this->update->id) {
                Cache::forget($marker);
            }
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->required(),
            'instruction' => $schema->string()->max(15000)->required(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
