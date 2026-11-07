<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\TranscribeTelegramPhoto;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductSearchIntentDetector;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramOperatorAccess;
use App\Services\Telegram\TelegramProductUploadIntake;
use App\Services\Telegram\TelegramWebhookDelivery;
use App\Services\Telegram\Webhook\ConversesWithTheOperator;
use App\Services\Telegram\Webhook\TelegramCallbackHandler;
use App\Services\Telegram\Webhook\TelegramCommandHandler;
use App\Services\Telegram\Webhook\TelegramPendingInputHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The webhook's door: who is asking and where the update goes. Commands, buttons and
 * text a card is waiting for each have a handler of their own.
 */
class TelegramWebhookController extends Controller
{
    use ConversesWithTheOperator;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly DraftTelegramPresenter $draftPresenter,
        private readonly TelegramCommandHandler $commands,
        private readonly TelegramCallbackHandler $callbacks,
        private readonly TelegramPendingInputHandler $pendingInput,
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
            $this->callbacks->handle($callback, $update);

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
            // A photographed label is a search (module 71): a single photo goes to the
            // manual intake unread only when the caption refuses the search (/add, «не
            // ищи») or the setting says «всегда ручная загрузка»; otherwise it is read
            // first and the reader says what the operator wants.
            if (Cache::has($createKey) || $hasImageDocument || filled(data_get($payload, 'message.media_group_id'))
                || app(ProductSearchIntentDetector::class)->photoIsManualBeforeReading($caption, app(AiSettings::class)->photoCaptionRouting())) {
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

        if ($this->commands->handle(trim($text), (string) $chatId, (string) $telegramUserId, $update)) {
            return response()->json(['ok' => true]);
        }

        if ($this->pendingInput->handle(trim($text), (string) $chatId, $update)) {
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
}
