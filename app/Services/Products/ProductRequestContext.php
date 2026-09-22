<?php

namespace App\Services\Products;

use App\Models\ProductDraft;

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

    /** The same identifier keys ProductIdentityMatcher and ProductSourceIdentityJudge read. */
    private const array IDENTIFIER_KEYS = ['model', 'sku', 'mpn', 'ean', 'upc', 'gtin'];

    /** @param array<string, mixed>|null $selectedProduct */
    private function __construct(
        private readonly ?string $originalRequest,
        private readonly ?array $selectedProduct,
        private readonly ?string $operatorHint = null,
    ) {}

    public static function forDraft(ProductDraft $draft): self
    {
        $request = $draft->relationLoaded('telegramUpdate')
            ? $draft->telegramUpdate?->text
            : $draft->telegramUpdate()->value('text');
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
            ],
        );
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
        return new self($this->originalRequest, $this->selectedProduct, self::text($hint));
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
     * The fields an agent prompt or tool result carries, always all four, so
     * an absent request reads as "unavailable" rather than as a silently
     * missing key.
     *
     * @return array{original_operator_request: ?string, original_operator_request_status: string, selected_product: ?array<string, mixed>, operator_hint: ?string}
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
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
