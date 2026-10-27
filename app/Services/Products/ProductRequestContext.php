<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;

/**
 * What the operator asked for and which product research settled on, as one
 * value every agent judging a source page on the draft's behalf receives:
 * the gallery preflight, the recipe trainer, and the trainer's intent and
 * Vision tools.
 *
 * The run's own Telegram update is deliberately not where the request comes
 * from. A search is continued, retrained or topped up by a button press, and
 * that update is the right key for the run's budget, cancellation and audit
 * trail - but its text is "draft:continue-search:8", not a product. Reading
 * the request from it told the trainer, on every continued search, that the
 * operator had asked for a callback string: in the archived test run 16 of 45
 * training rounds received one, and the trainer confirmed the A2HWGG-008US,
 * -067MY and -034SG listings as "the product" for a request that named
 * A2HWGG-009IT. The request belongs to the draft, so it is read from the
 * draft, and a run bound to no draft says so instead of borrowing whatever
 * message happens to be nearest.
 */
final class ProductRequestContext
{
    /** Carries the draft through a source array (internal, underscore-prefixed like its neighbours). */
    public const string SOURCE_KEY = '_product_draft_id';

    /** Carries the draft through the browser/trainer context array. */
    public const string CONTEXT_KEY = 'product_draft_id';

    public const string STATUS_PROVIDED = 'provided';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    /** Request payload keys: what research found unlike the request (live #215), and the operator's codes no page showed. */
    public const string DIFFERENCES_KEY = '_request_differences';

    public const string UNCONFIRMED_CODES_KEY = '_unconfirmed_operator_codes';

    private const string DIFFERENCES_NOTE = 'Research observed none of these operator requirements on any page of the requested model and built the card for the closest observed configuration; the operator sees each difference beside the add button and decides. Judge pages and frames against selected_product on these points: a listed difference is not by itself a reason to reject a source, a frame or the card.';

    /** The same identifier keys ProductIdentityMatcher and ProductSourceIdentityJudge read. */
    private const array IDENTIFIER_KEYS = ['model', 'sku', 'mpn', 'ean', 'upc', 'gtin'];

    /** @param array<string, mixed>|null $selectedProduct */
    private function __construct(
        private readonly ?string $originalRequest,
        private readonly ?array $selectedProduct,
        private readonly ?string $operatorHint = null,
        private readonly array $inputContext = [],
        private readonly array $requestDifferences = [],
    ) {}

    public static function forDraft(ProductDraft $draft): self
    {
        $input = ProductInputContext::forDraft($draft);
        $request = $input['original_operator_request'];
        $specifications = collect($draft->specifications ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && trim((string) ($item['value'] ?? '')) !== '');

        return new self(
            self::text($request),
            [
                'title' => $draft->title,
                'brand' => $draft->brand,
                'model' => $draft->model,
                'identifiers' => $specifications
                    ->filter(fn (array $item): bool => in_array($item['key'] ?? null, self::IDENTIFIER_KEYS, true))
                    ->map(fn (array $item): array => ['key' => $item['key'], 'value' => (string) $item['value']])
                    ->values()
                    ->all(),
                'color' => $draft->color,
                'condition' => $draft->condition,
                'configuration' => $specifications
                    ->reject(fn (array $item): bool => in_array($item['key'] ?? null, self::IDENTIFIER_KEYS, true))
                    ->map(fn (array $item): array => [
                        'key' => (string) ($item['key'] ?? ''),
                        'name' => (string) ($item['name'] ?? ''),
                        'value' => (string) $item['value'],
                    ])
                    ->values()
                    ->all(),
                // What research learned about the identity, for every agent that
                // judges a page or a frame against it (live #279: research knew
                // "MSN 30035506 / MD 62518", the model said only "(30035506)", and
                // a shop's MD62518 photos were held as another chassis).
                ...(self::text($draft->research_notes) === null ? []
                    : ['research_notes' => mb_substr(self::text($draft->research_notes), 0, 2000)]),
            ],
            inputContext: $input,
            requestDifferences: self::stored($draft)['differences'],
        );
    }

    /**
     * Research's own word on where the found product differs from the request,
     * kept on the request so a continued search, every agent and the card read
     * the same list. Written on each research answer, so a new answer replaces it.
     */
    public static function rememberDifferences(TelegramUpdate $update, array $research): void
    {
        $differences = collect((array) ($research['request_differences'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item) && self::text($item['requested'] ?? null) !== null && self::text($item['found'] ?? null) !== null)
            ->map(fn (array $item): array => ['requirement' => self::text($item['requirement'] ?? null) ?? 'other',
                'requested' => self::text($item['requested']), 'found' => self::text($item['found']),
                'evidence' => self::text($item['evidence'] ?? null)])
            ->values()->all();
        $codes = collect((array) ($research['unconfirmed_operator_codes'] ?? []))
            ->map(fn (mixed $code): ?string => self::text($code))->filter()->values()->all();
        $fresh = $update->fresh() ?? $update;
        $payload = is_array($fresh->payload) ? $fresh->payload : [];
        if (($payload[self::DIFFERENCES_KEY] ?? []) === $differences && ($payload[self::UNCONFIRMED_CODES_KEY] ?? []) === $codes) {
            return;
        }
        $payload[self::DIFFERENCES_KEY] = $differences;
        $payload[self::UNCONFIRMED_CODES_KEY] = $codes;
        $fresh->update(['payload' => $payload]);
        $update->setAttribute('payload', $payload);
    }

    /** @return array{differences: list<array{requirement: string, requested: string, found: string, evidence: ?string}>, codes: list<string>} */
    public static function stored(ProductDraft $draft): array
    {
        $payload = $draft->telegramUpdate?->payload;
        $payload = is_array($payload) ? $payload : [];

        return [
            'differences' => array_values(array_filter(is_array($payload[self::DIFFERENCES_KEY] ?? null) ? $payload[self::DIFFERENCES_KEY] : [], 'is_array')),
            'codes' => array_values(array_filter(is_array($payload[self::UNCONFIRMED_CODES_KEY] ?? null) ? $payload[self::UNCONFIRMED_CODES_KEY] : [], 'is_string')),
        ];
    }

    /**
     * Rebuilt from the context array a source carried into the browser. A
     * missing or vanished draft is reported as unavailable, never guessed.
     *
     * @param  array<string, mixed>  $context
     */
    public static function fromContext(array $context): self
    {
        $draftId = $context[self::CONTEXT_KEY] ?? null;
        $draft = is_numeric($draftId) ? ProductDraft::query()->find((int) $draftId) : null;

        return $draft ? self::forDraft($draft) : self::unavailable();
    }

    public static function unavailable(): self
    {
        return new self(null, null);
    }

    /**
     * An operator's retraining note is added beside the original request -
     * it narrows what went wrong last time, it does not replace what was
     * asked for.
     */
    public function withOperatorHint(?string $hint): self
    {
        return new self($this->originalRequest, $this->selectedProduct, self::text($hint), $this->inputContext, $this->requestDifferences);
    }

    public function originalRequest(): ?string
    {
        return $this->originalRequest;
    }

    public function operatorHint(): ?string
    {
        return $this->operatorHint;
    }

    /** @return array<string, mixed>|null */
    public function selectedProduct(): ?array
    {
        return $this->selectedProduct;
    }

    /**
     * A human request and attachment observations travel separately. A bare
     * photo has no human request; its evidence and search keys remain present.
     *
     * @return array{original_operator_request: ?string, original_operator_request_status: string, selected_product: ?array<string, mixed>, operator_hint: ?string, input_evidence: array, search_keys: array, search_plan: array}
     */
    public function forAgent(): array
    {
        return [
            'original_operator_request' => $this->originalRequest,
            'original_operator_request_status' => $this->originalRequest === null
                ? self::STATUS_UNAVAILABLE
                : self::STATUS_PROVIDED,
            'selected_product' => $this->selectedProduct,
            'operator_hint' => $this->operatorHint,
            'input_evidence' => $this->inputContext['input_evidence'] ?? [],
            'search_keys' => $this->inputContext['search_keys'] ?? [],
            'search_plan' => $this->inputContext['search_plan'] ?? [],
            ...($this->requestDifferences === [] ? [] : ['request_differences' => [
                'note' => self::DIFFERENCES_NOTE, 'items' => $this->requestDifferences]]),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
