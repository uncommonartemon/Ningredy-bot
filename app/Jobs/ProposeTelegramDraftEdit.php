<?php

namespace App\Jobs;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDraftEditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ProposeTelegramDraftEdit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $draftId, public int $updateId, public int $generation, public string $text, public ?string $interactionToken = null)
    {
        $this->onQueue('assistant');
    }

    public function handle(TelegramDraftEditor $editor, TelegramClient $telegram, DraftTelegramPresenter $presenter): void
    {
        $update = TelegramUpdate::findOrFail($this->updateId);
        try {
            if ($update->processed_at || $update->status === 'cancelled') {
                return;
            }
            $draft = ProductDraft::findOrFail($this->draftId);
            if ($update->cancel_requested_at || $draft->status !== 'pending_review'
                || (int) $draft->telegram_update_id !== $this->generation
                || ! app(DraftAccess::class)->belongsToContext($draft, $update)) {
                $update->update(['status' => $update->cancel_requested_at ? 'cancelled' : 'ignored', 'processed_at' => now()]);

                return;
            }
            $update->update(['status' => 'processing']);
            $proposal = $editor->propose($draft, $update, $this->text);
            if ($update->fresh()->cancel_requested_at) {
                $update->update(['status' => 'cancelled', 'processed_at' => now()]);

                return;
            }
            $this->presentProposal($draft, $update, $proposal, $telegram, $presenter);
            $update->update(['status' => 'completed', 'processed_at' => now()]);
        } finally {
            $this->clearQueuedMarker();
        }
    }

    public function presentProposal(ProductDraft $draft, TelegramUpdate $update, array $proposal, TelegramClient $telegram, DraftTelegramPresenter $presenter): void
    {
        if ($update->fresh()->cancel_requested_at || $draft->fresh()->status !== 'pending_review') {
            return;
        }
        $states = app(DraftTelegramInteractionState::class);
        $current = $states->get((string) $update->chat_id, (string) $update->telegram_user_id);
        if ($this->interactionToken !== null && ($current['token'] ?? '') !== $this->interactionToken) {
            $token = Str::random(12);
            Cache::put("draft-edit-proposal:{$update->chat_id}:{$update->telegram_user_id}:{$draft->id}:{$token}", $proposal,
                now()->addSeconds(max(1, (int) $proposal['expires_at'] - now()->getTimestamp())));
            $telegram->sendMessage((string) $update->chat_id, "✏️ Изменения для черновика #{$draft->id} готовы. Откройте их для просмотра и подтверждения.",
                ['inline_keyboard' => [[['text' => 'Открыть изменения', 'callback_data' => "draft:resume-edit:{$draft->id}:{$token}"]]]]);

            return;
        }
        foreach ($proposal['preview_messages'] ?? [] as $chunk) {
            $telegram->sendMessage((string) $update->chat_id, $chunk);
        }
        $state = $states->remember((string) $update->chat_id,
            (string) $update->telegram_user_id, $draft, 'confirmation', [
                'callback' => "draft:apply-edit:{$draft->id}", 'proposal' => $proposal,
            ]);
        $presenter->sendActionConfirmation($telegram, (string) $update->chat_id, $draft,
            $state['token'], 'Применить изменения к черновику?',
            mb_substr((string) ($proposal['preview'] ?? $proposal['summary'] ?? 'Проверьте изменения выше.'), 0, 3000)
                ."\nТовар в каталоге не меняется до отдельного подтверждения сохранения.", 'Применить к черновику');
    }

    public function failed(?Throwable $exception): void
    {
        $this->clearQueuedMarker();
        TelegramUpdate::whereKey($this->updateId)->whereNull('processed_at')->update([
            'status' => 'failed', 'processed_at' => now(), 'error' => mb_substr($exception?->getMessage() ?? 'Edit failed', 0, 1000),
        ]);
        $update = TelegramUpdate::find($this->updateId);
        if ($update && ! $update->cancel_requested_at) {
            app(TelegramClient::class)->sendMessage((string) $update->chat_id,
                'Не удалось подготовить исправление. Исходная карточка сохранена. '.mb_substr($exception?->getMessage() ?? '', 0, 500));
        }
    }

    private function clearQueuedMarker(): void
    {
        $key = "draft-fields:{$this->draftId}:queued";
        if (in_array(Cache::get($key), [true, $this->updateId], true)) {
            Cache::forget($key);
        }
        $update = TelegramUpdate::find($this->updateId);
        if ($update && $this->interactionToken !== null) {
            $states = app(DraftTelegramInteractionState::class);
            $current = $states->get((string) $update->chat_id, (string) $update->telegram_user_id);
            if (($current['kind'] ?? '') === 'processing' && ($current['token'] ?? '') === $this->interactionToken) {
                $states->clear((string) $update->chat_id, (string) $update->telegram_user_id);
            }
        }
    }
}
