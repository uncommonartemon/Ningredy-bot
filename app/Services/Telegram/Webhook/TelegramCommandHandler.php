<?php

namespace App\Services\Telegram\Webhook;

use App\Ai\Tools\GetSystemStatus;
use App\Ai\Tools\PrepareProductDeletion;
use App\Ai\Tools\SetProductActive;
use App\Jobs\AuditCharacteristics;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\SendDiagnosticReport;
use App\Models\AiOperation;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\CatalogTelegramNavigation;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\PhotoReadingCheck;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramCatalogDeletion;
use App\Services\Telegram\TelegramCatalogEditor;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramConversationScope;
use App\Services\Telegram\TelegramDatabaseOperator;
use App\Services\Telegram\TelegramProductUploadIntake;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;

/** Text commands and the panel's keyboard buttons; what is not one goes on to the assistant. */
class TelegramCommandHandler
{
    use ConversesWithTheOperator;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly DraftTelegramPresenter $draftPresenter,
    ) {}

    public function handle(string $text, string $chatId, string $telegramUserId, TelegramUpdate $update): bool
    {
        // Module 107: after «✏️ Исправить» under a photo's reading, the next plain message of this
        // operator is the correction. A command or a keyboard button is not one: it ends the wait.
        $readingCheck = app(PhotoReadingCheck::class);
        if (str_starts_with($text, '/') || in_array($text, $this->keyboardTexts(), true)) {
            $readingCheck->forget($chatId, $telegramUserId);
        } elseif (($reply = $readingCheck->correct($chatId, $telegramUserId, $text)) !== null) {
            $this->telegram->sendMessage($chatId, $reply);
            $this->markCommandProcessed($update);

            return true;
        }
        $createKey = "telegram-manual-create:{$chatId}:{$telegramUserId}";
        // /errors is answered by the assistant, so it must not stay behind as the
        // awaited hint, refined query or manual edit.
        if (preg_match('/^\/(?:edit|delete|product|catalog|drafts|find|new|reset|errors|audit)(?:@\w+)?(?:\s|$)/iu', $text) === 1
            || in_array($text, ['📦 Каталог', '📋 Черновики', '🔎 Найти товар', '🔄 Сброс', '⚠️ Ошибки', '🧹 Аудит характеристик'], true)) {
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

        if (preg_match('/^\/draft(?:@\w+)?\s+#?(\d+)$/iu', $text, $showDraft) === 1) {
            $this->showDraft((int) $showDraft[1], $chatId, $update);
            $this->markCommandProcessed($update);

            return true;
        }

        if ($text === '🔎 Найти товар') {
            $this->telegram->sendMessage($chatId, 'Опишите товар. Я сначала проверю каталог, а если совпадения нет — поищу в интернете.', $this->mainKeyboard());
            $this->markCommandProcessed($update);

            return true;
        }

        if (preg_match('/^\/status(?:@\w+)?$/iu', $text) === 1 || $text === '🖥 Статус') {
            $status = json_decode(app(GetSystemStatus::class)->handle(new Request([])), true);
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

        // The characteristic audit (decision of 2026-11-13): the model reads the whole dictionary,
        // fixes what it is sure of and sends the doubtful ones here with buttons.
        if (preg_match('/^\/audit(?:@\w+)?$/iu', $text) === 1 || $text === '🧹 Аудит характеристик') {
            $this->telegram->sendMessage($chatId, "🧹 Аудит характеристик начат: модель читает все характеристики с их значениями и товарами и сама проверяет, где не уверена. Это до нескольких минут.\nБесспорное исправлю сам, сомнительное пришлю сюда с кнопками.", $this->mainKeyboard());
            AuditCharacteristics::dispatch($chatId);
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

    /** One draft by its number, without the assistant: its card, or what became of it. */
    private function showDraft(int $draftId, string $chatId, TelegramUpdate $update): void
    {
        $draft = ProductDraft::query()->find($draftId);
        if (! $draft || ! $this->draftBelongsToContext($draft, $update)) {
            $this->telegram->sendMessage($chatId, "Черновика #{$draftId} нет в этом чате. Все черновики: /drafts", $this->mainKeyboard());

            return;
        }
        if ($draft->status !== 'pending_review') {
            $this->telegram->sendMessage($chatId, $draft->status === 'approved' && $draft->approved_product_id
                ? "Черновик #{$draftId} уже добавлен: товар #{$draft->approved_product_id}. Открыть: /product {$draft->approved_product_id}"
                : "Черновик #{$draftId} отклонён.".($draft->rejection_reason ? ' '.mb_substr((string) $draft->rejection_reason, 0, 500) : ''), $this->mainKeyboard());

            return;
        }
        if ($this->draftHasQueuedWork($draft->id)) {
            $this->telegram->sendMessage($chatId, "Черновик #{$draftId} сейчас обрабатывается. Карточка придёт сама.", $this->mainKeyboard());

            return;
        }
        $this->clearManualCreate($chatId, (string) $update->telegram_user_id);
        app(DraftTelegramInteractionState::class)->clear($chatId, (string) $update->telegram_user_id);
        $this->draftPresenter->clearForSearchContinuation($this->telegram, $draft);
        $this->draftPresenter->sendReview($this->telegram, $chatId, $draft);
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
            • «Покажи черновик 213», «В черновике 213 добавь TDP 115 Вт»
            • «В черновике 213 нет скорости записи» — поищу в интернете
            • «В товаре #12 поставь частоту 2490 MHz»
            • «Выключи айскет», «Забань этот сайт», «Поставь бюджет поиска 1 доллар»
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
            /draft номер — открыть черновик без обращения к AI
            /status — состояние системы без обращения к AI
            /errors — ошибки
            /audit — аудит характеристик: дубли и группы; сомнительное спрошу кнопками
            /new — очистить контекст диалога
            /reset — отменить зависшие запросы и очистить контекст (если поиск завис, например когда воркер очереди был выключен)
            /report — отчёт для разработчика одним файлом: журнал поисков, ошибки, выученные правила сайтов за 7 дней (без ключей)
            /report full — то же плюс копия базы без ключей (если не влезет в лимит Telegram — скачайте в админке)
            TEXT;
    }

    /** @return list<string> what the buttons of the main keyboard send */
    private function keyboardTexts(): array
    {
        return array_column(array_merge(...$this->mainKeyboard()['keyboard']), 'text');
    }

    public function openCatalogEditor(int $productId, TelegramUpdate $update, ?int $variantId = null, int $page = 0): void
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

    public function prepareCatalogDeletion(int $productId, TelegramUpdate $update): void
    {
        $result = json_decode((string) (new PrepareProductDeletion($update))
            ->handle(new Request(['product_id' => $productId])), true);
        if (! ($result['ok'] ?? false)) {
            $this->telegram->sendMessage((string) $update->chat_id, 'Товар не найден или операция уже обработана.');
        } else {
            [$question, $buttons] = app(TelegramCatalogDeletion::class)->confirmation(AiOperation::query()->findOrFail($result['operation_id']));
            $this->telegram->sendMessage((string) $update->chat_id, $question, $buttons);
        }
        $this->markCommandProcessed($update);
    }

    /** The card's own button: to sleep or back on sale, with the audit the assistant's tool keeps. */
    public function setCatalogSale(int $productId, bool $onSale, TelegramUpdate $update): void
    {
        $product = Product::query()->find($productId);
        if ($product === null) {
            $this->telegram->sendMessage((string) $update->chat_id, 'Товар не найден: возможно, он уже удалён.');
        } else {
            (new SetProductActive($update))->handle(new Request(['product_id' => $productId, 'active' => $onSale]));
            $navigation = app(CatalogTelegramNavigation::class);
            $product->refresh();
            $this->telegram->sendMessage((string) $update->chat_id, $navigation->saleNote($product), $navigation->product($product));
        }
        $this->markCommandProcessed($update);
    }
}
