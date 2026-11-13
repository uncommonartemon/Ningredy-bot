<?php

namespace App\Services\Telegram\Webhook;

use App\Exceptions\LowResolutionDraftMediaException;
use App\Exceptions\MissingDraftMediaException;
use App\Exceptions\UnreconciledDraftSpecificationsException;
use App\Exceptions\UnverifiedDraftMediaException;
use App\Jobs\EnrichTelegramProductDraft;
use App\Jobs\ProcessDraftPhotoActions;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\ProposeTelegramDraftEdit;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Models\AiOperation;
use App\Models\CharacteristicQuestion;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\TelegramUpdate;
use App\Services\Catalog\CharacteristicAudit;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductSourcePriority;
use App\Services\ProductSearch\Recovery\RequestGuardian;
use App\Services\Telegram\DraftPhotoSearch;
use App\Services\Telegram\DraftTelegramActionConfirmation;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\PhotoReadingCheck;
use App\Services\Telegram\TelegramCatalogDeletion;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDatabaseOperator;
use App\Services\Telegram\TelegramDraftEditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The inline buttons: draft cards, photo and source menus, search and deletion confirmations. */
class TelegramCallbackHandler
{
    use ConversesWithTheOperator;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ProductDraftWorkflow $draftWorkflow,
        private readonly DraftTelegramPresenter $draftPresenter,
        private readonly TelegramCommandHandler $commands,
    ) {}

    public function handle(array $callback, TelegramUpdate $update): void
    {
        $callbackId = (string) data_get($callback, 'id');
        $data = (string) data_get($callback, 'data');
        $chatId = (string) data_get($callback, 'message.chat.id');
        $messageId = data_get($callback, 'message.message_id');

        // An answer to a doubtful proposal of the characteristic audit.
        if (preg_match('/^chq:(y|n):(\d+)$/', $data, $question) === 1) {
            $audit = app(CharacteristicAudit::class);
            $asked = CharacteristicQuestion::query()->find((int) $question[2]);
            $result = $asked === null ? 'Этого вопроса уже нет.' : $audit->answer($asked, $question[1] === 'y', (string) $update->telegram_user_id);
            if ($asked !== null && is_numeric($messageId)) {
                $this->telegram->editMessageText($chatId, (int) $messageId, $audit->questionText($asked)."\n\n".$result);
            }
            $this->answerAndClose($callbackId, $update, mb_substr($result, 0, 190));

            return;
        }

        // The operator's answer about what the bot read on a photo (module 107).
        if (preg_match('/^pr:(ok|fix|again|no):(\d+)$/', $data, $press) === 1) {
            $result = app(PhotoReadingCheck::class)->answer((int) $press[2], $press[1], $chatId, (string) $update->telegram_user_id);
            if ($press[1] === 'fix' && ! $result['closes'] && str_starts_with($result['text'], '✏️')) {
                // The question stays with its buttons; the request for the correction is its own message.
                $this->telegram->sendMessage($chatId, $result['text']);
            } elseif ($result['closes'] && is_numeric($messageId)) {
                $this->telegram->editMessageText($chatId, (int) $messageId, "📷 Прочитано с фото:\n".$result['reading']."\n\n".$result['text']);
            }
            $this->answerAndClose($callbackId, $update, mb_substr($result['text'], 0, 190));

            return;
        }

        if (str_starts_with($data, 'product:')) {
            $this->clearManualCreate($chatId, (string) $update->telegram_user_id);
            app(DraftTelegramInteractionState::class)->clear($chatId, (string) $update->telegram_user_id);
        }

        if ($data === 'product:create') {
            $this->commands->handle('/add', $chatId, (string) $update->telegram_user_id, $update);
            $this->telegram->answerCallbackQuery($callbackId, 'Отправьте описание и фото.');

            return;
        }
        if (preg_match('/^product:variants:(\d+):(\d+)$/', $data, $variantPage) === 1) {
            $this->commands->openCatalogEditor((int) $variantPage[1], $update, null, (int) $variantPage[2]);
            $this->telegram->answerCallbackQuery($callbackId, 'Выберите комплектацию.');

            return;
        }
        // Two different ways out (operator, 2026-11-11): to sleep and back, or deleted for good.
        if (preg_match('/^product:(sleep|wake):(\d+)$/', $data, $sale) === 1) {
            $this->commands->setCatalogSale((int) $sale[2], $sale[1] === 'wake', $update);
            $this->telegram->answerCallbackQuery($callbackId, $sale[1] === 'wake' ? 'Возвращаю в продажу.' : 'Снимаю с продажи.');

            return;
        }
        if (preg_match('/^product:(tgedit|remove):(\d+)(?::(\d+))?$/', $data, $catalogAction) === 1) {
            if ($catalogAction[1] === 'tgedit') {
                $this->commands->openCatalogEditor((int) $catalogAction[2], $update, isset($catalogAction[3]) ? (int) $catalogAction[3] : null);
            } else {
                $this->commands->prepareCatalogDeletion((int) $catalogAction[2], $update);
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
            $available = $draft?->status === 'pending_review' && ($manualInput[1] === 'fields' || $draft->isManualUpload());
            if ($available) {
                app(DraftTelegramInteractionState::class)->remember($chatId, (string) $update->telegram_user_id, $draft,
                    $manualInput[1] === 'fields' ? 'manual_fields' : 'manual_photos');
                $this->draftPresenter->sendInputPrompt($this->telegram, $chatId, $draft,
                    $manualInput[1] === 'fields'
                        ? ($draft->isManualUpload()
                            ? 'Напишите изменения одним сообщением. Например: «цена: 15000 CZK», «состояние: б/у», «RAM: 32 GB». Для нового описания: «описание: …». Покажу изменения перед применением. /cancel — отмена.'
                            : 'Напишите изменения одним сообщением. Например: «добавь TDP 115 Вт», «цвет: чёрный», «убери Вес», «состояние: б/у». Покажу изменения перед применением. /cancel — отмена.')
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

        if (preg_match('/^draft:(enhance|unmark|replace|delete)-photo:(\d+):(\d+)$/', $data, $photoMatches) === 1) {
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

        if (preg_match('/^draft:(enhance|unmark|replace|delete):(\d+)$/', $data, $menuMatches) === 1) {
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

        if (preg_match('/^product:delete:(confirm|cancel|sleep):(\d+)$/', $data, $deleteMatches) === 1) {
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
        $labels = ['enhance' => 'Улучшаю', 'unmark' => 'Убираю водяной знак с', 'replace' => 'Ищу замену для', 'delete' => 'Удаляю'];
        $limits = ['enhance' => 180, 'unmark' => 180, 'replace' => 180, 'delete' => 10];
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

        if (app(DraftPhotoSearch::class)->other($draft, $chatId, $update->id) !== DraftPhotoSearch::STARTED) {
            $this->answerAndClose($callbackId, $update, 'Поиск фото уже идёт.');

            return;
        }

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

        $outcome = app(DraftPhotoSearch::class)->continue($draft, $chatId, $update->id);

        if ($outcome !== DraftPhotoSearch::STARTED) {
            $this->answerAndClose($callbackId, $update, $outcome === DraftPhotoSearch::NOT_STOPPED
                ? 'Поиск не был остановлен лимитом; продолжать нечего.' : 'Продолжение поиска уже выполняется.');

            return;
        }

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

        if (app(DraftPhotoSearch::class)->topUp($draft, $chatId, $update->id) !== DraftPhotoSearch::STARTED) {
            $this->answerAndClose($callbackId, $update, 'Поиск дополнительных фото уже идёт.');

            return;
        }

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
            ->whereIn('action', ['enhance_draft_photo', 'unmark_draft_photo', 'replace_draft_photo', 'delete_draft_photo', 'process_draft_photos', 'refind_catalog_photos'])
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
                $result = ['status' => 'failed', 'message' => $action === 'sleep'
                    ? 'Не удалось снять товар с продажи. Ошибка записана в журнал.'
                    : 'Не удалось удалить товар. Ошибка записана в журнал.'];
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
}
