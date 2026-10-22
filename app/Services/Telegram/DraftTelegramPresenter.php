<?php

namespace App\Services\Telegram;

use App\Jobs\ContinueDraftGallerySearch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Catalog\AttributePresentation;
use App\Services\Products\PhotoSourceNotice;
use App\Services\Products\ProductCatalogIdentity;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class DraftTelegramPresenter
{
    public function __construct(
        private readonly DraftTelegramMessageLifecycle $messageLifecycle,
    ) {}

    public function sendReview(
        TelegramClient $telegram,
        string $chatId,
        ProductDraft $draft,
        string $usageFootnote = '',
    ): void {
        $draft->load('media');
        if ($usageFootnote === '' && $draft->telegram_update_id) {
            $usageFootnote = app(RequestUsageFootnote::class)->for((int) $draft->telegram_update_id, includeSearchDuration: true);
        }
        if ($draft->status === 'pending_review'
            && $draft->gallery_status !== 'complete'
            && ! $draft->hasReadyVerifiedPartial()
            && in_array($draft->gallery_search_stop_reason, ['time_budget', 'exhausted'], true)
            && ! app(ProductSearchCancellation::class)->isCancelled($draft->telegram_update_id)
            && app(ProductSearchCostBudget::class)->hasRemaining($draft->telegram_update_id)) {
            // No paused card or approval controls while the original money remains.
            // A delivery checkpoint may temporarily claim "delivering" even
            // when another cycle is already queued. Keep that state honest
            // without dispatching the same continuation a second time.
            $claimed = TelegramUpdate::query()->whereKey($draft->telegram_update_id)
                ->whereNull('cancel_requested_at')->where('status', '!=', 'cancelled')
                ->update(['status' => 'processing', 'processed_at' => null]);
            if (! $claimed) {
                return;
            }
            if (Cache::add("draft-gallery-continue:{$draft->id}:queued", true, 2100)) {
                ContinueDraftGallerySearch::dispatch(
                    $draft->id, $chatId, $draft->telegram_update_id, $draft->telegram_update_id,
                )->afterCommit();
            }

            return;
        }
        if ($this->reconciliationPending($draft)) {
            $budgetStopped = in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'stalled'], true);
            $reason = match ($draft->gallery_search_stop_reason) {
                'cost_budget' => 'Денежный лимит поиска достигнут.',
                'time_budget' => 'Время поиска закончилось.',
                'stalled' => 'Поиск фото остановлен до завершения.',
                default => 'Не удалось завершить проверку карточки по выбранному источнику после автоматического восстановления.',
            };
            if (! $budgetStopped && ! app(AiSettings::class)->specificationReconciliationEnabled()) {
                $reason = 'Сборка карточки выключена в настройках AI. Проверка не запускалась; повтор поиска не поможет. Найденное сохранено.';
            }
            // A photo preview is a separate result from a publishable card.
            // Only reviewed files are shown; pending downloads stay internal.
            // Do not clear a successfully sent preview here: a delivery retry
            // replays its durable receipt when only the controls failed.
            $paths = $draft->verifiedPhotoPaths();
            if ($paths !== []) {
                $sourceUrl = trim((string) ($draft->photo_source_context['url'] ?? $draft->primary_source_url));
                $photoNotice = $this->photoNotice($draft);
                $caption = '📷 Частичный результат: '.count($paths)." фото\n"
                    .'Запрос: '.mb_substr((string) $draft->title, 0, 180)."\n\n"
                    .$reason."\nФото найдены; точная комплектация и характеристики ещё не подтверждены. Публикация недоступна."
                    .($photoNotice !== '' ? "\nℹ️ ".$photoNotice : '')
                    .($sourceUrl !== '' && mb_strlen($sourceUrl) <= 350 ? "\n🔗 ".$sourceUrl : '')
                    .$usageFootnote;
                $response = $telegram->sendMediaGroupFiles($chatId, $paths, $caption);
                $this->messageLifecycle->rememberReviewResponse($telegram, $draft, $chatId, $response, true, $caption);
                $this->messageLifecycle->replaceControlMessage(
                    $telegram, $draft, $chatId,
                    "📷 Частичный результат поиска #{$draft->id}: показаны найденные фото.\n"
                        .'Карточка не готова к публикации.'
                        .($photoNotice !== '' ? "\nℹ️ ".$photoNotice : '')
                        .($budgetStopped ? ' Продолжение выдаст новый бюджет поиска.' : '')
                        .($sourceUrl !== '' ? "\nИсточник фото: ".$sourceUrl : '')
                        ."\nВыберите действие:",
                    $this->controlMarkup($draft),
                );

                return;
            }
            // No checked photo files: keep the honest stopped-search message.
            $this->messageLifecycle->clearForRefresh($telegram, $draft);
            $text = "⏸ Поиск #{$draft->id} не завершён: {$draft->title}\n"
                .$reason."\nНайденные фото сохранены: {$draft->media->count()}. Товар не отправлен на подтверждение."
                .($budgetStopped ? "\nПродолжение выдаст новый бюджет поиска." : '')
                .$usageFootnote;
            $this->messageLifecycle->replaceControlMessage(
                $telegram, $draft, $chatId, $text, $this->controlMarkup($draft),
            );

            return;
        }
        $paths = $this->localMediaPaths($draft);

        $caption = $this->caption($draft, $usageFootnote);

        if ($paths !== []) {
            $response = $telegram->sendMediaGroupFiles($chatId, $paths, $caption);
        } else {
            $response = $telegram->sendMessage($chatId, $caption);
        }

        $this->messageLifecycle->rememberReviewResponse($telegram, $draft, $chatId, $response, $paths !== [], $caption);

        $this->sendControls($telegram, $chatId, $draft);
    }

    /** @return array<int, string> */
    private function localMediaPaths(ProductDraft $draft): array
    {
        return $draft->media
            ->sortBy([
                ['is_primary', 'desc'],
                ['sort_order', 'asc'],
            ])
            ->filter(fn ($media): bool => filled($media->disk) && filled($media->path))
            ->map(fn ($media): string => Storage::disk($media->disk)->path($media->path))
            ->filter(fn (string $path): bool => is_file($path) && is_readable($path))
            ->take(10)
            ->values()
            ->all();
    }

    public function finalizeRejection(TelegramClient $telegram, ProductDraft $draft): bool
    {
        return $this->messageLifecycle->finalizeRejectedReview($telegram, $draft);
    }

    /**
     * A control message (photo-selection menu, ...) is used up the moment its
     * keyboard is acted on - stripping the keyboard and leaving the message
     * behind (the old pattern) just piles up dead prompts in the chat.
     */
    public function clearControls(TelegramClient $telegram, ProductDraft $draft, string $chatId): void
    {
        $this->messageLifecycle->clearControlMessages($telegram, $draft, $chatId);
    }

    public function clearForSearchContinuation(TelegramClient $telegram, ProductDraft $draft): void
    {
        $this->messageLifecycle->clearForRefresh($telegram, $draft);
    }

    public function sendControls(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        // The card is written in English (CatalogLocales::CONTENT_SOURCE);
        // there is no Russian translation to prefer any more.
        $description = trim((string) $draft->description);
        // Truncate raw text before escaping; never cut an HTML entity/tag.
        $description = mb_substr($description, 0, 1800);
        while (strlen(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) > 3500) {
            $description = mb_substr($description, 0, max(0, mb_strlen($description) - 100));
        }
        $description = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $details = $description !== '' && ! $this->reconciliationPending($draft)
            ? "\n\n<blockquote expandable>".$description."</blockquote>\n" : '';
        $photoNotice = $this->photoNotice($draft);
        $details .= $photoNotice !== '' ? "\nℹ️ ".htmlspecialchars($photoNotice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\n" : '';
        $catalogNotice = $this->catalogNotice($draft);
        $details .= $catalogNotice !== '' ? "\n⚠️ ".htmlspecialchars($catalogNotice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\n" : '';

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "📋 Итого: черновик #{$draft->id}".$details."\nВыберите действие:",
            $this->controlMarkup($draft),
            parseMode: 'HTML',
        );
    }

    public function sendActionConfirmation(TelegramClient $telegram, string $chatId, ProductDraft $draft, string $token, string $title, string $description, string $label): array
    {
        return $this->messageLifecycle->replaceControlMessage($telegram, $draft, $chatId,
            "{$title}\nЧерновик #{$draft->id}: {$draft->title}\n\n{$description}", ['inline_keyboard' => [
                [['text' => $label, 'callback_data' => "draft:confirm:{$draft->id}:{$token}"]],
                [['text' => '← Назад, ничего не менять', 'callback_data' => "draft:review:{$draft->id}"]],
            ]]);
    }

    public function sendInputPrompt(TelegramClient $telegram, string $chatId, ProductDraft $draft, string $description): array
    {
        return $this->messageLifecycle->replaceControlMessage($telegram, $draft, $chatId,
            "✏️ Черновик #{$draft->id}: {$draft->title}\n\n{$description}", ['inline_keyboard' => [
                [['text' => '← Отменить ввод', 'callback_data' => "draft:input-cancel:{$draft->id}"]],
            ]]);
    }

    public function sendApprovalProblem(TelegramClient $telegram, string $chatId, ProductDraft $draft, string $reason): array
    {
        return $this->messageLifecycle->replaceControlMessage($telegram, $draft, $chatId,
            "⚠️ Черновик #{$draft->id} не добавлен.\n{$reason}\nДополнительный поиск не запущен. Выберите действие:", ['inline_keyboard' => [
                [['text' => '✏️ Исправить результат', 'callback_data' => "draft:edit:{$draft->id}"]],
                [['text' => '← Назад', 'callback_data' => "draft:review:{$draft->id}"]],
            ]]);
    }

    /** Second step of "Принять как есть": the operator sees exactly what stays unreconciled. */
    public function sendAcceptAsIsConfirmation(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        $fields = collect($this->unreconciledFields($draft))
            ->map(fn (array $field): string => '• '.$field['key'].': '.mb_substr((string) ($field['reason'] ?? ''), 0, 160))
            ->take(8)->implode("\n");

        return $this->messageLifecycle->replaceControlMessage($telegram, $draft, $chatId,
            "⚠️ Принять черновик #{$draft->id} как есть?\nФото проверены, но характеристики не сверены"
                .($fields !== '' ? ":\n{$fields}" : '.')
                ."\nТовар попадёт в каталог с этими данными без дополнительной проверки.", ['inline_keyboard' => [
                    [['text' => '✅ Да, принять как есть', 'callback_data' => "draft:asisok:{$draft->id}:{$draft->telegram_update_id}"]],
                    [['text' => '← Назад', 'callback_data' => "draft:review:{$draft->id}"]],
                ]]);
    }

    /** @return array<int, array{key: string, reason?: string}> */
    public function unreconciledFields(ProductDraft $draft): array
    {
        $fields = data_get($draft->card_repair_state, 'last_failure.repair_context.unresolved_fields');
        if (! is_array($fields) || $fields === []) {
            $fields = data_get(ProductSourceAttempt::query()->where('product_draft_id', $draft->id)
                ->where('action', 'reconcile_card')->latest('id')->value('output'), 'repair_context.unresolved_fields', []);
        }

        return array_values(array_filter((array) $fields, fn (mixed $field): bool => is_array($field) && is_string($field['key'] ?? null)));
    }

    public function sendEditMenu(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        $rows = [];
        if ($draft->isManualUpload()) {
            $rows[] = [['text' => '✏️ Текст, характеристики и цена', 'callback_data' => "draft:fields:{$draft->id}"]];
            $rows[] = [['text' => '📎 Загрузить ещё фото', 'callback_data' => "draft:upload:{$draft->id}"]];
            $rows[] = [['text' => '🌐 Дополнить информацию', 'callback_data' => "draft:enrich:{$draft->id}"]];
            $rows[] = [['text' => '🔎 Подобрать фото в интернете', 'callback_data' => "draft:webphotos:{$draft->id}"]];
            $rows[] = [['text' => '🖼 Управление загруженными фото', 'callback_data' => "draft:photos:{$draft->id}"]];
            $rows[] = [['text' => '← Вернуться к черновику', 'callback_data' => "draft:review:{$draft->id}"]];

            return $this->messageLifecycle->replaceControlMessage($telegram, $draft, $chatId,
                "✏️ Ручная карточка #{$draft->id}. Выберите данные или фотографии для изменения. Поиск в интернете — отдельное подтверждённое действие.",
                ['inline_keyboard' => $rows]);
        }
        if ($draft->media()->exists()) {
            $rows[] = [['text' => '🖼 Выбрать фото для изменения', 'callback_data' => "draft:photos:{$draft->id}"]];
        }
        $rows[] = [['text' => '🔄 Найти другие фото', 'callback_data' => "draft:restage:{$draft->id}"]];
        $rows[] = [['text' => '➕ Найти ещё фото', 'callback_data' => "draft:findmore:{$draft->id}"]];
        $rows[] = [['text' => '✏️ Уточнить товар и найти заново', 'callback_data' => "draft:query:{$draft->id}"]];
        if (filled($draft->primary_source_url)) {
            $rows[] = [['text' => '🔧 Проблема со сбором на сайте', 'callback_data' => "draft:source:{$draft->id}"]];
        }
        $rows[] = [['text' => '← Вернуться к черновику', 'callback_data' => "draft:review:{$draft->id}"]];

        return $this->messageLifecycle->replaceControlMessage($telegram, $draft, $chatId,
            "✏️ Исправить черновик #{$draft->id}\n{$draft->title}\n\nНе те фото — выберите работу с фото. Не тот товар или комплектация — уточните запрос для нового поиска. Открытие меню бесплатно; платный запуск потребует подтверждения.",
            ['inline_keyboard' => $rows]);
    }

    private function editAndRejectRow(ProductDraft $draft): array
    {
        return [
            ['text' => '✏️ Исправить', 'callback_data' => "draft:edit:{$draft->id}"],
            ['text' => '✖ Отклонить', 'callback_data' => "draft:reject:{$draft->id}"],
        ];
    }

    public function sendPhotoSelection(
        TelegramClient $telegram,
        string $chatId,
        ProductDraft $draft,
        string $action = 'enhance',
    ): array {
        $labels = [
            'enhance' => 'улучшить',
            'replace' => 'заменить',
            'delete' => 'удалить',
        ];
        // The fallback has to move the action itself, not just its label: the
        // callback_data below carries $action into a button, and only these
        // three have a handler. An unknown verb used to draw a menu labelled
        // "улучшить" whose every button did nothing when pressed.
        $action = isset($labels[$action]) ? $action : 'enhance';
        $verb = $labels[$action];
        $buttons = $draft->media()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->take(10)
            ->get()
            ->values()
            ->map(fn ($media, int $index): array => [
                'text' => $this->numberEmoji($index + 1),
                'callback_data' => "draft:{$action}-photo:{$draft->id}:{$media->id}",
            ])
            ->chunk(5)
            ->map(fn ($row): array => $row->values()->all())
            ->values()
            ->all();

        if ($action === 'replace') {
            $buttons[] = [[
                'text' => '🔁 Все фото',
                'callback_data' => "draft:restage:{$draft->id}",
            ]];
        }
        $buttons[] = [[
            'text' => '← Назад',
            'callback_data' => "draft:review:{$draft->id}",
        ]];

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "🖼 Какое фото черновика #{$draft->id} {$verb}?\nНомер соответствует позиции в альбоме.",
            ['inline_keyboard' => $buttons],
        );
    }

    private function controlMarkup(ProductDraft $draft): array
    {
        $mediaCount = $draft->media()->count();
        if ($draft->isManualUpload()) {
            $intake = $draft->manualIntake();
            $rows = [];
            if (($intake['status'] ?? '') === 'ready' && $mediaCount > 0) {
                $rows[] = [[
                    'text' => isset($intake['target_product_id']) ? '✅ Сохранить изменения товара' : '✅ Добавить в каталог',
                    'callback_data' => "draft:add:{$draft->id}",
                ]];
            }
            $rows[] = [['text' => '✏️ Исправить данные', 'callback_data' => "draft:fields:{$draft->id}"],
                ['text' => '📎 Добавить фото', 'callback_data' => "draft:upload:{$draft->id}"]];
            $rows[] = [['text' => '🌐 Доп. информация', 'callback_data' => "draft:enrich:{$draft->id}"],
                ['text' => '🔎 Фото в интернете', 'callback_data' => "draft:webphotos:{$draft->id}"]];
            if ($mediaCount > 0) {
                $rows[] = [['text' => '🖼 Управление фото', 'callback_data' => "draft:photos:{$draft->id}"]];
            }
            $rows[] = [['text' => '📋 Все данные и уточнения', 'callback_data' => "draft:details:{$draft->id}"]];
            $rows[] = [['text' => '✖ Отклонить', 'callback_data' => "draft:reject:{$draft->id}"]];

            return ['inline_keyboard' => $rows];
        }
        $searchPaused = in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'exhausted', 'stalled'], true);

        if ($searchPaused && $mediaCount === 0) {
            $buttonLabel = $draft->gallery_search_stop_reason === 'exhausted'
                ? '▶️ Продолжить поиск фото'
                : '▶️ Продолжить поиск фото (+$'.number_format(app(AiSettings::class)->maxSearchCostUsd(), 2).')';

            $rows = [
                [[
                    'text' => $buttonLabel,
                    'callback_data' => "draft:continue-search:{$draft->id}",
                ]],
                [[
                    'text' => '🔗 Источник',
                    'callback_data' => "draft:source:{$draft->id}",
                ]],
            ];
            if ($draft->images_staged_at !== null && ! $this->reconciliationPending($draft)) {
                $rows[] = [['text' => '📋 Все характеристики', 'callback_data' => "draft:details:{$draft->id}"]];
            }
            $rows[] = $this->editAndRejectRow($draft);

            return ['inline_keyboard' => $rows];
        }

        if ($this->reconciliationPending($draft)) {
            $rows = [];
            // Operator decision 2026-10-19: verified photos may be accepted with
            // the specifications as they are, after seeing what is unreconciled.
            if ($mediaCount > 0 && ! $draft->media()->where('verification_status', 'pending')->exists()) {
                $rows[] = [['text' => '✅ Принять как есть', 'callback_data' => "draft:asis:{$draft->id}"]];
            }
            if (in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'stalled'], true)) {
                $rows[] = [[
                    'text' => '▶️ Продолжить поиск (+$'.number_format(app(AiSettings::class)->maxSearchCostUsd(), 2).')',
                    'callback_data' => "draft:continue-search:{$draft->id}",
                ]];
            }
            $rows[] = $this->editAndRejectRow($draft);

            return ['inline_keyboard' => $rows];
        }

        // The publishing action gets its own row: it used to sit shoulder to
        // shoulder with the irreversible "Отменить", which is the one mis-tap
        // on this card that costs a whole search.
        $rows = $mediaCount > 0
            ? [[['text' => '✅ Добавить в каталог', 'callback_data' => "draft:add:{$draft->id}"]]]
            : [];
        $rows[] =
            [
                ['text' => '🖼 Фото', 'callback_data' => "draft:photos:{$draft->id}"],
                ['text' => '🔗 Источник', 'callback_data' => "draft:source:{$draft->id}"],
            ];
        if ($mediaCount === 0 && $draft->images_staged_at !== null) {
            $rows[] = [['text' => '📋 Все характеристики', 'callback_data' => "draft:details:{$draft->id}"]];
        }

        if (in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'exhausted', 'specifications_unreconciled', 'stalled'], true)) {
            $label = match ($draft->gallery_search_stop_reason) {
                'exhausted' => '▶️ Продолжить поиск',
                'specifications_unreconciled' => '🔁 Повторить сверку характеристик',
                default => '▶️ Продолжить поиск (+$'.number_format(app(AiSettings::class)->maxSearchCostUsd(), 2).')',
            };
            $rows[] = [[
                'text' => $label,
                'callback_data' => "draft:continue-search:{$draft->id}",
            ]];
        } elseif ($mediaCount <= 2) {
            $rows[] = [
                ['text' => '🔍 Найти ещё доп. фото', 'callback_data' => "draft:findmore:{$draft->id}"],
            ];
        }

        // Retraining is buried under "Источник" for a normal card, because a
        // working gallery needs no explanation. When the result itself says the
        // extraction fell short, the operator's hint is the single most useful
        // thing they can give, so it stops being a two-tap discovery problem.
        if ($this->galleryFellShort($draft, $mediaCount)) {
            $rows[] = [[
                'text' => '🧠 Переобучить с подсказкой',
                'callback_data' => "draft:source-hint:{$draft->id}",
            ]];
        }

        $rows[] = $this->editAndRejectRow($draft);

        return ['inline_keyboard' => $rows];
    }

    /**
     * "Fell short" is read from what the search itself recorded, not guessed
     * from the photo count alone: a partial gallery is one the pipeline already
     * knows is incomplete, and a card under its own category minimum is one the
     * operator will have to fix by hand anyway.
     */
    private function galleryFellShort(ProductDraft $draft, int $mediaCount): bool
    {
        if ($mediaCount === 0) {
            return false;
        }

        // A 'partial' status caused solely by outstanding specification
        // reconciliation is not an extraction shortfall - retraining with a
        // hint would not touch the actual outstanding problem.
        if ($draft->gallery_status === 'partial' && $draft->gallery_search_stop_reason !== 'specifications_unreconciled') {
            return true;
        }

        $category = trim((string) $draft->category);
        $minimum = $category === ''
            ? 0
            : (int) (Category::query()->where('slug', $category)->first()?->minimumVerifiedImages() ?? 0);

        return $minimum > 0 && $mediaCount < $minimum;
    }

    public function sendPhotoMenu(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        // One concept, one entry point: these three used to be three separate
        // top-level buttons that all opened the very same photo picker.
        $buttons = [
            [
                ['text' => '✨ Улучшить', 'callback_data' => "draft:enhance:{$draft->id}"],
                ['text' => '🔄 Заменить', 'callback_data' => "draft:replace:{$draft->id}"],
            ],
            [['text' => '🗑 Удалить', 'callback_data' => "draft:delete:{$draft->id}"]],
            [['text' => '← Назад', 'callback_data' => "draft:review:{$draft->id}"]],
        ];
        if ($draft->isManualUpload()) {
            $buttons = [
                [['text' => '📎 Загрузить ещё', 'callback_data' => "draft:upload:{$draft->id}"],
                    ['text' => '🗑 Удалить', 'callback_data' => "draft:delete:{$draft->id}"]],
                [['text' => '✨ Улучшить', 'callback_data' => "draft:enhance:{$draft->id}"],
                    ['text' => '🔎 Подобрать в интернете', 'callback_data' => "draft:webphotos:{$draft->id}"]],
                [['text' => '← Назад', 'callback_data' => "draft:review:{$draft->id}"]],
            ];
        }

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "🖼 Фотографии черновика #{$draft->id} ({$draft->media()->count()} шт.)\nЧто сделать?",
            ['inline_keyboard' => $buttons],
        );
    }

    public function sendSourceMenu(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        $primarySourceUrl = trim((string) $draft->primary_source_url);
        $host = $primarySourceUrl !== '' ? ProductSourcePriority::host($primarySourceUrl) : '';
        $buttons = [
            [['text' => '🧠 Переобучить рецепт', 'callback_data' => "draft:source-retrain:{$draft->id}"]],
            [['text' => '💬 Переобучить с подсказкой', 'callback_data' => "draft:source-hint:{$draft->id}"]],
            [['text' => '🚫 Не использовать источник', 'callback_data' => "draft:source-block:{$draft->id}"]],
            [['text' => '← Назад', 'callback_data' => "draft:review:{$draft->id}"]],
        ];
        $text = $host !== ''
            ? "🔗 Источник черновика #{$draft->id}: {$host}\nЧто сделать?"
            : "🔗 Источник черновика #{$draft->id}\nЧто сделать?";

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            $text,
            ['inline_keyboard' => $buttons],
        );
    }

    public function sendSourceBlockConfirm(TelegramClient $telegram, string $chatId, ProductDraft $draft, string $host): array
    {
        $buttons = [
            [
                ['text' => 'Да, забанить', 'callback_data' => "draft:source-block-confirm:{$draft->id}"],
                ['text' => 'Отмена', 'callback_data' => "draft:source-block-cancel:{$draft->id}"],
            ],
        ];

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "⚠️ Забанить источник {$host}?\nОн перестанет использоваться для ВСЕХ будущих товаров, не только этого черновика.",
            ['inline_keyboard' => $buttons],
        );
    }

    private function reconciliationPending(ProductDraft $draft): bool
    {
        return $draft->reconciliationPending();
    }

    private function caption(ProductDraft $draft, string $usageFootnote): string
    {
        $specifications = implode("\n", AttributePresentation::lines(
            AttributePresentation::specifications($draft->specifications ?? [])->take(6),
            fn (array $item): string => sprintf(
                '%s %s: %s',
                SpecificationEmoji::for((string) ($item['key'] ?? $item['name'] ?? '')),
                $item['name'],
                $item['value'],
            )));
        $galleryCount = $draft->media()->count();
        $primarySourceUrl = trim((string) $draft->primary_source_url);
        $reconciliationPending = $this->reconciliationPending($draft);
        $partialNotice = $draft->gallery_status === 'partial' && ! $reconciliationPending && ! $draft->isManualUpload()
            ? 'Частичный успех: полный цикл завершён, сохранены лучшие доступные проверенные фото.'
            : null;
        // A structured column, not a string match against gallery_notes'
        // free text - this must reach the same Telegram message an admin
        // actually approves from, and "Добавить в каталог" is genuinely
        // blocked (ProductDraftWorkflow::approve()) while this is true, not
        // merely advisory.
        $identityNotice = $reconciliationPending
            ? 'Сверка с источником фото не завершена. Фото сохранены, публикация недоступна. Можно повторить сверку или отменить черновик.'
            : null;
        $usageFootnote = ($partialNotice ? PHP_EOL.$partialNotice : '')
            .($identityNotice ? PHP_EOL.$identityNotice : '')
            .$usageFootnote;
        $footer = implode("\n", array_filter([
            "📷 Фото: {$galleryCount}",
            ($photoNotice = $this->photoNotice($draft)) !== '' ? 'ℹ️ '.$photoNotice : null,
            $primarySourceUrl !== '' ? "🔗 Источник: {$primarySourceUrl}" : null,
        ]));
        $header = implode("\n", array_filter([
            $reconciliationPending
                ? "⏸ Черновик #{$draft->id}: сверка не завершена"
                : "🆕 Черновик #{$draft->id} готов к добавлению",
            "🏷 {$draft->title}",
            '📦 '.(ProductCondition::options()[$draft->condition ?? 'new'] ?? $draft->condition),
            implode(' · ', array_filter([$draft->brand, $draft->model, $draft->color])),
        ]));
        if ($draft->isManualUpload()) {
            $intake = $draft->manualIntake();
            $commercial = $intake['commercial'] ?? [];
            $header = (($intake['status'] ?? '') === 'ready'
                ? "📥 Черновик #{$draft->id}: проверьте перед сохранением"
                : "📝 Черновик #{$draft->id}: нужны уточнения")
                ."\n🏷 {$draft->title}"
                ."\n📦 ".(ProductCondition::options()[$draft->condition ?? ''] ?? 'Состояние не указано');
            if (($commercial['price_amount'] ?? null) !== null) {
                $header .= "\n💰 ".$commercial['price_amount'].' '.($commercial['currency'] ?? '');
            }
            $footer .= "\n📎 Материалы пользователя; интернет-проверка отдельно.";
            if (! empty($intake['missing_fields'])) {
                $labels = ['title' => 'название', 'category' => 'категория', 'condition' => 'состояние', 'product_photo' => 'фото товара'];
                $footer .= "\nУточните: ".implode(', ', array_map(fn ($field) => $labels[$field] ?? $field, $intake['missing_fields']));
            }
            if (! empty($intake['conflicts'])) {
                $footer .= "\n⚠️ Есть противоречия — исправьте данные перед добавлением.";
            }
            if (! empty($intake['extra_photos'])) {
                $footer .= "\nВ галерее показаны первые 10 фото; остальные оригиналы сохранены.";
            }
        }
        $searchPaused = in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'exhausted', 'stalled'], true);
        // images_staged_at stays null only when staging never ran to its own
        // completion at all - e.g. the job was killed by its hard timeout
        // mid-search - as opposed to a normal run that finished and decided
        // there was nothing left to try. Both must block "готов", not just
        // the latter.
        $searchInterrupted = $draft->images_staged_at === null;

        if (! $draft->isManualUpload() && $galleryCount === 0 && ! $searchInterrupted && ! $reconciliationPending) {
            $header = "📋 Черновик #{$draft->id}\nИнформация собрана; фото не найдены.\n"
                ."🏷 {$draft->title}\n"
                .implode(' / ', array_filter([$draft->brand, $draft->model, $draft->color]));
            $usageFootnote = "\n".match ($draft->gallery_search_stop_reason) {
                'cost_budget' => 'Денежный лимит поиска достигнут. Продолжить поиск фото можно новым бюджетным циклом.',
                'time_budget' => 'Время поиска закончилось. Продолжить поиск фото можно новым бюджетным циклом.',
                'stalled' => 'Поиск фото остановлен до завершения. Продолжить поиск фото можно новым бюджетным циклом.',
                default => 'Подходящие фотографии не найдены. Поиск фото можно продолжить.',
            }.' Без фото добавление в каталог недоступно.'.$usageFootnote;
        } elseif (! $draft->isManualUpload() && ($searchPaused || $searchInterrupted) && $galleryCount === 0) {
            $header = "⏸ Черновик #{$draft->id}: поиск фотографий приостановлен\n"
                ."🏷 {$draft->title}\n"
                .implode(' / ', array_filter([$draft->brand, $draft->model, $draft->color]));
            $usageFootnote = "\n".match (true) {
                $searchInterrupted => 'Поиск фотографий прервался технической ошибкой и не успел завершиться штатно. Будет повторён автоматически.',
                $draft->gallery_search_stop_reason === 'exhausted' => 'Все известные и найденные AI-поиском источники проверены без результата. Продолжаю автоматически.',
                default => 'Лимит текущего запуска достигнут. Нажмите «Продолжить поиск фото», чтобы начать новый цикл.',
            }.$usageFootnote;
        }

        $fixed = $header."\n\n".$footer.$usageFootnote;
        $available = max(0, 1024 - mb_strlen($fixed) - 8);
        $body = implode("\n\n", array_filter([
            $this->clip((string) $draft->description, (int) floor($available * 0.55)),
            $specifications !== '' ? "⚙️ Характеристики:\n".$specifications : null,
        ]));

        return mb_substr($header."\n\n".$this->clip($body, $available, wholeLines: true)."\n\n".$footer.$usageFootnote, 0, 1024);
    }

    /**
     * Cuts at the last word (or whole line) that fits, so a caption never
     * ends mid-word - live #1's card read "…support c" and "▸ Displ".
     */
    private function clip(string $text, int $limit, bool $wholeLines = false): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, max(0, $limit - 1));
        $break = $wholeLines ? mb_strrpos($cut, "\n") : max((int) mb_strrpos($cut, ' '), (int) mb_strrpos($cut, "\n"));
        $kept = rtrim($break ? mb_substr($cut, 0, $break) : $cut);
        // A group heading left without any of its lines says nothing.
        $kept = (string) preg_replace('/\n+▸[^\n]*$/u', '', $kept);

        return $wholeLines ? $kept."\n…" : $kept.'…';
    }

    private function numberEmoji(int $number): string
    {
        return match ($number) {
            1 => '1️⃣',
            2 => '2️⃣',
            3 => '3️⃣',
            4 => '4️⃣',
            5 => '5️⃣',
            6 => '6️⃣',
            7 => '7️⃣',
            8 => '8️⃣',
            9 => '9️⃣',
            10 => '🔟',
            default => (string) $number,
        };
    }

    public function sendDetails(TelegramClient $telegram, string $chatId, ProductDraft $draft): void
    {
        $lines = ["📋 Черновик #{$draft->id}: {$draft->title}", (string) $draft->description];
        if (($photoNotice = $this->photoNotice($draft, false)) !== '') {
            $lines[] = 'ℹ️ '.$photoNotice;
        }
        $lines = array_merge($lines, AttributePresentation::lines(
            AttributePresentation::specifications($draft->specifications ?? []),
            fn (array $spec): string => SpecificationEmoji::for($spec['key'] ?? $spec['name'] ?? '').' '.($spec['name'] ?? '').': '.($spec['value'] ?? '')
                .(filled($spec['source_url'] ?? null) ? "\n".$spec['source_url'] : ''),
        ));
        if ($draft->isManualUpload()) {
            $intake = $draft->manualIntake();
            $commercial = $intake['commercial'] ?? [];
            if (($commercial['quantity'] ?? null) !== null) {
                $lines[] = '📦 Количество: '.$commercial['quantity'];
            }
            if (($commercial['warranty_months'] ?? null) !== null) {
                $lines[] = '🛡 Гарантия: '.$commercial['warranty_months'].' мес.';
            }
            foreach ($intake['conflicts'] ?? [] as $conflict) {
                $lines[] = '⚠️ '.(is_string($conflict) ? $conflict : json_encode($conflict, JSON_UNESCAPED_UNICODE));
            }
            $lines[] = 'Исходные фото и сведения пользователя сохранены. Интернет-источники указаны у добавленных характеристик.';
        }
        $text = implode("\n\n", array_filter($lines));
        while ($text !== '') {
            $telegram->sendMessage($chatId, mb_substr($text, 0, 3500));
            $text = mb_substr($text, 3500);
        }
        $this->sendControls($telegram, $chatId, $draft);
    }

    /**
     * Said beside the add button: the operator approved two MacBooks and two
     * identical GPUs in one evening because nothing on the card mentioned the
     * product already published (live #553, #567).
     */
    private function catalogNotice(ProductDraft $draft): string
    {
        if (blank($draft->title)) {
            return '';
        }
        $card = $draft->only(['brand', 'model', 'title', 'specifications']);
        $target = $draft->isManualUpload() ? (int) data_get($draft->manualIntake(), 'target_product_id') : 0;
        $identity = app(ProductCatalogIdentity::class);
        $exact = $identity->find($card);
        if ($exact !== null && $exact->id !== $target) {
            return $draft->isManualUpload()
                ? "Этот товар уже есть в каталоге: #{$exact->id}. Измените его через /edit {$exact->id}."
                : "Этот товар уже есть в каталоге: #{$exact->id}. «Добавить в каталог» обновит его, второй товар не появится.";
        }
        $similar = $identity->sameModel($card, $target ?: null);
        if ($similar->isEmpty()) {
            return '';
        }
        $list = $similar->map(function (Product $product): string {
            $variant = $product->variants->sortByDesc('is_default')->first();
            $code = collect([$variant?->mpn, $variant?->sku, $variant?->gtin])->first(fn ($value): bool => filled($value));

            return '#'.$product->id.($code !== null ? " ({$code})" : '');
        })->implode(', ');

        return "Эта модель уже есть в каталоге: {$list}. Проверьте, не дубль ли это: у черновика другой артикул или комплектация.";
    }

    private function photoNotice(ProductDraft $draft, bool $compact = true): string
    {
        $draft->loadMissing('media');
        if ($draft->media->isEmpty()) {
            return '';
        }
        // Describe the actual retained frames when an attempted new gallery was discarded.
        $contexts = $draft->media->pluck('photo_source_context')->filter(fn ($context): bool => is_array($context));
        if ($contexts->isEmpty() && is_array($draft->photo_source_context)) {
            $contexts = collect([$draft->photo_source_context]);
        }
        $notice = $contexts->map(fn (array $context): ?string => PhotoSourceNotice::russian($context))
            ->filter()->unique()->implode("\n");

        return $compact && mb_strlen($notice) > 420 ? mb_substr($notice, 0, 419).'…' : $notice;
    }
}
