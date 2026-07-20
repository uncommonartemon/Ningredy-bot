<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
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
        return 'Add a pending product draft to the catalog or reject it when the administrator explicitly names the draft ID and action.';
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
                throw_unless($draft->status === 'pending_review', RuntimeException::class, "Draft already processed: {$draft->status}.");

                if ($action === 'approve') {
                    $product = $this->workflow->approve($draft, telegramReviewerId: $this->update->telegram_user_id);

                    return ['draft_id' => $draft->id, 'product_id' => $product->id, 'active' => $product->is_active];
                }
                $this->workflow->reject($draft, telegramReviewerId: $this->update->telegram_user_id);

                return ['draft_id' => $draft->id, 'status' => 'rejected'];
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
