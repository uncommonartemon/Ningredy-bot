<?php

namespace App\Ai\Tools;

use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\ProductSearch\SearchJournal;
use App\Services\Telegram\RequestUsageFootnote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * What the bot did in a search of its own (operator, 2026-11-11: «админ сказал: вот твой
 * последний поиск был такой-то, указал на ошибку… чтобы был респонс и инструмент»). Before,
 * the assistant could act on a draft but could not look back: asked «почему нет фото» or «ты
 * нашёл не то», it had nothing to answer from. This reads the journal the search itself
 * wrote - the pages opened and what each gave, what was read on the labels, how it ended.
 */
class ExplainSearch implements Tool
{
    /** How many of the chat's last searches a list holds. */
    private const int LISTED = 8;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Look back at the bot\'s own product searches in this chat. Without arguments: the last searches (request, when, '
            .'what came of it). With request_id or draft_id, or last=true for the most recent one: the whole story of that search - '
            .'the request, the card it produced and its photos with the reason each was accepted, every site opened and what it gave, '
            .'what was read on the labels and how it compared with the requested code, code database answers, how the search ended, '
            .'time and cost. Call it before answering any remark or question about a past search («почему нет фото», «не то фото», '
            .'«ты искал не то», «что ты делал», «почему так долго»), then answer from it in plain words and fix what can be fixed.';
    }

    public function handle(Request $request): Stringable|string
    {
        $chat = (string) $this->update->chat_id;
        $draftId = $request->integer('draft_id');
        $requestId = $request->integer('request_id');
        if ($draftId > 0) {
            $draft = ProductDraft::query()->find($draftId);
            $requestId = (int) ($draft?->telegram_update_id ?? 0);
        }
        if ($requestId <= 0 && filter_var($request['last'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $requestId = (int) ($this->searches($chat)->first()['request_id'] ?? 0);
        }
        if ($requestId <= 0) {
            return $this->json(['ok' => true, 'searches' => $this->searches($chat)->all(),
                'note' => 'Pass request_id, draft_id or last=true for the story of one search.']);
        }
        $search = TelegramUpdate::query()->find($requestId);
        if ($search === null || (string) $search->chat_id !== $chat) {
            return $this->json(['ok' => false, 'error' => 'No such search in this chat.']);
        }

        return $this->json(['ok' => true, ...$this->story($search)]);
    }

    /**
     * The chat's last requests that searched for a product, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function searches(string $chat): Collection
    {
        $searched = ProductSourceAttempt::query()->select('telegram_update_id')->distinct();

        return TelegramUpdate::query()->where('chat_id', $chat)->whereKeyNot($this->update->id)
            ->where(fn ($query) => $query->whereIn('id', $searched)->orWhereIn('id', ProductDraft::query()->select('telegram_update_id')))
            ->latest('id')->limit(self::LISTED)->get()
            ->map(fn (TelegramUpdate $search): array => $this->outline($search));
    }

    /** @return array<string, mixed> */
    private function outline(TelegramUpdate $search): array
    {
        $draft = ProductDraft::query()->where('telegram_update_id', $search->id)->latest('id')->first();

        return [
            'request_id' => $search->id, 'asked' => mb_substr(trim((string) $search->text), 0, 160) ?: '(photo or button)',
            'asked_at' => $search->created_at?->format('Y-m-d H:i'), 'request_status' => $search->status,
            'draft_id' => $draft?->id, 'draft_status' => $draft?->status, 'title' => $draft?->title,
            'photos' => $draft === null ? 0 : $this->photos($draft)->count(),
            'ended' => $draft?->gallery_search_stop_reason ?: ($draft === null ? 'no card was made' : 'finished'),
        ];
    }

    /** @return array<string, mixed> */
    private function story(TelegramUpdate $search): array
    {
        $draft = ProductDraft::query()->where('telegram_update_id', $search->id)->latest('id')->first();
        $labels = ProductSourceAttempt::query()->where('telegram_update_id', $search->id)->where('action', 'compare_labels')
            ->orderBy('id')->get(['product_url', 'output'])
            ->flatMap(fn (ProductSourceAttempt $row): array => array_map(fn (array $frame): array => [
                'site' => (string) parse_url((string) $row->product_url, PHP_URL_HOST),
                'read_on_the_photo' => (string) ($frame['read_marking'] ?? $frame['reviewer_marking'] ?? ''),
                'against_the_requested_code' => (string) ($frame['verdict'] ?? ''),
                'taken' => (bool) ($frame['publishable'] ?? false),
            ], (array) data_get($row->output, 'frames', [])))->take(30)->values()->all();

        return [
            'search' => [...$this->outline($search),
                'error' => $search->error === null ? null : mb_substr((string) $search->error, 0, 500),
                'time_and_cost' => trim(strip_tags((string) app(RequestUsageFootnote::class)->for($search->id, includeSearchDuration: true)))],
            'card' => $draft === null ? null : [
                'draft_id' => $draft->id, 'status' => $draft->status, 'title' => $draft->title, 'brand' => $draft->brand,
                'model' => $draft->model, 'category' => $draft->category, 'gallery' => $draft->gallery_status,
                'ended' => $draft->gallery_search_stop_reason, 'notes' => mb_substr((string) $draft->gallery_notes, 0, 600),
                'facts_checked_against' => $draft->specifications_reconciled_source_url,
                'photos' => $this->photos($draft)->values()->all(),
            ],
            'sites' => SearchJournal::pagesOpened($search->id, $draft, 16),
            'labels_read' => $labels,
            'code_databases' => SearchJournal::codeLookups($search->id),
            'steps' => SearchJournal::timeline($search->id, 45),
        ];
    }

    /**
     * The card's photos, wherever they are now: on the draft, or on the product it became.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function photos(ProductDraft $draft): Collection
    {
        $media = $draft->media()->orderByDesc('is_primary')->orderBy('sort_order')->get();
        if ($media->isEmpty() && $draft->approved_product_id !== null) {
            $media = Product::query()->find($draft->approved_product_id)?->media()->orderByDesc('is_primary')->orderBy('sort_order')->get() ?? collect();
        }

        return $media->values()->map(fn ($photo, int $index): array => [
            'position' => $index + 1, 'from' => (string) parse_url((string) (data_get($photo->photo_source_context, 'url') ?: $photo->source_url), PHP_URL_HOST),
            'page' => data_get($photo->photo_source_context, 'url'), 'size' => $photo->width.'x'.$photo->height,
            'check' => $photo->verification_status, 'why_taken' => mb_substr((string) $photo->verification_notes, 0, 240),
        ]);
    }

    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->integer()->description('The search\'s request number (the # the bot shows), when known.'),
            'draft_id' => $schema->integer()->description('The draft the search produced, when the operator names a draft.'),
            'last' => $schema->boolean()->description('True for the most recent search of this chat.'),
        ];
    }
}
