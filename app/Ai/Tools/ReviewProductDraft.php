<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class ReviewProductDraft implements Tool
{
    use RecordsOperations;

    public function __construct(
        private readonly TelegramUpdate $update,
        private readonly ProductDraftWorkflow $workflow,
    ) {}

    public function description(): Stringable|string
    {
        return 'Ask the administrator to confirm adding a pending product draft to the catalog or rejecting it, when '
            .'they explicitly name the draft ID and action. Nothing changes until they press the confirmation button '
            .'this sends; the result is status=confirmation_required.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draftId = $request->integer('draft_id');
        $action = (string) $request->string('action');

        return $this->json($this->recordOperation(
            $this->update,
            class_basename(self::class),
            $action === 'approve' ? 'add_draft_to_catalog' : 'reject_product_draft',
            ['draft_id' => $draftId, 'action' => $action],
            function () use ($draftId, $action): array {
                $draft = ProductDraft::query()->find($draftId);
                throw_if(! $draft, ModelNotFoundException::class, 'Product draft not found.');
                throw_unless(app(DraftAccess::class)->belongsToContext($draft, $this->update), RuntimeException::class, 'Draft belongs to another operator or chat.');
                throw_unless($draft->status === 'pending_review', RuntimeException::class, "Draft already processed: {$draft->status}.");

                // Publishing is the operator's decision, so text only ever asks
                // for it: the model reading "каталог" as "add to the catalog"
                // must not publish anything (archived test run, update #11). The
                // confirmation button runs the same draft:add path as the
                // review card's own button, with its readiness checks.
                [$callback, $title, $description, $label] = $action === 'approve'
                    ? ["draft:add:{$draft->id}", 'Добавить в каталог?',
                        'Черновик будет опубликован как товар. Можно вернуться назад без изменений.', 'Да, добавить']
                    : ["draft:reject:{$draft->id}", 'Отклонить черновик?',
                        'Товар не будет добавлен. Можно вернуться назад без изменений.', 'Да, отклонить'];
                $state = app(DraftTelegramInteractionState::class)->remember(
                    (string) $this->update->chat_id, (string) $this->update->telegram_user_id,
                    $draft, 'confirmation', ['callback' => $callback],
                );
                app(DraftTelegramPresenter::class)->sendActionConfirmation(
                    app(TelegramClient::class), (string) $this->update->chat_id,
                    $draft, $state['token'], $title, $description, $label,
                );

                return ['draft_id' => $draft->id, 'status' => 'confirmation_required'];
            },
            ProductDraft::class,
            $draftId,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->required(),
            'action' => $schema->string()->enum(['approve', 'reject'])->required(),
        ];
    }
}
