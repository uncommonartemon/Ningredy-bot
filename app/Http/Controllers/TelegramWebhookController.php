<?php

namespace App\Http\Controllers;

use App\Ai\Tools\GetSystemStatus;
use App\Ai\Tools\PrepareProductDeletion;
use App\Exceptions\LowResolutionDraftMediaException;
use App\Exceptions\MissingDraftMediaException;
use App\Exceptions\UnreconciledDraftSpecificationsException;
use App\Exceptions\UnverifiedDraftMediaException;
use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\EnrichTelegramProductDraft;
use App\Jobs\ProcessDraftPhotoActions;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\ProposeTelegramDraftEdit;
use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\SendDiagnosticReport;
use App\Jobs\TopUpDraftGalleryPhotos;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Jobs\TranscribeTelegramPhoto;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\AiOperation;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductSearchIntentDetector;
use App\Services\Products\ProductSourcePriority;
use App\Services\ProductSearch\Recovery\RequestGuardian;
use App\Services\Telegram\CatalogTelegramNavigation;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftTelegramActionConfirmation;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramCatalogDeletion;
use App\Services\Telegram\TelegramCatalogEditor;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramConversationScope;
use App\Services\Telegram\TelegramDatabaseOperator;
use App\Services\Telegram\TelegramDraftEditor;
use App\Services\Telegram\TelegramOperatorAccess;
use App\Services\Telegram\TelegramProductUploadIntake;
use App\Services\Telegram\TelegramWebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        $id = $request->json('update_id');
        if (! is_int($id)) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }
        $lock = Cache::lock('telegram-webhook:'.$id, 120);
        abort_unless($lock->get(), 503, 'This Telegram update is being handled; retry delivery.');
        try {
            $result = $this->routeUpdate($request);
            $update = TelegramUpdate::where('update_id', $id)->first();
            if ($update && ! data_get($update->payload, '_webhook_routed')) {
                $update->update(['payload' => [...($update->payload ?? []), '_webhook_routed' => true]]);
            }

            return $result;
        } finally {
            TelegramWebhookDelivery::end();
            $lock->release();
        }
    }

    private function routeUpdate(Request $request): JsonResponse
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
        $hasPhoto = is_array(data_get($payload, 'message.photo'));
        $hasImageDocument = str_starts_with((string) data_get($payload, 'message.document.mime_type'), 'image/');
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

        TelegramWebhookDelivery::begin($update->id);
        if (! $update->wasRecentlyCreated && (data_get($update->payload, '_webhook_routed')
            || $update->processed_at !== null || in_array($update->status, ['processing', 'cancelled', 'rejected'], true))) {
            if ($isAllowed) {
                $this->telegram->retryWebhookDeliveries($update->id);
            }

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

        $inputState = $chatId !== null ? app(DraftTelegramInteractionState::class)->get((string) $chatId, (string) $telegramUserId) : null;
        // A pending button confirmation cannot be answered with a message, so a
        // message during one is what it looks like: the operator's next request
        // (live #153-#155: three products sent while a card waited for its
        // button were answered with "confirm by button" and lost). The
        // confirmation stays on its buttons until it expires.
        if (($hasVoice || $hasPhoto || $hasImageDocument) && $chatId !== null
            && (in_array($inputState['kind'] ?? '', ['source_hint', 'new_search', 'manual_fields', 'processing'], true)
                || ($hasVoice && ($inputState['kind'] ?? '') === 'manual_photos'))) {
            $this->telegram->sendMessage((string) $chatId, match ($inputState['kind']) {
                'processing' => 'Карточка сейчас обрабатывается. Дождитесь результата или /cancel.',
                'manual_photos' => 'Сейчас ожидаю фото для выбранной карточки. Отправьте фото или альбом либо /cancel.',
                default => 'Сейчас ожидаю текст для выбранного черновика. Напишите его текстом или нажмите «Отменить ввод».',
            }.' Фото и голос не отправлены в новый поиск.');
            $this->markCommandProcessed($update);

            return response()->json(['ok' => true]);
        }
        if (($hasVoice || $hasPhoto || $hasImageDocument) && $chatId !== null
            && $this->expiredManualInput((string) $chatId, (string) $telegramUserId)) {
            $this->forgetExpiredInput((string) $chatId, (string) $telegramUserId);
            $this->telegram->sendMessage((string) $chatId, 'Время ввода истекло. Откройте нужную карточку или /add заново. Материалы не отправлены в интернет-поиск.');
            $this->markCommandProcessed($update);

            return response()->json(['ok' => true]);
        }

        if (($hasPhoto || $hasImageDocument) && $chatId !== null) {
            $pending = app(DraftTelegramInteractionState::class)->get((string) $chatId, (string) $telegramUserId);
            $caption = trim((string) data_get($payload, 'message.caption', ''));
            $createKey = "telegram-manual-create:{$chatId}:{$telegramUserId}";
            if (($pending['kind'] ?? '') === 'manual_photos') {
                $draft = ProductDraft::find($pending['draft_id']);
                if (! $draft || ! $draft->isManualUpload() || $draft->status !== 'pending_review'
                    || (int) $draft->telegram_update_id !== (int) $pending['generation']
                    || ! $this->draftBelongsToContext($draft, $update)) {
                    $this->telegram->sendMessage((string) $chatId, 'Карточка для загрузки фото уже недоступна. Откройте её заново.');
                    $this->markCommandProcessed($update);
                } else {
                    app(TelegramProductUploadIntake::class)->queue($update, $caption, $draft->id);
                }

                return response()->json(['ok' => true, 'upload' => true]);
            }
            if (Cache::has($createKey) || $hasImageDocument || filled(data_get($payload, 'message.media_group_id'))
                || ($caption !== '' && ! app(ProductSearchIntentDetector::class)->hasExplicitSearchRequest($caption))) {
                $this->clearManualCreate((string) $chatId, (string) $telegramUserId);
                app(TelegramProductUploadIntake::class)->queue($update, $caption);

                return response()->json(['ok' => true, 'upload' => true]);
            }
        }

        if ($hasVoice && $chatId !== null && Cache::has("telegram-manual-create:{$chatId}:{$telegramUserId}")) {
            $this->telegram->sendMessage((string) $chatId, 'Для загрузки своего товара отправьте описание текстом и фото. Голос не отправлен в интернет-поиск. /cancel — отменить ввод.');
            $this->markCommandProcessed($update);

            return response()->json(['ok' => true]);
        }
        if ($hasVoice && $chatId !== null) {
            TranscribeTelegramVoice::dispatch($update->id)->afterCommit();

            return response()->json(['ok' => true, 'voice' => true]);
        }

        if ($hasPhoto && $chatId !== null) {
            TranscribeTelegramPhoto::dispatch($update->id)->afterCommit();

            return response()->json(['ok' => true, 'photo' => true]);
        }

        if (! is_string($text) || trim($text) === '' || $chatId === null) {
            // A file, video or sticker the operator sent on purpose deserves an
            // answer; service messages (pins, member changes) carry none of these.
            $content = array_intersect(array_keys((array) data_get($payload, 'message', [])),
                ['document', 'video', 'video_note', 'audio', 'animation', 'sticker', 'location', 'venue', 'contact', 'poll', 'dice']);
            if ($content !== [] && $chatId !== null) {
                $this->telegram->sendMessage((string) $chatId, 'Такие сообщения бот не обрабатывает. Напишите текстом, отправьте голосовое или фото товара. Поиск не запущен.');
            }
            $update->update(['status' => 'ignored', 'processed_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        if ($this->handleCommand(trim($text), (string) $chatId, (string) $telegramUserId, $update)) {
            return response()->json(['ok' => true]);
        }

        if ($this->handlePendingDraftInput(trim($text), (string) $chatId, $update)) {
            return response()->json(['ok' => true]);
        }
        if ($this->expiredManualInput((string) $chatId, (string) $telegramUserId)) {
            // Said once: the next message after this notice is an ordinary request again.
            $this->forgetExpiredInput((string) $chatId, (string) $telegramUserId);
            $this->telegram->sendMessage((string) $chatId, 'Время ввода истекло. Откройте ручную карточку или /add заново. Если это был новый поиск, отправьте запрос ещё раз. Поиск сейчас не запущен.');
            $this->markCommandProcessed($update);

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
        return app(TelegramOperatorAccess::class)->allows($telegramUserId);
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
        $createKey = "telegram-manual-create:{$chatId}:{$telegramUserId}";
        // /errors is answered by the assistant, so it must not stay behind as the
        // awaited hint, refined query or manual edit.
        if (preg_match('/^\/(?:edit|delete|product|catalog|drafts|find|new|reset|errors)(?:@\w+)?(?:\s|$)/iu', $text) === 1
            || in_array($text, ['📦 Каталог', '📋 Черновики', '🔎 Найти товар', '🔄 Сброс', '⚠️ Ошибки'], true)) {
            $this->clearManualCreate($chatId, $telegramUserId);
            app(DraftTelegramInteractionState::class)->clear($chatId, $telegramUserId);
        }
        if (preg_match('/^\/(?:cancel|new|reset)(?:@\w+)?$/iu', $text) === 1 || $text === '🔄 Сброс') {
            $this->clearManualCreate($chatId, $telegramUserId);
        }
        if (preg_match('/^\/cancel(?:@\w+)?$/iu', $text) === 1) {
            $active = app(DraftTelegramInteractionState::class)->get($chatId, $telegramUserId);
            if (($active['kind'] ?? '') === 'processing' && isset($active['input_update_id'])) {
                TelegramUpdate::query()->whereKey($active['input_update_id'])->where('chat_id', $chatId)
                    ->where('telegram_user_id', $telegramUserId)->whereNull('processed_at')
                    ->update(['status' => 'cancelled', 'cancel_requested_at' => now(), 'processed_at' => now()]);
            }
            app(DraftTelegramInteractionState::class)->clear($chatId, $telegramUserId);
            $this->telegram->sendMessage($chatId, 'Ввод отменён. Сохранённые карточки остаются.', $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }
        if (preg_match('/^\/add(?:@\w+)?(?:\s+(.*))?$/isu', $text, $add) === 1 || $text === '📥 Загрузить товар') {
            app(DraftTelegramInteractionState::class)->clear($chatId, $telegramUserId);
            $description = trim((string) ($add[1] ?? ''));
            if ($description !== '') {
                app(TelegramProductUploadIntake::class)->queue($update, $description);
            } else {
                Cache::put($createKey, true, now()->addMinutes(10));
                Cache::put($createKey.':expires_at', now()->addMinutes(10)->getTimestamp(), now()->addMinutes(70));
                $this->telegram->sendMessage($chatId,
                    "📥 Отправьте фотографии одним альбомом с описанием в подписи. Можно начать с текста, затем добавить фото к черновику.\nУкажите модель, состояние, характеристики и при необходимости цену с валютой и количество.\nБот подготовит карточку; добавление в каталог — только после вашего подтверждения. /cancel — отменить ввод.");
                $this->markCommandProcessed($update);
            }

            return true;
        }
        if (preg_match('/^\/edit(?:@\w+)?\s+#?(\d+)(?:\s+#?(\d+))?$/iu', $text, $editProduct) === 1) {
            $this->openCatalogEditor((int) $editProduct[1], $update, isset($editProduct[2]) ? (int) $editProduct[2] : null);

            return true;
        }
        if (preg_match('/^\/delete(?:@\w+)?\s+#?(\d+)$/iu', $text, $deleteProduct) === 1) {
            $this->prepareCatalogDeletion((int) $deleteProduct[1], $update);

            return true;
        }
        // A t.me deep link arrives as "/start <payload>".
        if (preg_match('/^\/(start|help)(?:@\w+)?(?:\s.*)?$/isu', $text) === 1 || $text === 'ℹ️ Помощь') {
            $this->telegram->sendMessage($chatId, $this->helpText(), $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/new(?:@\w+)?$/iu', $text) === 1) {
            if (! data_get($update->payload, '_new_context_cleared')) {
                app(TelegramDatabaseOperator::class)->cancelPending($update);
                app(DraftTelegramInteractionState::class)->clear($chatId, $telegramUserId);
                TelegramConversationScope::clear($chatId, $telegramUserId);
                $update->update(['payload' => [...$update->payload, '_new_context_cleared' => true]]);
            }
            $this->telegram->sendMessage($chatId, 'Контекст диалога очищен. Начинаем с чистого листа.', $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        // Operator, 2026-10-29: the bot is handed to a person; what went wrong there
        // reaches the developer as one file, with no terminal and no server access.
        if (preg_match('/^\/report(?:@\w+)?(?:\s+(full|db|база|с\s+базой))?$/iu', $text, $reportMatch) === 1) {
            $withDatabase = isset($reportMatch[1]);
            if (! data_get($update->payload, '_report_queued')) {
                SendDiagnosticReport::dispatch($chatId, 7, $withDatabase);
                $update->update(['payload' => [...$update->payload, '_report_queued' => true]]);
            }
            $this->telegram->sendMessage($chatId, 'Собираю отчёт для разработчика за 7 дней: журнал поисков, вызовы AI, карточки, выученные правила сайтов, лог ошибок'
                .($withDatabase ? ', копия базы' : '').' — без ключей и паролей. Пришлю файлом через минуту-две.', $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/reset(?:@\w+)?$/iu', $text) === 1 || $text === '🔄 Сброс') {
            $message = data_get($update->payload, '_reset_result');
            if (! is_string($message)) {
                $message = DB::transaction(function () use ($chatId, $telegramUserId, $update): string {
                    app(TelegramDatabaseOperator::class)->cancelPending($update);
                    app(DraftTelegramInteractionState::class)->clear($chatId, $telegramUserId);
                    // A ProcessTelegramMessage job whose update already shows processed_at
                    // returns immediately. cancel_requested_at also reaches a job or
                    // research tool already waiting for the provider's response.
                    $cancelled = TelegramUpdate::query()
                        ->where('chat_id', $chatId)
                        ->where('telegram_user_id', $telegramUserId)
                        ->whereNull('processed_at')
                        ->where('id', '!=', $update->id)
                        ->update(['status' => 'cancelled', 'cancel_requested_at' => now(), 'processed_at' => now()]);
                    TelegramConversationScope::clear($chatId, $telegramUserId);
                    $message = $cancelled > 0
                        ? "Сброс выполнен: отменено зависших запросов — {$cancelled}. Контекст диалога очищен. Можно начинать заново."
                        : 'Сброс выполнен: зависших запросов не было. Контекст диалога очищен. Можно начинать заново.';
                    $update->update(['payload' => [...$update->payload, '_reset_result' => $message]]);

                    return $message;
                });
            }
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

        if (preg_match('/^\/status(?:@\w+)?$/iu', $text) === 1 || $text === '🖥 Статус') {
            $status = json_decode(app(GetSystemStatus::class)->handle(new \Laravel\Ai\Tools\Request([])), true);
            $this->telegram->sendMessage($chatId, implode("\n", [
                '🖥 Состояние проекта',
                'Товаров: '.$status['catalog']['total'].' · опубликовано: '.$status['catalog']['active'],
                'Черновиков: '.$status['pending_drafts'],
                'В очереди: '.$status['queue']['pending'].' · упавших заданий: '.$status['queue']['failed'],
                'Ошибок AI за сутки: '.$status['ai_failures_last_24h'],
                'Наличие заданий не подтверждает, что воркер сейчас работает.',
                $status['catalog_url'] ? 'Каталог: '.$status['catalog_url'] : 'Публичный адрес пока не установлен.',
            ]), $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/admin(?:@\w+)?$/iu', $text) === 1 || $text === '🛠 Админка') {
            $navigation = app(CatalogTelegramNavigation::class)->catalog();
            $this->telegram->sendMessage($chatId, $navigation !== null
                ? '🛠 Управление каталогом. Создание и редактирование вручную не расходуют AI-бюджет. Откроется админка; потребуется обычный вход.'
                : 'Публичный адрес админки пока не настроен. Проверьте туннель и настройки Telegram.',
                $navigation ?? $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/product(?:@\w+)?\s+#?(\d+)$/iu', $text, $showProduct) === 1) {
            app(ProductCardPresenter::class)->sendMany($this->telegram, $chatId, [(int) $showProduct[1]], withManagementLinks: true,
                telegramUserId: $update->telegram_user_id);
            $this->markCommandProcessed($update);

            return true;
        }
        if (preg_match('/^\/catalog(?:@\w+)?(?:\s+(.*))?$/isu', $text, $catalogQuery) === 1 || $text === '📦 Каталог') {
            $navigation = app(CatalogTelegramNavigation::class)->catalog() ?? $this->mainKeyboard();
            $query = trim((string) ($catalogQuery[1] ?? ''));
            $ids = Product::query()->visibleInCatalog()->when($query !== '', fn ($products) => $products
                ->where(fn ($search) => $search->where('title', 'like', '%'.$query.'%')->orWhere('model', 'like', '%'.$query.'%')))
                ->latest('id')->limit(10)->pluck('id')->all();
            if ($ids === []) {
                $this->telegram->sendMessage($chatId, $query === ''
                    ? 'В каталоге пока нет опубликованных товаров.'
                    : "По запросу «{$query}» в каталоге ничего не найдено. Интернет-поиск: /find {$query}", $navigation);
            } else {
                app(ProductCardPresenter::class)->sendMany($this->telegram, $chatId, $ids, withManagementLinks: true,
                    telegramUserId: $update->telegram_user_id);
                $this->telegram->sendMessage($chatId, 'Товары каталога. /catalog название — найти в базе; /product номер — открыть; /edit номер — редактировать с подтверждением. Для полного управления вариантами доступен Filament.', $navigation);
            }
            $this->markCommandProcessed($update);

            return true;
        }

        $preset = match (true) {
            preg_match('/^\/errors(?:@\w+)?$/iu', $text) === 1, $text === '⚠️ Ошибки' => 'Покажи последние ошибки AI и очереди.',
            default => null,
        };
        if ($preset !== null) {
            $update->update(['text' => $preset]);

            return false;
        }

        if (! str_starts_with($text, '/') && Cache::has($createKey)) {
            $this->clearManualCreate($chatId, $telegramUserId);
            app(TelegramProductUploadIntake::class)->queue($update, $text);

            return true;
        }

        if (preg_match('/^\/find(?:@\w+)?(?:\s+(.*))?$/isu', $text, $matches) === 1) {
            $query = trim($matches[1] ?? '');
            if ($query === '') {
                $this->telegram->sendMessage($chatId, 'После /find укажите товар. Например: /find Lenovo Legion RTX 4070 32 GB.', $this->mainKeyboard());
                $this->markCommandProcessed($update);

                return true;
            }
            $update->update(['text' => $query]);
        } elseif (str_starts_with($text, '/')) {
            $this->telegram->sendMessage($chatId, 'Команда не распознана. /add — загрузить товар; /edit номер — редактировать; /find название — поиск; /help — все команды. Поиск не запущен.');
            $this->markCommandProcessed($update);

            return true;
        }

        return false;
    }

    private function handleCallback(array $callback, TelegramUpdate $update): void
    {
        $callbackId = (string) data_get($callback, 'id');
        $data = (string) data_get($callback, 'data');
        $chatId = (string) data_get($callback, 'message.chat.id');
        $messageId = data_get($callback, 'message.message_id');

        if (str_starts_with($data, 'product:')) {
            $this->clearManualCreate($chatId, (string) $update->telegram_user_id);
            app(DraftTelegramInteractionState::class)->clear($chatId, (string) $update->telegram_user_id);
        }

        if ($data === 'product:create') {
            $this->handleCommand('/add', $chatId, (string) $update->telegram_user_id, $update);
            $this->telegram->answerCallbackQuery($callbackId, 'Отправьте описание и фото.');

            return;
        }
        if (preg_match('/^product:variants:(\d+):(\d+)$/', $data, $variantPage) === 1) {
            $this->openCatalogEditor((int) $variantPage[1], $update, null, (int) $variantPage[2]);
            $this->telegram->answerCallbackQuery($callbackId, 'Выберите комплектацию.');

            return;
        }
        if (preg_match('/^product:(tgedit|remove):(\d+)(?::(\d+))?$/', $data, $catalogAction) === 1) {
            if ($catalogAction[1] === 'tgedit') {
                $this->openCatalogEditor((int) $catalogAction[2], $update, isset($catalogAction[3]) ? (int) $catalogAction[3] : null);
            } else {
                $this->prepareCatalogDeletion((int) $catalogAction[2], $update);
            }
            $this->telegram->answerCallbackQuery($callbackId, 'Карточка открыта.');

            return;
        }

        if (preg_match('/^draft:open:(\d+):(\d+)$/', $data, $open) === 1) {
            $draft = ProductDraft::query()->find((int) $open[1]);
            if (! $draft || (int) $draft->telegram_update_id !== (int) $open[2]
                || ! $this->draftBelongsToContext($draft, $update) || $draft->status !== 'pending_review') {
                $this->telegram->answerCallbackQuery($callbackId, 'Черновик недоступен или уже обработан.');
            } elseif ($this->draftHasQueuedWork($draft->id)) {
                $this->telegram->answerCallbackQuery($callbackId, 'Этот черновик сейчас обрабатывается. Дождитесь результата.');
            } else {
                $this->clearManualCreate($chatId, (string) $update->telegram_user_id);
                app(DraftTelegramInteractionState::class)->clear($chatId, (string) $update->telegram_user_id);
                $this->telegram->answerCallbackQuery($callbackId, 'Открываю актуальную карточку.');
                $this->draftPresenter->clearForSearchContinuation($this->telegram, $draft);
                $this->draftPresenter->sendReview($this->telegram, $chatId, $draft);
            }
            $this->markCommandProcessed($update);

            return;
        }

        if (preg_match('/^draft:resume-edit:(\d+):([A-Za-z0-9]{12})$/', $data, $resume) === 1) {
            $draft = ProductDraft::find((int) $resume[1]);
            $key = "draft-edit-proposal:{$chatId}:{$update->telegram_user_id}:{$resume[1]}:{$resume[2]}";
            $proposal = Cache::get($key);
            if (! $draft || ! is_array($proposal) || $draft->status !== 'pending_review'
                || ! $this->draftBelongsToContext($draft, $update)
                || (int) ($proposal['generation'] ?? 0) !== (int) $draft->telegram_update_id
                || ! hash_equals((string) ($proposal['fingerprint'] ?? ''), app(TelegramDraftEditor::class)->fingerprint($draft))
                || $this->draftHasQueuedWork($draft->id)) {
                $this->telegram->answerCallbackQuery($callbackId, 'Изменения устарели или карточка обрабатывается. Откройте редактор заново.');
            } else {
                Cache::forget($key);
                $this->clearManualCreate($chatId, (string) $update->telegram_user_id);
                app(DraftTelegramInteractionState::class)->clear($chatId, (string) $update->telegram_user_id);
                (new ProposeTelegramDraftEdit($draft->id, $update->id, $draft->telegram_update_id, ''))
                    ->presentProposal($draft, $update, $proposal, $this->telegram, $this->draftPresenter);
                $this->telegram->answerCallbackQuery($callbackId, 'Проверьте предложенные изменения.');
            }
            $this->markCommandProcessed($update);

            return;
        }

        $isConfirmation = preg_match('/^draft:confirm:(\d+):([A-Za-z0-9]{12})$/', $data, $confirmation) === 1;
        $checkedData = $isConfirmation ? "draft:review:{$confirmation[1]}" : $data;
        if ($this->rejectStaleDraftCallback($checkedData, $callbackId, $chatId, $messageId, $update)) {
            return;
        }
        if (preg_match('/^draft:[a-z-]+:(\d+)/', $data, $target) === 1
            && $this->draftHasQueuedWork((int) $target[1])) {
            $this->telegram->answerCallbackQuery($callbackId, 'Этот черновик уже обрабатывается. Повторное действие не запущено.');
            $this->markCommandProcessed($update);

            return;
        }

        $confirmed = false;
        $confirmedHint = null;
        $confirmedQuery = null;
        if ($isConfirmation) {
            // Once consumed, retain this update's receipt durably. A failed Telegram
            // delivery before dispatch can retry without consuming another token.
            $savedConfirmation = data_get($update->payload, '_consumed_draft_confirmation');
            $state = is_array($savedConfirmation) && hash_equals((string) ($savedConfirmation['token'] ?? ''), $confirmation[2])
                ? $savedConfirmation
                : app(DraftTelegramInteractionState::class)->consume($chatId, (string) $update->telegram_user_id, $confirmation[2]);
            $draft = ProductDraft::query()->find((int) $confirmation[1]);
            if (! $state || ($state['kind'] ?? '') !== 'confirmation' || ! $draft
                || $draft->status !== 'pending_review'
                || $state['draft_id'] !== $draft->id || (int) $state['generation'] !== (int) $draft->telegram_update_id) {
                $this->telegram->answerCallbackQuery($callbackId, 'Подтверждение устарело. Откройте действие заново.');
                $this->markCommandProcessed($update);

                return;
            }
            $data = $state['callback'];
            $terms = app(DraftTelegramActionConfirmation::class)->describe($data);
            if ($terms && ($state['budget'] ?? null) !== $terms['budget']) {
                $this->telegram->answerCallbackQuery($callbackId, 'Бюджет изменился. Откройте действие заново.');
                $this->markCommandProcessed($update);

                return;
            }
            $confirmedHint = $state['hint'] ?? null;
            $confirmedQuery = $state['query'] ?? null;
            $update->update([
                'text' => $data,
                'payload' => [...($update->payload ?? []), '_consumed_draft_confirmation' => $state, 'draft_action_confirmation' => [
                    'action' => $data, 'draft_id' => $draft->id, 'generation' => $state['generation'],
                    'budget' => $state['budget'] ?? null, 'input_update_id' => $state['input_update_id'] ?? null,
                ]],
            ]);
            $confirmed = true;
        } elseif (str_starts_with($data, 'draft:')) {
            $this->clearManualCreate($chatId, (string) $update->telegram_user_id);
            app(DraftTelegramInteractionState::class)->clear($chatId, (string) $update->telegram_user_id);
        }

        if (! $confirmed && ($terms = app(DraftTelegramActionConfirmation::class)->describe($data))) {
            $draftId = (int) explode(':', $data)[2];
            $draft = ProductDraft::query()->find($draftId);
            if ($draft && $draft->status === 'pending_review') {
                $this->requestDraftActionConfirmation($draft, $update, $data);
                $this->telegram->answerCallbackQuery($callbackId, 'Проверьте действие перед запуском.');
            } else {
                $this->telegram->answerCallbackQuery($callbackId, 'Черновик недоступен.');
            }
            $this->markCommandProcessed($update);

            return;
        }

        if (preg_match('/^draft:apply-edit:(\d+)$/', $data, $applyEdit) === 1 && $confirmed) {
            try {
                $draft = app(TelegramDraftEditor::class)->apply($draft, $update, $state['proposal'] ?? []);
                $this->draftPresenter->clearForSearchContinuation($this->telegram, $draft);
                $this->draftPresenter->sendReview($this->telegram, $chatId, $draft->fresh());
                $this->telegram->answerCallbackQuery($callbackId, 'Черновик обновлён. Сохранение в каталог отдельно.');
            } catch (\RuntimeException|ValidationException $error) {
                $this->telegram->sendMessage($chatId, 'Изменения не применены: '.mb_substr($error->getMessage(), 0, 1000));
                $this->telegram->answerCallbackQuery($callbackId, 'Карточка изменилась. Откройте редактор заново.');
            }
            $this->markCommandProcessed($update);

            return;
        }
        if (preg_match('/^draft:(fields|upload):(\d+)$/', $data, $manualInput) === 1) {
            $draft = ProductDraft::find((int) $manualInput[2]);
            $available = $draft?->status === 'pending_review' && $draft->isManualUpload();
            if ($available) {
                app(DraftTelegramInteractionState::class)->remember($chatId, (string) $update->telegram_user_id, $draft,
                    $manualInput[1] === 'fields' ? 'manual_fields' : 'manual_photos');
                $this->draftPresenter->sendInputPrompt($this->telegram, $chatId, $draft,
                    $manualInput[1] === 'fields'
                        ? 'Напишите изменения одним сообщением. Например: «цена: 15000 CZK», «состояние: б/у», «RAM: 32 GB». Для нового описания: «описание: …». Покажу изменения перед применением. /cancel — отмена.'
                        : 'Отправьте фото или альбом для этой карточки. Фото без сжатия можно отправить файлами. /cancel — отмена.');
            }
            $this->answerAndClose($callbackId, $update, $available ? 'Ожидаю материалы.' : 'Черновик уже обработан или не принимает ручной ввод.');

            return;
        }
        if (preg_match('/^draft:(enrich|webphotos):(\d+)$/', $data, $manualSearch) === 1 && $confirmed) {
            if (! $draft->isManualUpload()) {
                $this->answerAndClose($callbackId, $update, 'Это действие доступно только для загруженной вручную карточки.');

                return;
            }
            if ($manualSearch[1] === 'enrich') {
                $processing = app(DraftTelegramInteractionState::class)->remember($chatId, (string) $update->telegram_user_id,
                    $draft, 'processing', ['input_update_id' => $update->id, 'operation' => 'draft-enrich']);
                Cache::put("draft-enrich:{$draft->id}:queued", $update->id, 960);
                EnrichTelegramProductDraft::dispatch($draft->id, $update->id, $draft->telegram_update_id, $processing['token'])->afterCommit();
                $this->telegram->answerCallbackQuery($callbackId, 'Поиск характеристик поставлен в очередь.');
            } else {
                $query = json_encode($draft->only(['title', 'brand', 'model', 'color', 'condition', 'specifications']), JSON_UNESCAPED_UNICODE);
                $update->update(['text' => 'Найди в интернете именно этот товар и подготовь отдельную карточку с проверенной галереей. Исходную ручную карточку не меняй: '.$query,
                    'status' => 'received', 'processed_at' => null,
                    'payload' => [...$update->payload, '_manual_original_draft_id' => $draft->id]]);
                ProcessTelegramMessage::dispatch($update->id, freshConversation: true)->afterCommit();
                $this->telegram->answerCallbackQuery($callbackId, 'Подбор интернет-галереи поставлен в очередь.');
            }

            return;
        }

        if (preg_match('/^draft:details:(\d+)$/', $data, $details) === 1) {
            $draft = ProductDraft::find((int) $details[1]);
            if ($draft?->status === 'pending_review') {
                $this->draftPresenter->sendDetails($this->telegram, $chatId, $draft);
            }
            $this->answerAndClose($callbackId, $update, $draft?->status === 'pending_review' ? 'Полные данные карточки.' : 'Черновик уже обработан.');

            return;
        }
        if (preg_match('/^draft:edit:(\d+)$/', $data, $edit) === 1) {
            $draft = ProductDraft::query()->find((int) $edit[1]);
            if ($draft?->status === 'pending_review') {
                $this->draftPresenter->sendEditMenu($this->telegram, $chatId, $draft);
            }
            $this->answerAndClose($callbackId, $update, $draft?->status === 'pending_review' ? 'Выберите, что исправить.' : 'Черновик уже обработан.');

            return;
        }

        if (preg_match('/^draft:query:(\d+)$/', $data, $query) === 1) {
            $draft = ProductDraft::query()->find((int) $query[1]);
            if ($draft?->status === 'pending_review') {
                app(DraftTelegramInteractionState::class)->remember($chatId, (string) $update->telegram_user_id, $draft, 'new_search');
                $this->draftPresenter->sendInputPrompt($this->telegram, $chatId, $draft,
                    'Напишите полный уточнённый запрос: какой товар, цвет или комплектация нужны. Не только «другой цвет». Это новый поиск, а не ручная правка старых характеристик. Перед запуском покажу бюджет и попрошу подтверждение. Ввод действует 10 минут.');
            }
            $this->answerAndClose($callbackId, $update, $draft?->status === 'pending_review' ? 'Жду уточнённый запрос текстом.' : 'Черновик уже обработан.');

            return;
        }

        if (preg_match('/^draft:new-search:(\d+)$/', $data) === 1 && $confirmed) {
            if (! is_string($confirmedQuery) || trim($confirmedQuery) === '') {
                $this->telegram->answerCallbackQuery($callbackId, 'Нет уточнённого запроса. Введите его заново.');
                $this->markCommandProcessed($update);

                return;
            }
            $update->update([
                'text' => 'Найди в интернете товар и подготовь новый черновик: '.$confirmedQuery,
                'status' => 'received', 'processed_at' => null,
                'payload' => [...($update->payload ?? []), 'draft_revision' => [
                    'draft_id' => $state['draft_id'], 'generation' => $state['generation'],
                    'query' => $confirmedQuery, 'input_update_id' => $state['input_update_id'] ?? null,
                    'confirmed_budget' => $state['budget'],
                ]],
            ]);
            ProcessTelegramMessage::dispatch($update->id, freshConversation: true);
            $this->telegram->answerCallbackQuery($callbackId, 'Новый поиск поставлен в очередь.');
            $this->draftPresenter->sendControls($this->telegram, $chatId, $draft);
            $this->telegram->sendMessage($chatId, "🔎 Уточнённый поиск поставлен в очередь. Черновик #{$draft->id} сохранён без изменений.");

            return;
        }

        if (preg_match('/^draft:reject:(\d+)$/', $data, $reject) === 1 && ! $confirmed) {
            $draft = ProductDraft::query()->find((int) $reject[1]);
            if ($draft && $draft->status === 'pending_review') {
                $state = app(DraftTelegramInteractionState::class)->remember($chatId, (string) $update->telegram_user_id, $draft, 'confirmation', ['callback' => $data]);
                $this->draftPresenter->sendActionConfirmation($this->telegram, $chatId, $draft, $state['token'],
                    'Отклонить черновик?', 'Товар не будет добавлен. Для исправления результата вернитесь назад — новый поиск сейчас не запускается.', 'Да, отклонить');
                $this->telegram->answerCallbackQuery($callbackId, 'Подтвердите отклонение.');
                $this->markCommandProcessed($update);

                return;
            }
        }

        if (preg_match('/^draft:input-cancel:(\d+)$/', $data, $cancelInput) === 1) {
            $this->handleDraftReview((int) $cancelInput[1], $callbackId, $chatId, $update);

            return;
        }

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

        if (preg_match('/^draft:photos:(\d+)$/', $data, $photoMenuMatches) === 1) {
            $this->handleDraftPhotoMenu(
                draftId: (int) $photoMenuMatches[1],
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
                hint: $confirmedHint,
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
            if ($blockConfirmMatches[1] === 'confirm' && ! $confirmed) {
                $this->handleDraftSourceBlockPrompt((int) $blockConfirmMatches[2], $callbackId, $chatId, $update);

                return;
            }
            $this->handleDraftSourceBlockDecision(
                draftId: (int) $blockConfirmMatches[2],
                confirmed: $blockConfirmMatches[1] === 'confirm',
                callbackId: $callbackId,
                chatId: $chatId,
                update: $update,
                expectedHost: $state['host'] ?? null,
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

        if (preg_match('/^draft:continue-search:(\d+)$/', $data, $continueMatches) === 1) {
            $this->handleDraftContinueSearch(
                draftId: (int) $continueMatches[1],
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

        if (preg_match('/^guard:(show|wait):(\d+)$/', $data, $guardMatches) === 1) {
            $this->handleGuardianChoice($guardMatches[1], (int) $guardMatches[2], $callbackId, $chatId, $messageId, $update);

            return;
        }

        if (preg_match('/^draft:asis:(\d+)$/', $data, $asIsMatches) === 1) {
            $draft = ProductDraft::query()->find((int) $asIsMatches[1]);
            if ($draft?->status === 'pending_review') {
                $this->draftPresenter->sendAcceptAsIsConfirmation($this->telegram, $chatId, $draft);
                $this->telegram->answerCallbackQuery($callbackId, 'Проверьте, что останется несверенным.');
            } else {
                $this->telegram->answerCallbackQuery($callbackId, 'Черновик уже обработан.');
            }
            $this->markCommandProcessed($update);

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

        if (preg_match('/^db:changes:(confirm|cancel):(\d+)$/', $data, $databaseMatches) === 1) {
            $result = app(TelegramDatabaseOperator::class)->resolve((int) $databaseMatches[2], $databaseMatches[1], $update);
            $update->update(['status' => $result['status'], 'processed_at' => now()]);
            $this->telegram->answerCallbackQuery($callbackId, mb_substr($result['message'], 0, 200));
            if ($result['notify'] ?? false) {
                $this->removeCallbackKeyboard($chatId, $messageId);
                $this->telegram->sendMessage($chatId, $result['message']);
            }

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

        if (preg_match('/^draft:(add|approve|reject|asisok):(\d+)(?::(\d+))?$/', $data, $matches) !== 1) {
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

        $asIs = $matches[1] === 'asisok';
        if ($asIs && (int) ($matches[3] ?? 0) !== (int) $draft->telegram_update_id) {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик изменился после показа списка. Откройте его заново.');
            $update->update(['status' => 'already_processed', 'processed_at' => now()]);

            return;
        }
        $unreconciled = $asIs ? $this->draftPresenter->unreconciledFields($draft) : [];
        $approved = in_array($matches[1], ['add', 'approve', 'asisok'], true);

        try {
            $product = $approved
                ? $this->draftWorkflow->approve($draft, telegramReviewerId: $update->telegram_user_id, acceptUnreconciled: $asIs)
                : null;
        } catch (LowResolutionDraftMediaException|MissingDraftMediaException|UnverifiedDraftMediaException $exception) {
            $message = match (true) {
                $exception instanceof MissingDraftMediaException => 'В черновике нет фотографий.',
                $exception instanceof UnverifiedDraftMediaException => 'Проверка фотографий не состоялась. Публикация пока недоступна.',
                default => 'Фото не проходят текущий порог качества.',
            };
            $this->telegram->answerCallbackQuery($callbackId, 'Товар не добавлен. Дополнительный поиск не запущен.');
            $this->draftPresenter->sendApprovalProblem($this->telegram, $chatId, $draft, $message);
            $update->update(['status' => 'completed', 'error' => null, 'processed_at' => now()]);

            return;
        } catch (UnreconciledDraftSpecificationsException $exception) {
            // An old approval button is not consent to start another paid check.
            // Refresh the controls even if Telegram's short-lived toast expired.
            try {
                $this->telegram->answerCallbackQuery($callbackId, 'Поиск ещё не подготовил готовую карточку. Товар не добавлен.');
            } catch (Throwable $callbackError) {
                report($callbackError);
            }
            $this->draftPresenter->sendReview($this->telegram, $chatId, $draft);
            $update->update(['status' => 'completed', 'error' => null, 'processed_at' => now()]);

            return;
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
            'action' => $approved ? ($asIs ? 'add_draft_to_catalog_as_is' : 'add_draft_to_catalog') : 'reject_product_draft',
            'target_type' => ProductDraft::class,
            'target_id' => $draft->id,
            'payload' => ['draft_id' => $draft->id, ...($asIs ? ['accepted_unreconciled_fields' => $unreconciled] : [])],
            'result' => ['product_id' => $product?->id],
            'status' => 'completed',
            'executed_at' => now(),
        ]);

        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $result = $approved
            ? ($draft->isManualUpload() && data_get($draft->manualIntake(), 'target_product_id')
                ? "✅ Изменения товара #{$product->id} сохранены. Операция #{$operation->id}."
                : "✅ Товар #{$product->id} добавлен в каталог и активен. Операция #{$operation->id}.")
            : "✖ Черновик #{$draft->id} отклонён. Операция #{$operation->id}.";
        $this->telegram->answerCallbackQuery($callbackId, $result);

        if ($chatId !== '' && is_int($messageId)) {
            try {
                $this->telegram->removeInlineKeyboard($chatId, $messageId);
            } catch (Throwable $exception) {
                // The pressed message may already have been replaced or left unchanged;
                // the decision itself is saved, so only an unexpected failure is reported.
                if (! preg_match('/message (to edit not found|is not modified)/i', $exception->getMessage())) {
                    report($exception);
                }
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

    private function rejectStaleDraftCallback(
        string $data,
        string $callbackId,
        string $chatId,
        mixed $messageId,
        TelegramUpdate $update,
    ): bool {
        if (preg_match('/^draft:[a-z-]+:(\d+)(?::\d+)?$/', $data, $matches) !== 1) {
            return false;
        }

        $draft = ProductDraft::query()->find((int) $matches[1]);

        if (! $draft) {
            return false;
        }

        if (! $this->draftBelongsToContext($draft, $update)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Эта карточка относится к другому чату или оператору.');
            $this->markCommandProcessed($update);

            return true;
        }

        $trackedMessageIds = collect($draft->telegram_control_message_ids ?? [])
            ->map(fn (mixed $trackedId): int => (int) $trackedId)
            ->filter(fn (int $trackedId): bool => $trackedId > 0)
            ->values();

        // Legacy drafts created before control-message tracking cannot be
        // authenticated this way, so their existing buttons remain usable.
        // Every newly presented draft has at least one tracked control ID.
        if ($trackedMessageIds->isEmpty()) {
            return false;
        }

        $storedChatId = trim((string) $draft->telegram_review_chat_id);
        $currentMessageId = (int) $messageId;
        $matchesCurrentControl = $currentMessageId > 0
            && $trackedMessageIds->contains($currentMessageId)
            && ($storedChatId === '' || $storedChatId === $chatId);

        if ($matchesCurrentControl) {
            return false;
        }

        $this->telegram->answerCallbackQuery(
            $callbackId,
            'Эта кнопка устарела и относится к другой версии черновика.',
        );
        $update->update(['status' => 'completed', 'processed_at' => now()]);

        return true;
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
        $operation = AiOperation::query()->firstOrCreate(['idempotency_key' => "{$action}-draft-photo:{$update->id}"], [
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

        if ($operation->status === 'completed') {
            $this->markCommandProcessed($update);

            return;
        }

        try {
            $this->telegram->answerCallbackQuery($callbackId, "Операция поставлена в очередь: фото {$position}.");
            $this->draftPresenter->clearControls($this->telegram, $draft, $chatId);
            $this->telegram->sendMessage(
                $chatId,
                "⏳ {$labels[$action]} фото {$position} черновика #{$draft->id} · лимит {$limits[$action]} сек.",
                ['inline_keyboard' => [[['text' => 'Отменить обработку', 'callback_data' => "search:cancel:{$update->id}"]]]],
            );
            ProcessDraftPhotoActions::dispatch(
                $draft->id,
                [['action' => $action, 'media_id' => $media->id, 'position' => $position]],
                $update->id,
                $chatId,
                $operation->id,
                $draft->telegram_update_id,
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

    private function handleDraftPhotoMenu(
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

        $this->telegram->answerCallbackQuery($callbackId, 'Выберите действие с фотографиями.');
        $this->draftPresenter->sendPhotoMenu($this->telegram, $chatId, $draft);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
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
            $this->answerAndClose($callbackId, $update, 'Черновик не найден или уже обработан.');

            return;
        }

        if (blank($draft->primary_source_url)) {
            $this->answerAndClose($callbackId, $update, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        $queuedKey = "draft-gallery-retrain:{$draft->id}:queued";

        if (! Cache::add($queuedKey, $update->id, now()->addMinutes(35))) {
            $this->answerAndClose($callbackId, $update, 'Переобучение этого источника уже идёт.');

            return;
        }

        TrainDraftGalleryRecipe::dispatch($draft->id, $update->id, $chatId, $hint, $draft->telegram_update_id);
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
            $this->answerAndClose($callbackId, $update, 'Черновик не найден или уже обработан.');

            return;
        }

        if (blank($draft->primary_source_url)) {
            $this->answerAndClose($callbackId, $update, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        app(DraftTelegramInteractionState::class)->remember($chatId, (string) $update->telegram_user_id, $draft, 'source_hint');
        $this->telegram->answerCallbackQuery($callbackId, 'Опишите проблему следующим сообщением.');
        $this->draftPresenter->sendInputPrompt($this->telegram, $chatId, $draft,
            'Опишите одним текстовым сообщением, что не так со сбором фото. Подсказка будет передана тренеру. Действует 10 минут.');
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
            $this->answerAndClose($callbackId, $update, 'Черновик не найден или уже обработан.');

            return;
        }

        $host = blank($draft->primary_source_url) ? '' : ProductSourcePriority::host($draft->primary_source_url);

        if ($host === '') {
            $this->answerAndClose($callbackId, $update, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, 'Подтвердите бан источника.');
        $state = app(DraftTelegramInteractionState::class)->remember($chatId, (string) $update->telegram_user_id,
            $draft, 'confirmation', ['callback' => "draft:source-block-confirm:{$draft->id}", 'host' => $host]);
        $this->draftPresenter->sendActionConfirmation($this->telegram, $chatId, $draft, $state['token'],
            "Заблокировать {$host}?", 'Домен перестанет использоваться для поиска фотографий всех товаров.', 'Да, заблокировать этот домен');
        $update->update(['status' => 'completed', 'processed_at' => now()]);
    }

    private function handleDraftSourceBlockDecision(
        int $draftId,
        bool $confirmed,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
        ?string $expectedHost = null,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->answerAndClose($callbackId, $update, 'Черновик не найден или уже обработан.');

            return;
        }

        if (! $confirmed) {
            $this->telegram->answerCallbackQuery($callbackId, 'Отменено.');
            $this->draftPresenter->sendControls($this->telegram, $chatId, $draft);
            $update->update(['status' => 'completed', 'processed_at' => now()]);

            return;
        }

        $host = blank($draft->primary_source_url) ? '' : ProductSourcePriority::host($draft->primary_source_url);

        if ($expectedHost === null || ! hash_equals($expectedHost, $host)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Источник изменился. Проверьте домен и подтвердите заново.');
            $this->handleDraftSourceBlockPrompt($draftId, $callbackId, $chatId, $update);

            return;
        }

        if ($host === '') {
            $this->answerAndClose($callbackId, $update, 'У черновика нет ссылки на карточку товара.');

            return;
        }

        // Через тот же писатель, что и кнопка в панели: два оператора, одна
        // блокировка. Раньше здесь и в Filament были две разные записи, и
        // домен считался заблокированным только по одной из них.
        app(ProductGalleryRecipeRouter::class)->blockDomain(
            $host,
            "Заблокировано вручную оператором в Telegram (черновик #{$draft->id}).",
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

    private function handleDraftRestageGallery(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->answerAndClose($callbackId, $update, 'Черновик не найден или уже обработан.');

            return;
        }

        $queuedKey = "draft-gallery-restage:{$draft->id}:queued";

        if (! Cache::add($queuedKey, true, now()->addMinutes(35))) {
            $this->answerAndClose($callbackId, $update, 'Поиск фото уже идёт.');

            return;
        }

        RestageDraftGalleryPhotos::dispatch($draft->id, $chatId, $update->id, $draft->telegram_update_id);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $this->telegram->answerCallbackQuery($callbackId, 'Ищу другие фото без прежних источников.');
        $this->telegram->sendMessage(
            $chatId,
            "🔄 Черновик #{$draft->id}: исключаю прежние источники и похожие фото. Старая галерея останется, пока новая не будет готова.",
        );
    }

    private function handleDraftContinueSearch(
        int $draftId,
        string $callbackId,
        string $chatId,
        TelegramUpdate $update,
    ): void {
        $draft = ProductDraft::query()->find($draftId);

        if (! $draft || $draft->status !== 'pending_review') {
            $this->answerAndClose($callbackId, $update, 'Черновик не найден или уже обработан.');

            return;
        }

        $resumable = in_array($draft->gallery_search_stop_reason, [
            'cost_budget', 'time_budget', 'exhausted', 'specifications_unreconciled', 'stalled',
        ], true)
            || $draft->images_staged_at === null;

        if (! $resumable) {
            $this->answerAndClose($callbackId, $update, 'Поиск не был остановлен лимитом; продолжать нечего.');

            return;
        }

        $queuedKey = "draft-gallery-continue:{$draft->id}:queued";

        if (! Cache::add($queuedKey, true, now()->addMinutes(35))) {
            $this->answerAndClose($callbackId, $update, 'Продолжение поиска уже выполняется.');

            return;
        }

        $this->draftPresenter->clearForSearchContinuation($this->telegram, $draft);
        ContinueDraftGallerySearch::dispatch($draft->id, $chatId, $update->id, $draft->telegram_update_id);
        $update->update(['status' => 'completed', 'processed_at' => now()]);
        $this->telegram->answerCallbackQuery(
            $callbackId,
            'Продолжаю с новых и ещё не обработанных источников; прежние фото сохранены до успешной замены.',
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
            $this->answerAndClose($callbackId, $update, 'Черновик не найден или уже обработан.');

            return;
        }

        $queuedKey = "draft-gallery-topup:{$draft->id}:queued";

        if (! Cache::add($queuedKey, true, now()->addMinutes(16))) {
            $this->answerAndClose($callbackId, $update, 'Поиск дополнительных фото уже идёт.');

            return;
        }

        TopUpDraftGalleryPhotos::dispatch($draft->id, $chatId, $update->id, $draft->telegram_update_id);
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

        if ($target && (! hash_equals((string) $target->chat_id, $chatId)
            || ! hash_equals((string) $target->telegram_user_id, (string) $update->telegram_user_id))) {
            $this->telegram->answerCallbackQuery($callbackId, 'Эта операция создана в другом чате или другим пользователем.');
            $update->update(['status' => 'rejected', 'processed_at' => now()]);

            return;
        }

        $activePhotoAction = $target && AiOperation::query()->where('telegram_update_id', $target->id)
            ->whereIn('action', ['enhance_draft_photo', 'replace_draft_photo', 'delete_draft_photo', 'process_draft_photos', 'refind_catalog_photos'])
            ->whereIn('status', ['running', 'failed'])->exists();
        if (! $target || ($target->processed_at !== null && ! $activePhotoAction)) {
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

    /** The buttons under the request guardian's "search is slow" notice. */
    private function handleGuardianChoice(string $choice, int $targetUpdateId, string $callbackId, string $chatId, mixed $messageId, TelegramUpdate $update): void
    {
        $target = TelegramUpdate::query()->find($targetUpdateId);
        if (! $target || ! hash_equals((string) $target->chat_id, $chatId)
            || ! hash_equals((string) $target->telegram_user_id, (string) $update->telegram_user_id)) {
            $this->answerAndClose($callbackId, $update, 'Этот поиск создан в другом чате или другим пользователем.');

            return;
        }
        if ($target->processed_at !== null || $target->cancel_requested_at !== null) {
            $this->answerAndClose($callbackId, $update, 'Поиск уже завершён.');
            $this->removeCallbackKeyboard($chatId, $messageId);

            return;
        }
        if ($choice === 'wait') {
            Cache::put('request-guard:wait:'.$target->id, now()->addMinutes(10)->getTimestamp(), now()->addMinutes(15));
            $this->answerAndClose($callbackId, $update, 'Жду ещё 10 минут.');
            $this->removeCallbackKeyboard($chatId, $messageId);

            return;
        }
        $shown = app(RequestGuardian::class)->showFound($target, '📦 Показываю, что уже найдено, остальной поиск остановлен.');
        $this->answerAndClose($callbackId, $update, $shown ? 'Показываю найденное.' : 'Пока показать нечего.');
        if ($shown) {
            $this->removeCallbackKeyboard($chatId, $messageId);
        }
    }

    private function handleProductDeletion(
        string $action,
        int $operationId,
        string $callbackId,
        string $chatId,
        mixed $messageId,
        TelegramUpdate $update,
    ): void {
        try {
            $result = app(TelegramCatalogDeletion::class)->resolve($operationId, $action, $update);
        } catch (Throwable $exception) {
            report($exception);
            // An after-commit delivery failure must not turn a completed deletion into a retry.
            $operation = AiOperation::find($operationId);
            if ($operation?->status === 'completed') {
                $result = ['status' => 'completed', 'message' => 'Товар удалён. Операция #'.$operationId.'.', 'notify' => true];
            } else {
                $result = ['status' => 'failed', 'message' => 'Не удалось удалить товар. Ошибка записана в журнал.'];
            }
        }
        $update->update(['status' => $result['status'], 'processed_at' => now()]);
        $this->telegram->answerCallbackQuery($callbackId, mb_substr($result['message'], 0, 200));
        if (! ($result['notify'] ?? false)) {
            return;
        }
        $this->removeCallbackKeyboard($chatId, $messageId);
        if ($chatId !== '') {
            $this->telegram->sendMessage($chatId, $result['message'], $this->mainKeyboard());
        }
    }

    private function sendDrafts(string $chatId, string $telegramUserId): void
    {
        $drafts = ProductDraft::query()->where('requested_by_telegram_user_id', $telegramUserId)->latest('id')->limit(10)->get();
        if ($drafts->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Черновиков пока нет.', $this->mainKeyboard());

            return;
        }

        $lines = $drafts->map(fn (ProductDraft $draft): string => "#{$draft->id} · {$draft->title} · ".match ($draft->status) {
            'pending_review' => 'ожидает решения', 'approved' => 'добавлен', 'rejected' => 'отклонён', default => 'обрабатывается',
        });
        $buttons = $drafts->where('status', 'pending_review')->map(fn (ProductDraft $draft): array => [
            ['text' => "Открыть #{$draft->id}", 'callback_data' => "draft:open:{$draft->id}:{$draft->telegram_update_id}"],
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
            /catalog — последние товары без обращения к AI
            /catalog название — поиск в вашей базе без AI
            /add — загрузить свой товар: фото и описание
            /product номер — открыть товар
            /edit номер — редактировать через черновик
            /delete номер — удалить после подтверждения
            /cancel — отменить ожидаемый ввод
            /admin — ручное создание и редактирование без обращения к AI
            /drafts — черновики
            /status — состояние системы без обращения к AI
            /errors — ошибки
            /new — очистить контекст диалога
            /reset — отменить зависшие запросы и очистить контекст (если поиск завис, например когда воркер очереди был выключен)
            /report — отчёт для разработчика одним файлом: журнал поисков, ошибки, выученные правила сайтов за 7 дней (без ключей)
            /report full — то же плюс копия базы без ключей (если не влезет в лимит Telegram — скачайте в админке)
            TEXT;
    }

    private function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '🔎 Найти товар'], ['text' => '📦 Каталог']],
                [['text' => '📥 Загрузить товар']],
                [['text' => '📋 Черновики'], ['text' => '🖥 Статус']],
                [['text' => '⚠️ Ошибки'], ['text' => 'ℹ️ Помощь']],
                [['text' => '🛠 Админка'], ['text' => '🔄 Сброс']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * Consumes a pending "💬 Переобучить с подсказкой" prompt (see
     * handleDraftSourceHintPrompt) - the next plain-text message in that chat
     * is the operator's hint, not a new product search, so it must never
     * reach ServerAssistantAgent without explicit confirmation. Returns false when nothing is
     * pending, so the caller falls through to the normal AI dispatch.
     */
    /** Up to two words and no digit: a reply to a question, not a product. */
    private function readsAsShortReply(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && preg_match('/\d/u', $text) !== 1
            && count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) <= 2;
    }

    private function handlePendingDraftInput(string $text, string $chatId, TelegramUpdate $update): bool
    {
        $states = app(DraftTelegramInteractionState::class);
        $state = $states->get($chatId, (string) $update->telegram_user_id);
        // A button confirmation is not answered by text: a message during one is
        // the operator's next request and goes its normal way (live #153-#155),
        // while the confirmation keeps waiting on its buttons. The exception is
        // a short reply without a model number - "да", "ok", "подтверждаю" - which
        // is an attempt to answer the button, not a product: it gets the reminder
        // and never a paid search.
        if (($state['kind'] ?? '') === 'confirmation' && $this->readsAsShortReply($text)) {
            $this->telegram->sendMessage($chatId, 'Подтвердить или отменить можно только кнопкой под сообщением. /cancel — отменить действие. Текст не отправлен в новый поиск.');
            $this->markCommandProcessed($update);

            return true;
        }
        if (($state['kind'] ?? '') === 'processing') {
            $this->telegram->sendMessage($chatId, 'Карточка сейчас обрабатывается. Дождитесь результата или /cancel. Текст не отправлен в новый поиск.');
            $this->markCommandProcessed($update);

            return true;
        }
        if (($state['kind'] ?? '') === 'manual_photos') {
            $this->telegram->sendMessage($chatId, 'Сейчас ожидаю фото для выбранной карточки. Отправьте фото/альбом или /cancel, затем выберите «Исправить данные».');
            $this->markCommandProcessed($update);

            return true;
        }
        if (($state['kind'] ?? '') === 'manual_fields') {
            $state = $states->consume($chatId, (string) $update->telegram_user_id, $state['token']);
            $draft = $state ? ProductDraft::find($state['draft_id']) : null;
            if ($draft && $draft->isManualUpload() && $draft->status === 'pending_review'
                && (int) $draft->telegram_update_id === (int) $state['generation'] && $this->draftBelongsToContext($draft, $update)) {
                if ($this->draftHasQueuedWork($draft->id)) {
                    $states->remember($chatId, (string) $update->telegram_user_id, $draft, 'manual_fields');
                    $this->telegram->sendMessage($chatId, 'Карточка уже обрабатывается. Изменения пока не запущены. Дождитесь результата.');
                    $this->markCommandProcessed($update);

                    return true;
                }
                $processing = $states->remember($chatId, (string) $update->telegram_user_id, $draft, 'processing',
                    ['input_update_id' => $update->id, 'operation' => 'draft-fields']);
                Cache::put("draft-fields:{$draft->id}:queued", $update->id, 240);
                ProposeTelegramDraftEdit::dispatch($draft->id, $update->id, $draft->telegram_update_id, $text, $processing['token'])->afterCommit();
                $this->telegram->sendMessage($chatId, '✏️ Готовлю изменения для просмотра. Интернет-поиск не запускается.');
            } else {
                $this->telegram->sendMessage($chatId, 'Карточка уже недоступна. Новый поиск не запущен.');
                $this->markCommandProcessed($update);
            }

            return true;
        }
        if (! $state || ! in_array($state['kind'], ['source_hint', 'new_search'], true)) {
            return false;
        }
        $state = $states->consume($chatId, (string) $update->telegram_user_id, $state['token']);
        if (! $state) {
            $this->markCommandProcessed($update);

            return true;
        }

        $draft = ProductDraft::query()->find($state['draft_id']);

        if (! $draft || $draft->status !== 'pending_review' || ($state['kind'] === 'source_hint' && blank($draft->primary_source_url))
            || (int) $draft->telegram_update_id !== (int) $state['generation'] || ! $this->draftBelongsToContext($draft, $update)) {
            $this->telegram->sendMessage($chatId, 'Черновик, для которого ожидался текст, уже изменён или недоступен. Новый поиск не запущен.');
            $update->update(['status' => 'ignored', 'processed_at' => now()]);

            return true;
        }

        $isQuery = $state['kind'] === 'new_search';
        $limit = $isQuery ? 4000 : 1000;
        if (mb_strlen($text) > $limit) {
            $states->remember($chatId, (string) $update->telegram_user_id, $draft, $state['kind']);
            $this->telegram->sendMessage($chatId, "Текст слишком длинный: максимум {$limit} символов. Сократите его — ничего не запущено.");
            $this->markCommandProcessed($update);

            return true;
        }
        $this->requestDraftActionConfirmation($draft, $update,
            $isQuery ? "draft:new-search:{$draft->id}" : "draft:source-retrain:{$draft->id}",
            [$isQuery ? 'query' : 'hint' => $text, 'input_update_id' => $update->id]);
        $update->update(['status' => 'completed', 'processed_at' => now()]);

        return true;
    }

    private function markCommandProcessed(TelegramUpdate $update): void
    {
        $update->update(['status' => 'command', 'processed_at' => now()]);
    }

    /** Answers the press and closes the update: an open one would count as hung on /reset. */
    private function answerAndClose(string $callbackId, TelegramUpdate $update, string $text): void
    {
        $this->telegram->answerCallbackQuery($callbackId, $text);
        $this->markCommandProcessed($update);
    }

    private function requestDraftActionConfirmation(ProductDraft $draft, TelegramUpdate $update, string $callback, array $extra = []): void
    {
        $terms = app(DraftTelegramActionConfirmation::class)->describe($callback);
        $state = app(DraftTelegramInteractionState::class)->remember((string) $update->chat_id, (string) $update->telegram_user_id,
            $draft, 'confirmation', [...$extra, 'callback' => $callback, 'budget' => $terms['budget']]);
        $preview = $extra['query'] ?? $extra['hint'] ?? null;
        $description = $terms['description'].($preview !== null ? "\n\nВаш текст:\n".mb_substr($preview, 0, 2000)
            .(mb_strlen($preview) > 2000 ? "\n… (показано начало; будет передан весь ваш текст)" : '') : '');
        $this->draftPresenter->sendActionConfirmation($this->telegram, (string) $update->chat_id, $draft,
            $state['token'], $terms['title'], $description, $terms['label']);
    }

    private function draftHasQueuedWork(int $draftId): bool
    {
        // Use the same markers that the existing jobs clear on completion/failure.
        // No new persistent lock or independent timeout is introduced by the UI.
        foreach (['draft-gallery-retrain', 'draft-gallery-restage', 'draft-gallery-continue', 'draft-gallery-topup', 'draft-photo-actions', 'draft-fields', 'draft-enrich', 'draft-upload'] as $operation) {
            if (Cache::has("{$operation}:{$draftId}:queued")) {
                return true;
            }
        }

        return false;
    }

    private function clearManualCreate(string $chat, string $user): void
    {
        Cache::forget("telegram-manual-create:{$chat}:{$user}");
        Cache::forget("telegram-manual-create:{$chat}:{$user}:expires_at");
    }

    private function forgetExpiredInput(string $chat, string $user): void
    {
        app(DraftTelegramInteractionState::class)->clear($chat, $user);
        $this->clearManualCreate($chat, $user);
    }

    private function expiredManualInput(string $chat, string $user): bool
    {
        $createKey = "telegram-manual-create:{$chat}:{$user}";
        $deadline = Cache::get($createKey.':expires_at');

        return app(DraftTelegramInteractionState::class)->expired($chat, $user) !== null
            || (! Cache::has($createKey) && is_numeric($deadline) && (int) $deadline <= now()->getTimestamp());
    }

    private function draftBelongsToContext(ProductDraft $draft, TelegramUpdate $update): bool
    {
        return app(DraftAccess::class)->belongsToContext($draft, $update);
    }

    private function openCatalogEditor(int $productId, TelegramUpdate $update, ?int $variantId = null, int $page = 0): void
    {
        $product = Product::find($productId);
        try {
            throw_unless($product, \RuntimeException::class, 'Товар не найден.');
            $total = $product->variants()->count();
            if ($variantId === null && $total > 1) {
                $pages = (int) ceil($total / 20);
                $page = min($page, $pages - 1);
                $buttons = $product->variants()->orderByDesc('is_default')->orderBy('id')->forPage($page + 1, 20)->get()
                    ->map(function ($variant) use ($productId): array {
                        $label = collect(['#'.$variant->id, $variant->name ?: $variant->sku, $variant->color,
                            $variant->price !== null ? $variant->price.' '.$variant->currency : null])->filter()->implode(' · ');

                        return [['text' => mb_substr($label, 0, 64), 'callback_data' => "product:tgedit:{$productId}:{$variant->id}"]];
                    })->all();
                $navigation = [];
                if ($page > 0) {
                    $navigation[] = ['text' => '← Назад', 'callback_data' => 'product:variants:'.$productId.':'.($page - 1)];
                }
                if ($page + 1 < $pages) {
                    $navigation[] = ['text' => 'Далее →', 'callback_data' => 'product:variants:'.$productId.':'.($page + 1)];
                }
                if ($navigation !== []) {
                    $buttons[] = $navigation;
                }
                $this->telegram->sendMessage((string) $update->chat_id,
                    "Товар #{$productId}: {$product->title}\nВыберите комплектацию для редактирования. Общие название и описание относятся ко всему товару."
                    .($pages > 1 ? "\nСтраница ".($page + 1).'/'.$pages : ''), ['inline_keyboard' => $buttons]);
                $this->markCommandProcessed($update);

                return;
            }
            $draft = app(TelegramCatalogEditor::class)->createDraft($product, $update, $variantId);
            $this->draftPresenter->sendReview($this->telegram, (string) $update->chat_id, $draft);
        } catch (\RuntimeException $error) {
            $this->telegram->sendMessage((string) $update->chat_id, 'Редактор не открыт: '.$error->getMessage());
        }
        $this->markCommandProcessed($update);
    }

    private function prepareCatalogDeletion(int $productId, TelegramUpdate $update): void
    {
        $result = json_decode((string) (new PrepareProductDeletion($update))
            ->handle(new \Laravel\Ai\Tools\Request(['product_id' => $productId])), true);
        if (! ($result['ok'] ?? false)) {
            $this->telegram->sendMessage((string) $update->chat_id, 'Товар не найден или операция уже обработана.');
        } else {
            $id = $result['operation_id'];
            $this->telegram->sendMessage((string) $update->chat_id,
                "Удалить товар #{$productId}: ".$result['product']['title']."?\nУдаление товара, вариантов и фото необратимо. Подтверждение действует 10 минут.",
                ['inline_keyboard' => [[
                    ['text' => 'Удалить навсегда', 'callback_data' => "product:delete:confirm:{$id}"],
                    ['text' => 'Отмена', 'callback_data' => "product:delete:cancel:{$id}"],
                ]]]);
        }
        $this->markCommandProcessed($update);
    }
}
