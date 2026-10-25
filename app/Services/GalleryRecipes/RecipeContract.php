<?php

namespace App\Services\GalleryRecipes;

use App\Exceptions\InvalidGalleryRecipeException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validates one proposed plan. Does not run a browser, train, or activate recipes. */
class RecipeContract
{
    public function __construct(private readonly RecipePortability $portability) {}

    /** @return array<string, mixed> */
    public function validateRecipe(array $data, string $productPageTitle = '', array $productPageHeadings = []): array
    {
        $data['training_decision'] = is_string($data['training_decision'] ?? null)
            ? $data['training_decision']
            : 'propose_recipe';
        $data['page_kind'] = is_string($data['page_kind'] ?? null)
            ? $data['page_kind']
            : 'unknown';
        $data['page_assessment_evidence'] = collect(
            is_array($data['page_assessment_evidence'] ?? null)
                ? $data['page_assessment_evidence']
                : [],
        )
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => mb_substr(trim($item), 0, 500))
            ->unique()
            ->values()
            ->all();
        // Stored recipes and test fixtures created before ordered actions
        // remain valid; new strict AI responses always include this field.
        $data['actions'] = is_array($data['actions'] ?? null) ? $data['actions'] : [];
        // Same backward-compatibility rule for exclude_selectors: recipes
        // trained before this field existed simply exclude nothing.
        $data['exclude_selectors'] = is_array($data['exclude_selectors'] ?? null) ? $data['exclude_selectors'] : [];
        $data['actions'] = collect($data['actions'])
            ->map(function (mixed $action): mixed {
                if (! is_array($action)) {
                    return $action;
                }

                if (is_string($action['purpose'] ?? null)) {
                    $action['purpose'] = mb_substr(trim($action['purpose']), 0, 200);
                }

                return $action;
            })
            ->values()
            ->all();
        $data['wait_after_click_ms'] = $this->clampInt($data['wait_after_click_ms'] ?? null, 50, 1000, 150);
        $data['max_thumbnail_clicks'] = $this->clampInt($data['max_thumbnail_clicks'] ?? null, 0, 20, 5);
        $data['max_next_clicks'] = $this->clampInt($data['max_next_clicks'] ?? null, 0, 15, 5);
        // Free-text reasoning fields: truncate an overlong AI answer instead of
        // rejecting an otherwise-usable recipe outright over field length.
        if (is_string($data['expected_count_evidence'] ?? null)) {
            $data['expected_count_evidence'] = mb_substr($data['expected_count_evidence'], 0, 500);
        }
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = mb_substr($data['reason'], 0, 1000);
        }
        if (is_string($data['scope_reason'] ?? null)) {
            $data['scope_reason'] = mb_substr(trim($data['scope_reason']), 0, 1000);
        }

        // Scout exposes the DOM property as `current_src`, while the browser
        // runner already reads element.currentSrc automatically and recipe
        // attributes are deliberately limited to real HTML attribute names.
        // Normalize that observation alias before validation so one harmless
        // naming mismatch cannot burn every paid correction round.
        $data['attributes'] = collect(is_array($data['attributes'] ?? null) ? $data['attributes'] : [])
            ->filter(fn (mixed $attribute): bool => is_string($attribute))
            ->map(function (string $attribute): string {
                $attribute = strtolower(trim($attribute));

                return match ($attribute) {
                    'currentsrc', 'current_src', 'current-src' => 'src',
                    'source_srcset', 'source-srcset' => 'srcset',
                    default => str_starts_with($attribute, 'data_')
                        ? str_replace('_', '-', $attribute)
                        : $attribute,
                };
            })
            ->unique()
            ->values()
            ->all();

        // A terminal decision has no executable recipe. Validate its reason
        // and page assessment, but never force paid retries to fix unused
        // action/selector fields. No executable fields leave this branch.
        $decisionOnly = $data['training_decision'] === 'abandon_page';
        try {
            $data = Validator::make(
                $data,
                $this->recipeValidationRules($decisionOnly),
                $this->recipeValidationMessages(app()->getLocale()),
            )->validate();
        } catch (ValidationException $exception) {
            throw new InvalidGalleryRecipeException(
                $exception->getMessage(),
                $this->englishValidationMessage($data, $decisionOnly),
                self::validationRuleSignature($exception),
            );
        }

        if ($decisionOnly) {
            return $data;
        }

        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors', 'exclude_selectors'] as $key) {
            $data[$key] = collect($data[$key] ?? [])
                ->filter(fn (mixed $selector): bool => is_string($selector) && $this->safeSelector($selector))
                ->map(fn (string $selector): string => trim($selector))
                ->unique()->values()->all();
        }
        foreach (['active_image_selector', 'position_selector', 'gallery_scope_selector'] as $key) {
            if (! empty($data[$key]) && ! $this->safeSelector($data[$key])) {
                throw new InvalidGalleryRecipeException('Небезопасный селектор наблюдения.', 'Unsafe observation selector: '.$key, [$key]);
            }
        }

        foreach (array_merge([$data['frame_selectors'] ?? []], array_column($data['actions'], 'frame_selectors')) as $path) {
            foreach ($path ?? [] as $selector) {
                if (preg_match('/(?:javascript:|https?:|file:|xpath|\x00)/i', $selector)) {
                    throw new InvalidGalleryRecipeException('Небезопасный путь iframe.', 'Unsafe frame path.', ['frame_selectors']);
                }
            }
        }

        // Prefer a selector scoped to the product gallery over its broad suffix.
        // AI may return both `img.foo` and `.gallery img.foo`; executing both can
        // collect unrelated recommendation, recently-viewed or cart images that
        // happen to share the same utility classes with the real gallery.
        $collectSelectors = collect($data['collect_selectors']);
        $data['collect_selectors'] = $collectSelectors
            ->reject(function (string $selector) use ($collectSelectors): bool {
                return $collectSelectors->contains(function (string $other) use ($selector): bool {
                    if ($other === $selector || ! str_ends_with($other, $selector)) {
                        return false;
                    }

                    $prefix = substr($other, 0, -strlen($selector));

                    return preg_match('/(?:\\s|>|\\+|~)\\s*$/', $prefix) === 1;
                });
            })
            ->values()
            ->all();

        $data['actions'] = collect($data['actions'])
            ->filter(fn (mixed $action): bool => is_array($action)
                && is_string($action['selector'] ?? null)
                && $this->safeSelector($action['selector']))
            ->map(fn (array $action): array => $this->sanitizedAction($action))
            ->values()
            ->all();

        $data['attributes'] = collect($data['attributes'] ?? [])
            ->map(fn (string $attribute): string => strtolower(trim($attribute)))
            ->unique()->values()->all();

        if (($data['training_decision'] ?? 'propose_recipe') === 'propose_recipe' && $data['collect_selectors'] === []) {
            throw new InvalidGalleryRecipeException(
                'AI не вернул безопасный селектор сбора изображений.',
                'No safe image collection selector remains. Select an observed product gallery element.',
                ['collect_selectors.safe'],
            );
        }

        // Last, so it sees the selectors as they will actually run - after the
        // unsafe ones are dropped and the redundant ones folded away.
        $this->portability->rejectProductSpecificSelectors($data, $productPageTitle);
        // A shop can leave <title> generic while its visible H1 names the
        // product. Check each observed heading independently, never guessed
        // product names or a concatenation of unrelated page text.
        foreach ($productPageHeadings as $heading) {
            if (is_string($heading)) {
                $this->portability->rejectProductSpecificSelectors($data, $heading);
            }
        }

        return $data;
    }

    /**
     * Every field of one action, and nothing else, on its way to the browser.
     *
     * The set is read off the validation rules rather than written out a second
     * time. A hand-kept copy is how after_each_selector came to be asked for in
     * the prompt, accepted by the rules and checked by the validator while the
     * browser never once received it: the field was added to the contract and
     * to everything that reads it, and the one list that had to repeat it was
     * missed. Whatever the rules admit now passes; anything invented still does
     * not, which is the only reason this list exists.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function sanitizedAction(array $action): array
    {
        $sanitized = [];

        foreach ($this->recipeActionFields() as $field) {
            $value = $action[$field] ?? null;
            $sanitized[$field] = is_string($value) ? trim($value) : $value;
        }

        // A step the page only sometimes puts up - a consent wall a returning
        // visitor no longer sees, a region or age gate. Absent means skipped,
        // not failed, so one recipe holds for a first visit and for every later
        // one. Anything else, including a missing value, stays mandatory.
        if (array_key_exists('when', $sanitized)) {
            $sanitized['when'] = $sanitized['when'] === 'if_present' ? 'if_present' : 'always';
        }

        // The one check the rules cannot make: a selector may be well-formed and
        // still unsafe. An unsafe follow-up drops with its own settings, never
        // leaving a limit pointing at a selector that will not run.
        if (array_key_exists('after_each_selector', $sanitized)
            && ! (is_string($sanitized['after_each_selector']) && $this->safeSelector($sanitized['after_each_selector']))) {
            $sanitized['after_each_selector'] = null;
            $sanitized['after_each_limit'] = null;
            $sanitized['after_each_wait_after_ms'] = null;
        }

        return $sanitized;
    }

    /**
     * The action fields the contract admits, in declaration order.
     *
     * @return array<int, string>
     */
    public function recipeActionFields(): array
    {
        return collect(array_keys($this->recipeValidationRules()))
            ->filter(fn (string $rule): bool => str_starts_with($rule, 'actions.*.') && ! str_contains(substr($rule, strlen('actions.*.')), '.'))
            ->map(fn (string $rule): string => substr($rule, strlen('actions.*.')))
            ->values()
            ->all();
    }

    /** @return array<string, array<int, string>> */
    private function recipeValidationRules(bool $decisionOnly = false): array
    {
        $rules = [
            'training_decision' => ['required', 'in:propose_recipe,abandon_page'],
            'recipe_scope' => ['sometimes', 'nullable', 'in:domain,page_family'],
            'scope_reason' => ['required_if:recipe_scope,page_family', 'nullable', 'string', 'max:1000'],
            'page_kind' => ['required', 'in:product_card,product_family_landing,editorial_marketing,listing_or_comparison,non_product_page,unknown'],
            'page_assessment_evidence' => ['present', 'array', 'max:8'],
            'page_assessment_evidence.*' => ['string', 'max:500'],
            'gallery_present' => ['required', 'boolean'],
            'expected_image_count' => ['required', 'integer', 'min:0'],
            'expected_count_evidence' => ['required', 'string', 'max:500'],
            'content_confirmed_product' => ['required', 'boolean'],
            'actions' => ['present', 'array'],
            'actions.*.kind' => ['required', 'in:click,click_each,click_until_no_change,hover,scroll_into_view,scroll_each,select_variant'],
            'actions.*.selector' => ['required', 'string', 'max:300'],
            // index addresses ONE specific element among a selector's
            // matches, not a repeat count - a page can reasonably have more
            // than 20 elements matching a broad selector (real case,
            // 2026-09-14: techbuy.com.au, selector "a", needed index 25/46).
            // The actual safety ceiling belongs to limit/max_thumbnail_clicks/
            // max_next_clicks below (how many actions run), never to which
            // element one specific action addresses.
            'actions.*.index' => ['required', 'integer', 'between:0,200'],
            'actions.*.limit' => ['required', 'integer', 'between:1,20'],
            'actions.*.wait_after_ms' => ['required', 'integer', 'between:50,1500'],
            'actions.*.when' => ['nullable', 'in:always,if_present'],
            'actions.*.iteration_mode' => ['sometimes', 'in:elements,repeat,auto'],
            'actions.*.frame_selectors' => ['sometimes', 'array'],
            'actions.*.frame_selectors.*' => ['string', 'max:300'],
            'actions.*.after_each_selector' => ['nullable', 'string', 'max:300'],
            'actions.*.after_each_limit' => ['nullable', 'integer', 'between:1,20'],
            'actions.*.after_each_wait_after_ms' => ['nullable', 'integer', 'between:50,1500'],
            'actions.*.purpose' => ['required', 'string', 'max:200'],
            'pre_click_selectors' => ['present', 'array'],
            'collect_selectors' => ['present', 'array'],
            'active_image_selector' => ['nullable', 'string', 'max:300'],
            'position_selector' => ['nullable', 'string', 'max:300'],
            'gallery_scope_selector' => ['nullable', 'string', 'max:300'],
            'frame_selectors' => ['sometimes', 'array'],
            'frame_selectors.*' => ['string', 'max:300'],
            'thumbnail_selectors' => ['present', 'array'],
            'open_selectors' => ['present', 'array'],
            'next_selectors' => ['present', 'array'],
            'exclude_selectors' => ['present', 'array'],
            'pre_click_selectors.*' => ['string', 'max:300'],
            'collect_selectors.*' => ['string', 'max:300'],
            'thumbnail_selectors.*' => ['string', 'max:300'],
            'open_selectors.*' => ['string', 'max:300'],
            'next_selectors.*' => ['string', 'max:300'],
            'exclude_selectors.*' => ['string', 'max:300'],
            'attributes' => ['present', 'array', 'max:12'],
            'attributes.*' => ['regex:/^(?:src|href|srcset|data-[a-z0-9_-]+)$/i', 'max:80'],
            'max_thumbnail_clicks' => ['required', 'integer', 'between:0,20'],
            'max_next_clicks' => ['required', 'integer', 'between:0,15'],
            'wait_after_click_ms' => ['required', 'integer', 'between:50,1000'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'reason' => ['required', 'string', 'max:1000'],
        ];

        return $decisionOnly
            ? array_intersect_key($rules, array_flip([
                'training_decision', 'page_kind', 'page_assessment_evidence',
                'page_assessment_evidence.*', 'confidence', 'reason',
            ]))
            : $rules;
    }

    /**
     * The app locale is Russian (operator-facing Telegram text), but this
     * message is fed back to the AI trainer as previous_attempt_feedback and
     * attempt_history, alongside an otherwise entirely English prompt - so it
     * is rendered in English regardless of APP_LOCALE.
     */
    private function englishValidationMessage(array $data, bool $decisionOnly = false): string
    {
        $locale = app()->getLocale();
        app()->setLocale('en');

        try {
            Validator::make($data, $this->recipeValidationRules($decisionOnly), $this->recipeValidationMessages('en'))->validate();

            return 'The recipe failed schema validation.';
        } catch (ValidationException $exception) {
            return $exception->getMessage();
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * Identifies which rule(s) failed independent of which array index or
     * value triggered them ("actions.1.index" and "actions.0.index" are the
     * same recurring mistake, not two different ones) - this is what lets
     * the training loop notice "stuck repeating this" instead of only ever
     * seeing the message text change because the bad value itself changed.
     *
     * @return array<int, string>
     */
    private static function validationRuleSignature(ValidationException $exception): array
    {
        return collect($exception->validator->errors()->keys())
            ->map(fn (string $key): string => preg_replace('/\.\d+(?=\.|$)/', '.*', $key) ?? $key)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Laravel's default regex-failure message ("the attributes.2 field
     * format is invalid") doesn't say what format IS valid, which let the AI
     * repeat the same rejected value for all 3 rounds of a real training run
     * (it kept adding "alt" - not a URL-bearing attribute - to identify a
     * placeholder/logo carousel slide, a case the app already handles by
     * URL pattern after collection; see instructions() for the matching
     * prompt guidance). Only this one rule gets a clearer message; the rest
     * already get Laravel's normal localized text.
     *
     * @return array<string, string>
     */
    private function recipeValidationMessages(string $locale): array
    {
        return [
            'attributes.*.regex' => $locale === 'ru'
                ? 'Каждый элемент attributes должен быть ровно "src", "href", "srcset" либо именем атрибута "data-*" - другие атрибуты (например "alt") сюда не годятся; заглушки/логотипы приложение и так отсеивает по URL после сбора.'
                : 'Each attributes entry must be exactly "src", "href", "srcset", or a "data-*" attribute name - other attributes such as "alt" are not accepted here; the app already filters placeholder/logo images by URL pattern after collection, so there is no need to identify them via attributes.',
        ];
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    public function safeSelector(string $selector): bool
    {
        return trim($selector) !== ''
            && ! str_contains($selector, "\0")
            && ! preg_match('/(?:javascript:|https?:|file:|xpath|script\b|iframe\b)/i', $selector);
    }
}
