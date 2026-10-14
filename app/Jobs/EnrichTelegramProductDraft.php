<?php

namespace App\Jobs;

use App\Ai\Tools\SearchProductTextEvidence;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDraftEditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Tools\Request;
use Throwable;

class EnrichTelegramProductDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $draftId, public int $updateId, public int $generation, public ?string $interactionToken = null)
    {
        $this->onQueue('assistant');
    }

    public function handle(TelegramClient $telegram, DraftTelegramPresenter $presenter, TelegramDraftEditor $editor): void
    {
        $update = TelegramUpdate::findOrFail($this->updateId);
        try {
            if ($update->processed_at || $update->status === 'cancelled') {
                return;
            }
            $draft = ProductDraft::findOrFail($this->draftId);
            if ($update->cancel_requested_at || ! $draft->isManualUpload() || $draft->status !== 'pending_review'
                || (int) $draft->telegram_update_id !== $this->generation
                || ! app(DraftAccess::class)->belongsToContext($draft, $update)) {
                $update->update(['status' => $update->cancel_requested_at ? 'cancelled' : 'ignored', 'processed_at' => now()]);

                return;
            }
            $update->update(['status' => 'processing']);
            throw_if(app(ProductSearchCostBudget::class)->exceeded($update->id), \RuntimeException::class,
                'Денежный бюджет дополнительного исследования исчерпан.');
            $inputFingerprint = $this->inputFingerprint($draft);
            $telegram->sendMessage((string) $update->chat_id, '🌐 Ищу дополнительные характеристики. Ваши фото, цена и введённые данные сохраняются.',
                ['inline_keyboard' => [[['text' => 'Отменить исследование', 'callback_data' => "search:cancel:{$update->id}"]]]]);
            $result = json_decode((string) (new SearchProductTextEvidence($draft->id, $update->id, enforceSearchBudget: true))->handle(new Request([
                'question' => 'Find additional supported specifications for this exact configuration. Do not change known facts or infer commercial condition, price, stock or optional installed equipment. Report conflicts and unknowns.',
                'target_offer' => 'current_offer',
            ])), true, 512, JSON_THROW_ON_ERROR);
            $draft->refresh();
            if ($update->fresh()->cancel_requested_at) {
                $update->update(['status' => 'cancelled', 'processed_at' => now()]);

                return;
            }
            if ($draft->status !== 'pending_review'
                || ! hash_equals($inputFingerprint, $this->inputFingerprint($draft))) {
                $update->update(['status' => 'ignored', 'processed_at' => now()]);
                $telegram->sendMessage((string) $update->chat_id, 'Карточка изменилась во время поиска. Найденные характеристики к ней не применены.');

                return;
            }
            $specs = $draft->specifications ?? [];
            $known = collect($specs)->map(fn ($spec): string => mb_strtolower($spec['key'] ?? $spec['name'] ?? ''))->all();
            foreach ($result['facts'] ?? [] as $fact) {
                $key = mb_strtolower($fact['key'] ?? $fact['name'] ?? '');
                if (($fact['scope'] ?? '') !== 'exact_configuration' || in_array($key, $known, true)) {
                    continue;
                }
                $specs[] = $fact;
                $known[] = $key;
            }
            if (count($specs) === count($draft->specifications ?? [])) {
                $telegram->sendMessage((string) $update->chat_id,
                    'Новых однозначно применимых характеристик не найдено. Карточка сохранена.'
                    ."\n".mb_substr($result['summary'] ?? $result['reason'] ?? '', 0, 1500));
                $presenter->sendControls($telegram, (string) $update->chat_id, $draft);
            } else {
                $proposal = $editor->proposalFor($draft, $update, ['fields' => ['specifications' => $specs], 'commercial' => []],
                    'Добавить характеристики из интернета с сохранёнными ссылками на источники.');
                (new ProposeTelegramDraftEdit($draft->id, $update->id, $this->generation, '', $this->interactionToken))
                    ->presentProposal($draft, $update, $proposal, $telegram, $presenter);
            }
            $update->update(['status' => 'completed', 'processed_at' => now()]);
        } finally {
            $this->clearQueuedMarker();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->clearQueuedMarker();
        $update = TelegramUpdate::find($this->updateId);
        $update?->update(['status' => 'failed', 'processed_at' => now(), 'error' => mb_substr($exception?->getMessage() ?? '', 0, 1000)]);
        if ($update && ! $update->cancel_requested_at) {
            app(TelegramClient::class)->sendMessage((string) $update->chat_id, 'Дополнительный поиск не завершён. Исходная карточка и фотографии сохранены.');
        }
    }

    private function inputFingerprint(ProductDraft $draft): string
    {
        return hash('sha256', json_encode([
            'draft' => $draft->only(['status', 'telegram_update_id', 'title', 'brand', 'model', 'color', 'condition', 'description', 'specifications', 'translations']),
            'intake' => $draft->manualIntake(),
            'media' => $draft->media()->get(['id', 'checksum', 'path'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    private function clearQueuedMarker(): void
    {
        $key = "draft-enrich:{$this->draftId}:queued";
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
