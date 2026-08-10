<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDraftPhotoActions;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\TopUpDraftGalleryPhotos;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\AiOperation;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductGalleryRecipe;
use App\Models\TelegramChatState;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductSourcePriority;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ProductDraftWorkflow $draftWorkflow,
        private readonly DraftTelegramPresenter $draftPresenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSecret($request);
        $payload = $request->json()->all();
        $updateId = data_get($payload, 'update_id');

        if (! is_int($updateId)) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $callback = data_get($payload, 'callback_query');
        $isCallback = is_array($callback);
        $telegramUserId = data_get($payload, $isCallback ? 'callback_query.from.id' : 'message.from.id');
        $chatId = data_get($payload, $isCallback ? 'callback_query.message.chat.id' : 'message.chat.id');
        $messageId = data_get($payload, $isCallback ? 'callback_query.message.message_id' : 'message.message_id');
        $username = data_get($payload, $isCallback ? 'callback_query.from.username' : 'message.from.username');
        $text = data_get($payload, $isCallback ? 'callback_query.data' : 'message.text');
        $replyToText = $isCallback ? null : (
            data_get($payload, 'message.reply_to_message.text')
            ?? data_get($payload, 'message.reply_to_message.caption')
        );
        $hasVoice = is_array(data_get($payload, 'message.voice'));
        $isAllowed = $this->isAllowed($telegramUserId);

        if (! $isCallback && is_string($text)) {
            $text = $this->stripProgressEcho($text);
        }

        $update = TelegramUpdate::firstOrCreate(
            ['update_id' => $updateId],
            [
                'telegram_user_id' => $telegramUserId === null ? null : (string) $telegramUserId,
                'chat_id' => $chatId === null ? null : (string) $chatId,
                'message_id' => is_int($messageId) ? $messageId : null,
                'username' => $username,
                'text' => is_string($text) ? $text : null,
                'reply_to_text' => is_string($replyToText) ? mb_substr($replyToText, 0, 4096) : null,
                'payload' => $payload,
                'status' => $isAllowed ? 'received' : 'rejected',
            ],
        );

        if (! $update->wasRecentlyCreated) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        if (! $isAllowed) {
            $update->update(['processed_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        if ($isCallback) {
            $this->handleCallback($callback, $update);

            return response()->json(['ok' => true]);
        }

        if ($hasVoice && $chatId !== null) {
            TranscribeTelegramVoice::dispatch($update->id)->afterCommit();

            return response()->json(['ok' => true, 'voice' => true]);
        }

        if (! is_string($text) || trim($text) === '' || $chatId === null) {
            $update->update(['status' => 'ignored', 'processed_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        if ($this->handleCommand(trim($text), (string) $chatId, (string) $telegramUserId, $update)) {
            return response()->json(['ok' => true]);
        }

        if ($this->handlePendingSourceHint(trim($text), (string) $chatId, $update)) {
            return response()->json(['ok' => true]);
        }

        ProcessTelegramMessage::dispatch($update->id)->afterCommit();

        return response()->json(['ok' => true]);
    }

    private function verifyWebhookSecret(Request $request): void
    {
        $raw = (string) config('services.telegram.webhook_secret');
        $configured = preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $raw) === 1 ? $raw : hash('sha256', $raw);
        abort_if($raw === '', 503, 'Telegram webhook is not configured.');
        abort_unless(hash_equals($configured, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
    }

    private function isAllowed(mixed $telegramUserId): bool
    {
        $stored = AppSetting::valueFor(AppSetting::TELEGRAM_ALLOWED_USER_IDS);
        $allowed = $stored !== null
            ? array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $stored) ?: [])))
            : config('services.telegram.allowed_user_ids', []);

        return $allowed !== [] && in_array((string) $telegramUserId, $allowed, true);
    }

    /**
     * Users re-ask a search by copying the bot's live progress line, which
     * carries timer chrome: "⏳ 12с · Acer nitro V16 ... · лимит 300 сек."
     * (TelegramProgressReporter / TelegramProgressHeartbeat). Left as-is the
     * bot searches for the copied junk itself ("1с · 1с · Acer ..."), burning
     * a full rate-limited search on garbage. Strip the leading "Nс ·" timer
     * chunks (repeated, for nested pastes) and the trailing limit note; a
     * real query never starts with "Nс · ".
     */
    private function stripProgressEcho(string $text): string
    {
        $text = preg_replace('/^(?:[⏳❌✅🔎⚠️…]+\s*)?(?:\d+\s*[сc]\s*·\s*(?:[⏳❌✅🔎⚠️…]+\s*)?)+/u', '', trim($text)) ?? $text;
        $text = preg_replace('/\s*·\s*лимит\s+\d+\s*сек\.?\s*$/iu', '', $text) ?? $text;

        return trim($text);
    }

    private function handleCommand(string $text, string $chatId, string $telegramUserId, TelegramUpdate $update): bool
    {
        if (preg_match('/^\/(start|help)(?:@\w+)?$/iu', $text) === 1 || $text === 'ℹ️ Помощь') {
            $this->telegram->sendMessage($chatId, $this->helpText(), $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/new(?:@\w+)?$/iu', $text) === 1) {
            TelegramChatState::query()->where('chat_id', $chatId)->delete();
            $this->telegram->sendMessage($chatId, 'Контекст диалога очищен. Начинаем с чистого листа.', $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/reset(?:@\w+)?$/iu', $text) === 1 || $text === '🔄 Сброс') {
            // A ProcessTelegramMessage job whose update already shows processed_at
            // returns immediately without doing anything (see the job's handle()) -
            // this is enough to neutralize a stuck/queued job even if the worker
            // was down when it was dispatched and only picks it up later.
            $cancelled = TelegramUpdate::query()
                ->where('chat_id', $chatId)
                ->whereNull('processed_at')
                ->where('id', '!=', $update->id)
                ->update(['status' => 'cancelled', 'processed_at' => now()]);
            TelegramChatState::query()->where('chat_id', $chatId)->delete();
            $message = $cancelled > 0
                ? "Сброс выполнен: отменено зависших запросов — {$cancelled}. Контекст диалога очищен. Можно начинать заново."
                : 'Сброс выполнен: зависших запросов не было. Контекст диалога очищен. Можно начинать заново.';
            $this->telegram->sendMessage($chatId, $message, $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/url(?:@\w+)?$/iu', $text) === 1) {
            $publicUrl = rtrim((string) AppSetting::valueFor(
                AppSetting::TELEGRAM_PROXY_URL,
                (string) config('services.telegram.proxy_url'),
            ), '/');
            $message = $publicUrl !== ''
                ? "Текущий публичный адрес:\n{$publicUrl}\n\nКаталог: {$publicUrl}/catalog"
                : 'Публичный ngrok URL пока не установлен.';

            $this->telegram->sendMessage($chatId, $message, $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/drafts(?:@\w+)?$/iu', $text) === 1 || $text === '📋 Черновики') {
            $this->sendDrafts($chatId, $telegramUserId);
            $this->markCommandProcessed($update);

            return true;
        }

        if ($text === '🔎 Найти товар') {
            $this->telegram->sendMessage($chatId, 'Опишите товар. Я сначала проверю каталог, а если совпадения нет — поищу в интернете.', $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        $preset = match (true) {
            preg_match('/^\/status(?:@\w+)?$/iu', $text) === 1, $text === '🖥 Статус' => 'Проверь состояние сервера, очереди и каталога.',
            preg_match('/^\/errors(?:@\w+)?$/iu', $text) === 1, $text === '⚠️ Ошибки' => 'Покажи последние ошибки AI и очереди.',
            $text === '📦 Каталог' => 'Покажи последние активные товары локального каталога.',
            default => null,
        };
        if ($preset !== null) {
            $update->update(['text' => $preset]);

            return false;
        }

        if (preg_match('/^\/find(?:@\w+)?(?:\s+(.*))?$/isu', $text, $matches) === 1) {
            $query = trim($matches[1] ?? '');
            if ($query === '') {
                $this->telegram->sendMessage($chatId, 'После /find укажите товар. Например: /find Lenovo Legion RTX 4070 32 GB.', $this->mainKeyboard());
                $this->markCommandProcessed($update);

                return true;
            }
            $update->update(['text' => $query]);
        }

        return false;
    }

    private function handleCallback(array $callback, TelegramUpdate $update): void
    {
        $callbackId = (string) data_get($callback, 'id');
        $data = (string) data_get($callback, 'data');
        $chatId = (string) data_get($callback, 'message.chat.id');
        $messageId = data_get($callback, 'message.message_id');

        if (preg_match('/^draft:(enhance|replace|delete)-photo:(\d+):(\d+)$/', $data, $photoMatches) === 1) {
            $this->handleDraftEnhancePhoto(
                draftId: (int) $photoMatches[2],
                mediaId: (int) $photoMatches[3],
                action: $photoMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:(enhance|replace|delete):(\d+)$/', $data, $menuMatches) === 1) {
            $this->handleDraftEnhanceMenu(
                draftId: (int) $menuMatches[2],
                action: $menuMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:source:(\d+)$/', $data, $sourceMenuMatches) === 1) {
            $this->handleDraftSourceMenu(
                draftId: (int) $sourceMenuMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        // draft:retrain: is the pre-submenu callback_data - kept working so
        // buttons already sitting on cards sent before this menu existed
        // don't dead-end when clicked.
        if (preg_match('/^draft:(?:source-retrain|retrain):(\d+)$/', $data, $retrainMatches) === 1) {
            $this->handleDraftSourceRetrain(
                draftId: (int) $retrainMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:source-hint:(\d+)$/', $data, $hintMatches) === 1) {
            $this->handleDraftSourceHintPrompt(
                draftId: (int) $hintMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:source-block:(\d+)$/', $data, $blockMatches) === 1) {
            $this->handleDraftSourceBlockPrompt(
                draftId: (int) $blockMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:source-block-(confirm|cancel):(\d+)$/', $data, $blockConfirmMatches) === 1) {
            $this->handleDraftSourceBlockDecision(
                draftId: (int) $blockConfirmMatches[2],
                confirmed: $blockConfirmMatches[1] === 'confirm',
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:restage:(\d+)$/', $data, $restageMatches) === 1) {
            $this->handleDraftRestageGallery(
                draftId: (int) $restageMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:findmore:(\d+)$/', $data, $findMoreMatches) === 1) {
            $this->handleDraftFindMorePhotos(
                draftId: (int) $findMoreMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^search:cancel:(\d+)$/', $data, $cancelMatches) === 1) {
            $this->handleSearchCancel(
                targetUpdateId: (int) $cancelMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                messageId: $messageId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:review:(\d+)$/', $data, $reviewMatches) === 1) {
            $this->handleDraftReview(
                draftId: (int) $reviewMatches[1],
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^product:delete:(confirm|cancel):(\d+)$/', $data, $deleteMatches) === 1) {
            $this->handleProductDeletion(
                action: $deleteMatches[1],
                operationId: (int) $deleteMatches[2],
                callbackId: $callbackId,
                chatId: $chatId,
                messageId: $messageId,
                update: $update,
            );

            return;
        }

        if (preg_match('/^draft:(add|approve|reject):(\d+)$/', $data, $matches) !== 1) {
            $this->telegram->answerCallbackQuery($callbackId, 'Неизвестное действие.');
            $update->update(['status' => 'ignored', 'processed_at' => now()]);

            return;
        }

        $draft = ProductDraft::query()->find((int) $matches[2]);
        if (! $draft) {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден.');
            $update->update(['status' => 'not_found', 'processed_at' => now()]);

            return;
        }
        if ($draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, "Черновик уже обработан: {$draft->status}.");
            $update->update(['status' => 'already_processed', 'processed_at' => now()]);

            return;
        }

        $approved = in_array($matches[1], ['add', 'approve'], true);

        try {
            $product = $approved
                ? $this->draftWorkflow->approve($draft, telegramReviewerId: $update->telegram_user_id)
                : null;
        } catch (\RuntimeException $exception) {
            $this->telegram->answerCallbackQuery($callbackId, mb_substr($exception->getMessage(), 0, 180));
            $this->telegram->sendMessage($chatId, '⚠️ '.$exception->getMessage());
            $update->update(['status' => 'rejected', 'error' => $exception->getMessage(), 'processed_at' => now()]);

            return;
        }

        if (! $approved) {
            $this->draftWorkflow->reject($draft, telegramReviewerId: $update->telegram_user_id);
        }

        $operation = AiOperation::query()->create([
            'telegram_update_id' => $update->id,
            'telegram_user_id' => $update->telegram_user_id,
            'tool' => 'TelegramCallback',
            'action' => $approved ? 'add_draft_to_catalog' : 'reject_product_draft',
            'target_type' => ProductDraft::class,
            'target_id' => $draft->id,
            'payload' => ['draft_id' => $draft->id],
            'result' => ['product_id' => $product?->id],
            'status' => 'completed',
            'executed_at' => now(),
        ]);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $result = $approved
            ? "✅ Товар #{$product->id} добавлен в каталог и активен. Операция #{$operation->id}."
            : "✖ Черновик #{$draft->id} отклонён. Операция #{$operation->id}.";
        $this->telegram->answerCallbackQuery($callbackId, $result);

        if ($chatId !== '' && is_int($messageId)) {
            try {
                $this->telegram->removeInlineKeyboard($chatId, $messageId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        if ($chatId !== '' && ! $approved) {
            $finalized = false;

            try {
                $finalized = $this->draftPresenter->finalizeRejection($this->telegram, $draft);
            } catch (Throwable $exception) {
                report($exception);
            }

            if (! $finalized) {
                $this->telegram->sendMessage($chatId, $result, $this->mainKeyboard());
            }
        }
    }

    private function handleDraftEnhanceMenu(
        int $draftId,
        string $action,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');
            $update->update(['status' => 'not_found', 'processed_at' => now()]);

            return;
        }

        if (! $draft->media()->exists()) {
            $this->telegram->answerCallbackQuery($callbackId, 'В черновике нет доступных фото.');
            $update->update(['status' => 'not_found', 'processed_at' => now()]);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, 'Выберите фотографию.');
        $this->draftPresenter->sendPhotoSelection($this->telegram, $chatId, $draft, $action);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private function handleDraftReview(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');
            $update->update(['status' => 'not_found', 'processed_at' => now()]);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, 'Возвращаю действия черновика.');
        $this->draftPresenter->sendControls($this->telegram, $chatId, $draft);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private function handleDraftEnhancePhoto(
        int $draftId,
        int $mediaId,
        string $action,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);
        $media = $draft?->media()->whereKey($mediaId)->first();

        if (! $draft || $draft->status !== 'pending_review' || ! $media) {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик или фотография уже недоступны.');
            $update->update(['status' => 'not_found', 'processed_at' => now()]);

            return;
        }

        $queuedKey = "draft-photo-actions:{$draft->id}:queued";

        if (! Cache::add($queuedKey, $update->id, now()->addMinutes(12))) {
            $this->telegram->answerCallbackQuery($callbackId, 'Фото этого черновика уже обрабатываются.');
            $update->update(['status' => 'already_processing', 'processed_at' => now()]);

            return;
        }

        $position = $draft->media()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->pluck('id')
            ->search($media->id);
        $position = $position === false ? 1 : $position + 1;
        $labels = ['enhance' => 'Улучшаю', 'replace' => 'Ищу замену для', 'delete' => 'Удаляю'];
        $limits = ['enhance' => 180, 'replace' => 180, 'delete' => 10];
        $operation = AiOperation::query()->create([
            'telegram_update_id' => $update->id,
            'telegram_user_id' => $update->telegram_user_id,
            'tool' => 'TelegramCallback',
            'action' => "{$action}_draft_photo",
            'target_type' => ProductDraftMedia::class,
            'target_id' => $media->id,
            'idempotency_key' => "{$action}-draft-photo:{$update->id}",
            'payload' => ['draft_id' => $draft->id, 'media_id' => $media->id, 'position' => $position],
            'status' => 'running',
        ]);

        try {
            $this->telegram->answerCallbackQuery($callbackId, "Операция поставлена в очередь: фото {$position}.");
            $this->draftPresenter->clearControls($this->telegram, $draft, $chatId);
            $this->telegram->sendMessage(
                $chatId,
                "⏳ {$labels[$action]} фото {$position} черновика #{$draft->id} · лимит {$limits[$action]} сек.",
            );
            ProcessDraftPhotoActions::dispatch(
                $draft->id,
                [['action' => $action, 'media_id' => $media->id, 'position' => $position]],
                $update->id,
                $chatId,
                $operation->id,
            );
            $update->update(['status' => 'completed', 'processed_at' => now()]);
        } catch (Throwable $exception) {
            Cache::forget($queuedKey);
            $operation->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'executed_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function removeCallbackKeyboard(string $chatId, mixed $messageId): void
    {
        if ($chatId === '' || ! is_int($messageId)) {
            return;
        }

        try {
            $this->telegram->removeInlineKeyboard($chatId, $messageId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function handleDraftSourceMenu(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');
            $update->update(['status' => 'not_found', 'processed_at' => now()]);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, 'Выберите действие с источником.');
        $this->draftPresenter->sendSourceMenu($this->telegram, $chatId, $draft);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private function handleDraftSourceRetrain(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
        ?string $hint = null,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');

            return;
        }

        if (blank($draft->primary_source_url)) {
            $this->telegram->answerCallbackQuery($callbackId, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        $queuedKey = "draft-gallery-retrain:{$draft->id}:queued";

        if (! Cache::add($queuedKey, true, now()->addMinutes(10))) {
            $this->telegram->answerCallbackQuery($callbackId, 'Переобучение этого источника уже идёт.');

            return;
        }

        TrainDraftGalleryRecipe::dispatch($draft->id, $update->id, $chatId, $hint);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $this->telegram->answerCallbackQuery($callbackId, 'AI изучает DOM источника и проверит новый рецепт.');
        $this->draftPresenter->clearControls($this->telegram, $draft, $chatId);
        $this->telegram->sendMessage(
            $chatId,
            $hint !== null
                ? "🧠 Черновик #{$draft->id}: запускаю переобучение источника с вашей подсказкой. Старый рецепт и фото останутся, пока новый не пройдёт проверку."
                : "🧠 Черновик #{$draft->id}: запускаю переобучение источника. Старый рецепт и фото останутся, пока новый не пройдёт проверку.",
        );
    }

    private function handleDraftSourceHintPrompt(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');

            return;
        }

        if (blank($draft->primary_source_url)) {
            $this->telegram->answerCallbackQuery($callbackId, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        Cache::put(self::pendingSourceHintKey($chatId), $draft->id, now()->addMinutes(10));
        $this->telegram->answerCallbackQuery($callbackId, 'Опишите проблему следующим сообщением.');
        $this->draftPresenter->clearControls($this->telegram, $draft, $chatId);
        $this->telegram->sendMessage(
            $chatId,
            "✏️ Черновик #{$draft->id}: опишите одним следующим сообщением, что не так с источником или фото (например: «на странице таблица с разными моделями» или «путает цвет корпуса»). Я передам это AI перед переобучением. Действует 10 минут.",
        );
        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private function handleDraftSourceBlockPrompt(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');

            return;
        }

        $host = blank($draft->primary_source_url) ? '' : ProductSourcePriority::host($draft->primary_source_url);

        if ($host === '') {
            $this->telegram->answerCallbackQuery($callbackId, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, 'Подтвердите бан источника.');
        $this->draftPresenter->sendSourceBlockConfirm($this->telegram, $chatId, $draft, $host);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private function handleDraftSourceBlockDecision(
        int $draftId,
        bool $confirmed,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');

            return;
        }

        if (! $confirmed) {
            $this->telegram->answerCallbackQuery($callbackId, 'Отменено.');
            $this->draftPresenter->sendControls($this->telegram, $chatId, $draft);
            $update->update(['status' => 'completed', 'processed_at' => now()]);

            return;
        }

        $host = blank($draft->primary_source_url) ? '' : ProductSourcePriority::host($draft->primary_source_url);

        if ($host === '') {
            $this->telegram->answerCallbackQuery($callbackId, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        ProductGalleryRecipe::query()->updateOrCreate(
            ['domain' => $host, 'path_pattern' => '*'],
            [
                'status' => 'disabled',
                'source_blocked' => true,
                'source_block_reason' => "Заблокировано вручную оператором в Telegram (черновик #{$draft->id}).",
                'source_blocked_at' => now(),
                'retry_after' => null,
            ],
        );
        $this->telegram->answerCallbackQuery($callbackId, "Источник {$host} забанен.");
        $this->draftPresenter->clearControls($this->telegram, $draft, $chatId);
        $this->telegram->sendMessage(
            $chatId,
            "🚫 Источник {$host} заблокирован вручную. Больше не будет использоваться в поиске фото ни для одного товара.",
        );
        $this->draftPresenter->sendControls($this->telegram, $chatId, $draft);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private static function pendingSourceHintKey(string $chatId): string
    {
        return "draft-source-hint-await:{$chatId}";
    }

    private function handleDraftRestageGallery(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');

            return;
        }

        $queuedKey = "draft-gallery-restage:{$draft->id}:queued";

        if (! Cache::add($queuedKey, true, now()->addMinutes(10))) {
            $this->telegram->answerCallbackQuery($callbackId, 'Поиск фото уже идёт.');

            return;
        }

        RestageDraftGalleryPhotos::dispatch($draft->id, $chatId, $update->id);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $this->telegram->answerCallbackQuery($callbackId, 'Ищу другие фото без прежних источников.');
        $this->telegram->sendMessage(
            $chatId,
            "🔄 Черновик #{$draft->id}: исключаю прежние источники и похожие фото. Старая галерея останется, пока новая не будет готова.",
        );
    }

    private function handleDraftFindMorePhotos(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик не найден или уже обработан.');

            return;
        }

        $queuedKey = "draft-gallery-topup:{$draft->id}:queued";

        if (! Cache::add($queuedKey, true, now()->addMinutes(10))) {
            $this->telegram->answerCallbackQuery($callbackId, 'Поиск дополнительных фото уже идёт.');

            return;
        }

        TopUpDraftGalleryPhotos::dispatch($draft->id, $chatId, $update->id);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $this->telegram->answerCallbackQuery($callbackId, 'Ищу дополнительные фото.');
        $this->telegram->sendMessage(
            $chatId,
            "🔍 Черновик #{$draft->id}: ищу дополнительные фото. Текущие останутся на месте.",
        );
    }

    /**
     * The blocking AI call already in flight cannot actually be aborted from
     * here (see TelegramProgressReporter::withCancelButton()) - this only
     * marks intent. ResearchProduct checks cancel_requested_at once that call
     * finally returns and discards the result instead of building a draft.
     */
    private function handleSearchCancel(
        int $targetUpdateId,
        string $callbackId,
        string $chatId,
        mixed $messageId,
        TelegramUpdate $update,
    ): void {
        $target = TelegramUpdate::query()->find($targetUpdateId);

        if (! $target || $target->processed_at !== null) {
            $this->telegram->answerCallbackQuery($callbackId, 'Поиск уже завершён — отменять нечего.');
        } else {
            $target->update(['cancel_requested_at' => now()]);
            $this->telegram->answerCallbackQuery(
                $callbackId,
                'Отмена запрошена. Уже начатый запрос к AI доработает в фоне, но результат будет проигнорирован.',
            );
        }

        if ($chatId !== '' && is_int($messageId)) {
            try {
                $this->telegram->removeInlineKeyboard($chatId, $messageId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private function handleProductDeletion(
        string $action,
        int $operationId,
        string $callbackId,
        string $chatId,
        mixed $messageId,
        TelegramUpdate $update,
    ): void {
        $operation = AiOperation::query()->with('telegramUpdate')->find($operationId);

        if (! $operation || $operation->action !== 'delete_product') {
            $this->telegram->answerCallbackQuery($callbackId, 'Операция удаления не найдена.');
            $update->update(['status' => 'not_found', 'processed_at' => now()]);

            return;
        }

        $sameUser = hash_equals((string) $operation->telegram_user_id, (string) $update->telegram_user_id);
        $sameChat = hash_equals((string) $operation->telegramUpdate?->chat_id, $chatId);

        if (! $sameUser || ! $sameChat) {
            $this->telegram->answerCallbackQuery($callbackId, 'Эта операция создана в другом чате или другим пользователем.');
            $update->update(['status' => 'rejected', 'processed_at' => now()]);

            return;
        }

        if ($operation->status !== 'awaiting_confirmation') {
            $this->telegram->answerCallbackQuery($callbackId, "Операция уже обработана: {$operation->status}.");
            $update->update(['status' => 'already_processed', 'processed_at' => now()]);

            return;
        }

        $productTitle = (string) data_get($operation->payload, 'title', 'Товар');
        $productId = (int) $operation->target_id;

        if ($action === 'cancel') {
            $operation->update([
                'status' => 'cancelled',
                'result' => ['cancelled_by_update_id' => $update->id],
                'executed_at' => now(),
            ]);
            $result = "Удаление товара #{$productId} отменено.";
        } else {
            $product = Product::query()->find($productId);

            if (! $product) {
                $operation->update([
                    'status' => 'failed',
                    'error' => 'Product was not found at confirmation time.',
                    'executed_at' => now(),
                ]);
                $this->telegram->answerCallbackQuery($callbackId, 'Товар уже отсутствует.');
                $update->update(['status' => 'not_found', 'processed_at' => now()]);

                return;
            }

            try {
                $product->delete();
                $operation->update([
                    'status' => 'completed',
                    'result' => [
                        'deleted_product_id' => $productId,
                        'title' => $productTitle,
                        'confirmed_by_update_id' => $update->id,
                    ],
                    'executed_at' => now(),
                ]);
                $result = "🗑 Товар #{$productId} «{$productTitle}» удалён навсегда. Операция #{$operation->id}.";
            } catch (Throwable $exception) {
                report($exception);
                $operation->update([
                    'status' => 'failed',
                    'error' => mb_substr($exception->getMessage(), 0, 5000),
                    'executed_at' => now(),
                ]);
                $this->telegram->answerCallbackQuery($callbackId, 'Не удалось удалить товар. Ошибка записана в журнал.');
                $update->update([
                    'status' => 'failed',
                    'error' => mb_substr($exception->getMessage(), 0, 5000),
                    'processed_at' => now(),
                ]);

                return;
            }
        }

        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $this->telegram->answerCallbackQuery($callbackId, $result);

        if ($chatId !== '' && is_int($messageId)) {
            try {
                $this->telegram->removeInlineKeyboard($chatId, $messageId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($chatId !== '') {
            $this->telegram->sendMessage($chatId, $result, $this->mainKeyboard());
        }
    }

    private function sendDrafts(string $chatId, string $telegramUserId): void
    {
        $drafts = ProductDraft::query()->where('requested_by_telegram_user_id', $telegramUserId)->latest('id')->limit(10)->get();
        if ($drafts->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Черновиков пока нет.', $this->mainKeyboard());

            return;
        }

        $lines = $drafts->map(fn (ProductDraft $draft): string => "#{$draft->id} · {$draft->title} · {$draft->status}");
        $buttons = $drafts->where('status', 'pending_review')->map(fn (ProductDraft $draft): array => [
            ['text' => "➕ Добавить #{$draft->id}", 'callback_data' => "draft:add:{$draft->id}"],
            ['text' => "✖ Отклонить #{$draft->id}", 'callback_data' => "draft:reject:{$draft->id}"],
        ])->values()->all();
        $this->telegram->sendMessage($chatId, "Последние черновики:\n\n".$lines->implode("\n"),
            $buttons ? ['inline_keyboard' => $buttons] : $this->mainKeyboard());
    }

    private function helpText(): string
    {
        return <<<'TEXT'
            Управление Ningredy

            Пишите обычным текстом или отправляйте голосовое. Примеры:
            • «Найди Lenovo Legion RTX 4070» — каталог, затем интернет
            • «Найди в интернете Mac mini M4» — сразу интернет и черновик
            • «Деактивируй товар #12» — скроет его с сайта
            • «Измени описание товара #12 на ...»
            • «Покажи статус сервера / последние ошибки»

            /find запрос — поиск
            /drafts — черновики
            /status — состояние системы
            /errors — ошибки
            /new — очистить контекст диалога
            /reset — отменить зависшие запросы и очистить контекст (если поиск завис, например когда воркер очереди был выключен)
            TEXT;
    }

    private function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '🔎 Найти товар'], ['text' => '📦 Каталог']],
                [['text' => '📋 Черновики'], ['text' => '🖥 Статус']],
                [['text' => '⚠️ Ошибки'], ['text' => 'ℹ️ Помощь']],
                [['text' => '🔄 Сброс']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * Consumes a pending "💬 Переобучить с подсказкой" prompt (see
     * handleDraftSourceHintPrompt) - the next plain-text message in that chat
     * is the operator's hint, not a new product search, so it must never
     * reach ServerAssistantAgent. Returns false (untouched) when nothing is
     * pending, so the caller falls through to the normal AI dispatch.
     */
    private function handlePendingSourceHint(string $text, string $chatId, TelegramUpdate $update): bool
    {
        $draftId = Cache::pull(self::pendingSourceHintKey($chatId));

        if (! is_int($draftId)) {
            return false;
        }

        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review' || blank($draft->primary_source_url)) {
            $this->telegram->sendMessage($chatId, 'Черновик, для которого ждал подсказку, уже недоступен. Подсказка не использована.');
            $update->update(['status' => 'ignored', 'processed_at' => now()]);

            return true;
        }

        $queuedKey = "draft-gallery-retrain:{$draft->id}:queued";

        if (! Cache::add($queuedKey, true, now()->addMinutes(10))) {
            $this->telegram->sendMessage($chatId, 'Переобучение этого источника уже идёт. Подсказка не использована.');
            $update->update(['status' => 'ignored', 'processed_at' => now()]);

            return true;
        }

        $hint = mb_substr($text, 0, 1000);
        TrainDraftGalleryRecipe::dispatch($draft->id, $update->id, $chatId, $hint);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $this->telegram->sendMessage(
            $chatId,
            "🧠 Черновик #{$draft->id}: запускаю переобучение источника с вашей подсказкой. Старый рецепт и фото останутся, пока новый не пройдёт проверку.",
        );

        return true;
    }

    private function markCommandProcessed(TelegramUpdate $update): void
    {
        $update->update(['status' => 'command', 'processed_at' => now()]);
    }
}
