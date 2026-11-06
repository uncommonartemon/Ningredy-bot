<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductPhotoIdentity;
use App\Services\ProductSearch\Catalogs\ReferenceSites;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftQueuedWork;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDraftEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The operator corrects a pending draft in words - a characteristic the bot missed
 * or got wrong, a field, the condition - or asks the bot to find one on the web, and
 * the draft changes (module 72; operator, 2026-11-06: «заметил, сказал, и тот
 * поправил»). A draft is not public, the changes are named in the chat and the card
 * comes back at once, so no button stands between the words and the change.
 */
class EditProductDraft implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Change a pending product draft. instruction: the operator\'s words with the values - a field (title, brand, model, colour, condition, description, category, product type), '
            .'a characteristic to add, correct or remove, the price, quantity or warranty of a manually uploaded product. wanted: characteristics to find on the web when the operator named them without a value; '
            .'found values are quoted from a page and known ones are not overwritten. Pass one of the two per call; for both, call twice. The change is applied at once, named in the chat, and the card comes back with its buttons: '
            .'answer with one short line and leave draft_id null. Never change a draft\'s fields or characteristics through the database tools. Photos are not characteristics: a request for more or other photos goes to ManageDraftPhotos.';
    }

    public function handle(Request $request): Stringable|string
    {
        $draft = ProductDraft::query()->find($request->integer('draft_id'));
        $instruction = trim((string) ($request['instruction'] ?? ''));
        $wanted = trim((string) ($request['wanted'] ?? ''));
        if ($draft === null) {
            return $this->json(['ok' => false, 'error' => 'Draft not found.']);
        }
        if (! app(DraftAccess::class)->belongsToContext($draft, $this->update)) {
            return $this->json(['ok' => false, 'error' => 'Draft belongs to another operator or chat.']);
        }
        if ($draft->status !== 'pending_review') {
            return $this->json(['ok' => false, 'draft_id' => $draft->id, 'product_id' => $draft->approved_product_id,
                'error' => "Draft already processed: {$draft->status}. A product in the catalog is changed with the product tools."]);
        }
        if ($instruction === '' && $wanted === '') {
            return $this->json(['ok' => false, 'draft_id' => $draft->id, 'error' => 'instruction or wanted is required.']);
        }
        if (DraftQueuedWork::has($draft->id)) {
            return $this->json(['ok' => false, 'draft_id' => $draft->id,
                'error' => 'The draft is being processed right now; its result arrives by itself - ask for the change after that.']);
        }
        $unsaid = $this->numbersTheOperatorDidNotWrite($instruction, $draft);
        if ($unsaid !== []) {
            return $this->json(['ok' => false, 'draft_id' => $draft->id, 'not_in_message' => $unsaid,
                'error' => 'These numbers are not in the operator\'s message, so they are not written. Pass their own words, or ask them for the value, or use wanted to find it on the web.']);
        }
        $marker = "draft-fields:{$draft->id}:queued";
        Cache::put($marker, $this->update->id, 960);
        try {
            $result = $wanted !== '' && $instruction === ''
                ? $this->findOnTheWeb($draft, $wanted)
                : $this->fromTheirWords($draft, $instruction);
        } catch (Throwable $exception) {
            return $this->json(['ok' => false, 'draft_id' => $draft->id, 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
        } finally {
            if (Cache::get($marker) === $this->update->id) {
                Cache::forget($marker);
            }
        }

        return $this->json($result);
    }

    /** @return array<string, mixed> */
    private function fromTheirWords(ProductDraft $draft, string $instruction): array
    {
        $editor = app(TelegramDraftEditor::class);
        $result = $this->recordOperation($this->update, class_basename(self::class), 'edit_product_draft',
            ['draft_id' => $draft->id, 'instruction' => $instruction],
            function (callable $commit) use ($draft, $instruction, $editor): array {
                // The agent's call runs before the write transaction opens.
                $proposal = $editor->propose($draft, $this->update, $instruction);

                return $commit(function () use ($draft, $proposal, $editor): array {
                    $editor->apply($draft, $this->update, $proposal);

                    return ['changes' => (string) ($proposal['preview'] ?? ''), 'messages' => $proposal['preview_messages'] ?? []];
                });
            }, ProductDraft::class, $draft->id, staged: true);

        return $this->deliver($draft, (array) ($result['messages'] ?? []), (string) ($result['changes'] ?? ''));
    }

    /**
     * Characteristics the operator named without a value: the draft's own text
     * researcher looks for them, a value goes in only with its page and quotation, and
     * what the card already says stays. The operator's request pays for it, as in
     * AddProductSpecifications.
     *
     * @return array<string, mixed>
     */
    private function findOnTheWeb(ProductDraft $draft, string $wanted): array
    {
        $found = json_decode((string) ProductDraft::withReconciliationWrite(fn () => (new SearchProductTextEvidence($draft->id, $this->update->id, enforceSearchBudget: true))->handle(new Request([
            'question' => 'The operator asked for these characteristics of this exact configuration: '.$wanted.'. Quote each from the page you read. Write name and value in English; keep numbers and units as the page publishes them.',
            'target_offer' => 'current_offer',
        ]))), true) ?: [];
        $draft->refresh();
        $personLinks = app(ReferenceSites::class)->personLinks(ProductPhotoIdentity::codes($draft), $draft->model, $draft->brand);
        if (($found['found'] ?? false) !== true) {
            return ['ok' => false, 'draft_id' => $draft->id, 'error' => (string) ($found['reason'] ?? 'The search did not finish.'), 'person_links' => $personLinks,
                'message' => 'Скажи оператору прямо, что поиск не удался и почему. Если подходит ссылка из person_links, предложи открыть её и прислать значения.'];
        }
        $specifications = is_array($draft->specifications) ? $draft->specifications : [];
        $known = collect($specifications)->map(fn (mixed $row): string => is_array($row) ? mb_strtolower((string) ($row['key'] ?? '')) : '')->flip();
        $added = [];
        $rejected = [];
        foreach ((array) ($found['facts'] ?? []) as $fact) {
            $key = mb_strtolower(trim((string) ($fact['key'] ?? '')));
            if ($key === '' || $known->has($key)) {
                continue;
            }
            if (! in_array($fact['scope'] ?? '', AddProductSpecifications::APPLICABLE_SCOPES, true)) {
                $rejected[] = ['name' => $fact['name'] ?? $key, 'reason' => 'страница говорит о другой конфигурации или о всём семействе'];

                continue;
            }
            if (! AddProductSpecifications::quotes((string) ($fact['value'] ?? ''), (string) ($fact['source_excerpt'] ?? ''))) {
                $rejected[] = ['name' => $fact['name'] ?? $key, 'reason' => 'значения нет в цитате со страницы'];

                continue;
            }
            $specifications[] = array_intersect_key($fact, array_flip(['key', 'name', 'value', 'group', 'source_url', 'source_excerpt', 'source_type', 'scope', 'applicability']));
            $known->put($key, true);
            $added[] = ['name' => (string) $fact['name'], 'value' => (string) $fact['value'], 'source' => parse_url((string) $fact['source_url'], PHP_URL_HOST)];
        }
        if ($added === []) {
            return ['ok' => true, 'draft_id' => $draft->id, 'status' => 'nothing_found', 'rejected' => $rejected, 'search_summary' => $found['summary'] ?? null, 'person_links' => $personLinks,
                'message' => 'Скажи оператору, что новых значений не найдено и чем закончился поиск (search_summary). Черновик не менялся. Если подходит ссылка из person_links, предложи её: оператор откроет сам и пришлёт значения.'];
        }
        $editor = app(TelegramDraftEditor::class);
        $result = $this->recordOperation($this->update, class_basename(self::class), 'find_draft_specifications',
            ['draft_id' => $draft->id, 'wanted' => $wanted, 'added' => $added],
            function () use ($draft, $specifications, $editor): array {
                $proposal = $editor->proposalFor($draft, $this->update, ['fields' => ['specifications' => $specifications], 'commercial' => []],
                    'Добавить найденные характеристики со ссылками на страницы.');
                $editor->apply($draft, $this->update, $proposal);

                return ['changes' => (string) ($proposal['preview'] ?? ''), 'messages' => $proposal['preview_messages'] ?? []];
            }, ProductDraft::class, $draft->id);

        return [...$this->deliver($draft, (array) ($result['messages'] ?? []), (string) ($result['changes'] ?? '')),
            'added' => $added, 'rejected' => $rejected, 'search_summary' => $found['summary'] ?? null, 'person_links' => $personLinks];
    }

    /**
     * The changes in the chat, then the card with them.
     *
     * @param  array<int, mixed>  $messages
     * @return array<string, mixed>
     */
    private function deliver(ProductDraft $draft, array $messages, string $changes): array
    {
        $draft->refresh();
        $chat = (string) $this->update->chat_id;
        $telegram = app(TelegramClient::class);
        $presenter = app(DraftTelegramPresenter::class);
        $reconciled = ! $draft->reconciliationPending();
        $delivered = true;
        try {
            app(DraftTelegramInteractionState::class)->clear($chat, (string) $this->update->telegram_user_id);
            $presenter->clearForSearchContinuation($telegram, $draft);
            foreach ($messages as $index => $chunk) {
                $telegram->sendMessage($chat, ($index === 0 ? "✏️ Черновик #{$draft->id} изменён:\n\n" : '').(string) $chunk);
            }
            $presenter->sendReview($telegram, $chat, $draft);
        } catch (Throwable $exception) {
            report($exception);
            $delivered = false;
        }

        return ['ok' => true, 'draft_id' => $draft->id, 'status' => 'applied', 'changes' => $changes, 'card_sent' => $delivered, 'reconciled' => $reconciled,
            'message' => ($delivered ? 'Карточка с изменениями уже отправлена: ответь одной короткой строкой, draft_id оставь null.' : 'Изменения записаны, но карточка не отправилась: скажи это и предложи открыть черновик заново.')
                .($reconciled ? '' : ' Скажи, что карточка теперь не сверена: можно нажать «Принять как есть».')];
    }

    /**
     * A value must be the operator's own: every number of the instruction stands in
     * their message. The draft's own number may be there from a reply to its card.
     *
     * @return list<string>
     */
    private function numbersTheOperatorDidNotWrite(string $instruction, ProductDraft $draft): array
    {
        if ($instruction === '') {
            return [];
        }

        return array_values(array_diff(AddProductSpecifications::numbers($instruction),
            AddProductSpecifications::numbers((string) $this->update->text), [(string) $draft->id]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->required(),
            'instruction' => $schema->string()->max(15000)->description('The operator\'s words about the change, verbatim: "добавь TDP 115 Вт", "убери Вес", "это б/у".'),
            'wanted' => $schema->string()->max(1000)->description('Characteristics to find on the web, in the operator\'s words, when they gave no value: "скорость записи".'),
        ];
    }
}
