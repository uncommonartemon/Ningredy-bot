<?php

namespace App\Ai\Tools;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftOverview;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftQueuedWork;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * «Дай / покажи / открой черновик 213»: one draft, not the list (module 72).
 * The operator named the draft, so its card goes out from here the way the
 * «Открыть #N» button sends it; the reply's own draft_id is reserved for the
 * draft of the current search and cannot carry a draft of another turn.
 */
class ShowProductDraft implements Tool
{
    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'One product draft by its number: status, title, codes, characteristics, photos, what is unreconciled or missing, the differences from the request. '
            .'For a pending draft it also re-sends the review card with the photos and buttons to the chat (send_card=true, the default): '
            .'use it whenever the operator asks to see, open, give or show a draft, or asks what is in it. '
            .'After it, leave draft_id null in your answer - the card is already sent - and keep the message to one short line. '
            .'An approved draft answers with product_id: show that product with catalog_results.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draft = ProductDraft::query()->find($request->integer('draft_id'));
        if ($draft === null) {
            return $this->json(['ok' => false, 'error' => 'Draft not found.']);
        }
        if (! app(DraftAccess::class)->belongsToContext($draft, $this->update)) {
            return $this->json(['ok' => false, 'error' => 'Draft belongs to another operator or chat.']);
        }
        $overview = app(ProductDraftOverview::class)->forChat($draft);
        $sendCard = filter_var($request['send_card'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($draft->status !== 'pending_review' || ! $sendCard) {
            return $this->json(['ok' => true, 'card_sent' => false, ...$overview]);
        }
        if (DraftQueuedWork::has($draft->id)) {
            return $this->json(['ok' => true, 'card_sent' => false,
                'busy' => 'Черновик сейчас обрабатывается; его карточка придёт сама, когда работа закончится.', ...$overview]);
        }
        $chat = (string) $this->update->chat_id;
        $user = (string) $this->update->telegram_user_id;
        Cache::forget("telegram-manual-create:{$chat}:{$user}");
        app(DraftTelegramInteractionState::class)->clear($chat, $user);
        $presenter = app(DraftTelegramPresenter::class);
        $telegram = app(TelegramClient::class);
        $presenter->clearForSearchContinuation($telegram, $draft);
        $presenter->sendReview($telegram, $chat, $draft);

        return $this->json(['ok' => true, 'card_sent' => true, ...$overview]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->required(),
            'send_card' => $schema->boolean(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
