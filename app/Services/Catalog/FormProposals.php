<?php

namespace App\Services\Catalog;

use App\Ai\Agents\FormAdvisorAgent;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CharacteristicQuestion;
use App\Models\Product;
use App\Services\Ai\AiSettings;
use App\Services\Products\ManualProductAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After a product is formed, what it has beyond its category's form is put to the operator
 * (decision of 2026-11-13): «➕ to the form» makes the bot look for it in every product of the
 * category, «✖️ not needed» is kept and not asked again for that category. In the strict mode
 * those values were held back from the product; «➕» writes them too.
 *
 * A model looks at the product against the form and picks what is worth asking, with a reason
 * (decision of 2026-11-16): any form, the starter one or none at all, is completed this way, and
 * what the model does not pick is not asked.
 */
class FormProposals
{
    /** Questions in one message. */
    public const int PER_MESSAGE = FormAdvisorAgent::MAX_PROPOSALS;

    public function __construct(private readonly CategoryStandard $standard, private readonly AiSettings $settings) {}

    /**
     * @param  list<array{definition_id: int, value: string, unit: ?string, variant_id: ?int}>  $held  values the strict mode did not write
     * @return list<CharacteristicQuestion>
     */
    public function propose(Product $product, array $held = []): array
    {
        $category = $product->category()->first();
        if ($category === null) {
            return [];
        }
        // The form the category follows - its own or a parent's; a category with no form anywhere
        // gets its first rows proposed on itself.
        $target = $category->standardSource('form') ?? $category;
        $formDefinitions = $this->standard->form($category);
        $form = $formDefinitions->pluck('id')->flip();
        $identifiers = AttributeDefinition::query()->whereIn('key', AttributeDefinition::IDENTIFIER_KEYS)->pluck('id')->flip();
        $asked = CharacteristicQuestion::query()->where('kind', CharacteristicQuestion::FORM)->whereIn('status', ['open', 'declined'])->get()
            ->filter(fn (CharacteristicQuestion $question): bool => (int) ($question->payload['category_id'] ?? 0) === (int) $target->id);
        $askedIds = $asked->map(fn (CharacteristicQuestion $question): int => (int) ($question->payload['definition_id'] ?? 0))->flip();
        $found = [];
        $product->loadMissing(['attributes.definition', 'variants.attributes.definition']);
        foreach ($product->attributes->concat($product->variants->flatMap->attributes) as $value) {
            if ($value->definition !== null) {
                $found[$value->definition->id] ??= ['definition' => $value->definition, 'value' => CategoryStandard::written((string) $value->value, $value->unit),
                    'held' => false, 'unit' => $value->unit, 'variant_id' => null];
            }
        }
        foreach ($held as $row) {
            $definition = AttributeDefinition::query()->find($row['definition_id']);
            if ($definition !== null) {
                $found[$definition->id] ??= ['definition' => $definition, 'value' => (string) $row['value'], 'held' => true,
                    'unit' => $row['unit'] ?? null, 'variant_id' => $row['variant_id'] ?? null];
            }
        }
        $candidates = array_filter($found, fn (array $row, int $id): bool => ! $form->has($id) && ! $identifiers->has($id) && ! $askedIds->has($id),
            ARRAY_FILTER_USE_BOTH);
        if ($candidates === []) {
            return [];
        }
        $byKey = collect($candidates)->keyBy(fn (array $row): string => $row['definition']->key);
        $questions = [];
        foreach ($this->advise($target, $formDefinitions, $product, $found, $candidates, $asked->where('status', 'declined')) as $pick) {
            $row = $byKey->pull($pick['key']);
            if ($row === null) {
                // Not a candidate, or picked twice.
                continue;
            }
            $definition = $row['definition'];
            $questions[] = CharacteristicQuestion::query()->create(['kind' => CharacteristicQuestion::FORM, 'payload' => [
                'category_id' => $target->id, 'category' => $target->name, 'definition_id' => $definition->id, 'key' => $definition->key,
                'name' => (string) ($definition->translations['ru'] ?? __($definition->label)), 'value' => mb_substr($row['value'], 0, 255),
                'product_id' => $product->id, 'held' => $row['held'], 'unit' => $row['unit'], 'variant_id' => $row['variant_id'],
                'reason' => $pick['reason'], 'section' => $pick['section'],
            ]]);
        }

        return $questions;
    }

    /** The operator's answer to one proposal; the text says what was done. */
    public function answer(CharacteristicQuestion $question, bool $yes, ?string $answeredBy = null): string
    {
        if ($question->status !== 'open') {
            return 'Уже решено.';
        }
        $payload = $question->payload;
        $category = Category::query()->find($payload['category_id'] ?? 0);
        $definition = AttributeDefinition::query()->find($payload['definition_id'] ?? 0);
        if ($category === null || $definition === null) {
            $question->update(['status' => 'stale', 'answered_by' => $answeredBy, 'answered_at' => now()]);

            return 'Уже не актуально.';
        }
        if (! $yes) {
            $question->update(['status' => 'declined', 'answered_by' => $answeredBy, 'answered_at' => now()]);

            return '✖️ «'.$payload['name'].'» в форму не добавлено, больше не спрошу.';
        }
        DB::transaction(function () use ($category, $definition, $payload): void {
            $links = $category->attributeDefinitions()->newPivotQuery();
            $inForm = (clone $links)->where(fn ($query) => $query->where('is_default', true)->orWhere('is_required', true));
            $section = in_array($payload['section'] ?? null, [CategoryStandard::MAIN, CategoryStandard::EXTRA], true) ? $payload['section']
                : ((clone $inForm)->count() < 8 ? CategoryStandard::MAIN : CategoryStandard::EXTRA);
            $category->attributeDefinitions()->syncWithoutDetaching([$definition->id => [
                'is_default' => true, 'example' => $payload['value'] ?? null, 'section' => $section,
                'sort_order' => (int) (clone $links)->max('sort_order') + 1,
            ]]);
            if (($payload['held'] ?? false) && ($product = Product::query()->find($payload['product_id'] ?? 0)) !== null) {
                $typed = app(ManualProductAttributes::class)->normalize(['attribute_definition_id' => $definition->id,
                    'value' => (string) $payload['value'], 'unit' => $payload['unit'] ?? null]) ?? [];
                AttributeValue::query()->updateOrCreate(
                    $payload['variant_id'] ? ['attribute_definition_id' => $definition->id, 'product_variant_id' => $payload['variant_id']]
                        : ['attribute_definition_id' => $definition->id, 'product_id' => $product->id],
                    array_intersect_key($typed, array_flip(['value', 'normalized_value', 'numeric_value', 'boolean_value', 'unit'])));
            }
        });
        $question->update(['status' => 'applied', 'answered_by' => $answeredBy, 'answered_at' => now()]);

        return '➕ «'.$payload['name'].'» добавлено в форму «'.$category->name.'».';
    }

    /**
     * «➕ Все» / «✖️ Никакие» under a message: the answer goes to the proposals that message shows,
     * never to the ones after them; those come next, with their own buttons.
     *
     * @return array{text: string, keyboard: array<string, mixed>|null}
     */
    public function answerShown(int $productId, bool $yes, ?string $answeredBy = null): array
    {
        $done = array_map(fn (CharacteristicQuestion $question): string => $this->answer($question, $yes, $answeredBy),
            array_slice($this->open($productId), 0, self::PER_MESSAGE));
        $left = $this->message($productId);

        return ['text' => (implode("\n", $done) ?: 'Этих вопросов уже нет.').($left === null ? '' : "\n\n".$left['text']),
            'keyboard' => $left['keyboard'] ?? null];
    }

    /** @return array{text: string, keyboard: array<string, mixed>}|null the open proposals for a product, with their buttons */
    public function message(int $productId): ?array
    {
        $open = $this->open($productId);
        if ($open === []) {
            return null;
        }
        $first = $open[0]->payload;
        $category = Category::query()->find($first['category_id'] ?? 0);
        $shown = array_slice($open, 0, self::PER_MESSAGE);
        $lines = [$category !== null && $this->standard->form($category)->isEmpty()
            ? '🧩 У категории «'.$first['category'].'» ещё нет формы. Из найденного у товара #'.$productId.' в неё стоит взять:'
            : '🧩 В форму «'.$first['category'].'» стоит добавить то, что нашлось у товара #'.$productId.':'];
        $rows = [];
        foreach ($shown as $question) {
            $lines[] = '• '.$question->payload['name'].' — '.mb_substr((string) $question->payload['value'], 0, 60)
                .(filled($question->payload['reason'] ?? null) ? ' ('.mb_substr((string) $question->payload['reason'], 0, 100).')' : '');
            $rows[] = [['text' => '➕ '.mb_substr((string) $question->payload['name'], 0, 28), 'callback_data' => 'chq:y:'.$question->id],
                ['text' => '✖️ Не надо', 'callback_data' => 'chq:n:'.$question->id]];
        }
        if (count($open) > count($shown)) {
            $lines[] = 'Ещё '.(count($open) - count($shown)).' — после ответа на эти.';
        }
        $lines[] = 'Добавить в форму? Тогда бот будет искать их у каждого товара этой категории.';
        if (count($shown) > 1) {
            $rows[] = [['text' => '➕ Все', 'callback_data' => 'chqp:y:'.$productId], ['text' => '✖️ Никакие', 'callback_data' => 'chqp:n:'.$productId]];
        }

        return ['text' => implode("\n", $lines), 'keyboard' => ['inline_keyboard' => $rows]];
    }

    /** @return list<CharacteristicQuestion> the open proposals about one product */
    public function open(int $productId): array
    {
        return CharacteristicQuestion::query()->open()->where('kind', CharacteristicQuestion::FORM)->orderBy('id')->get()
            ->filter(fn (CharacteristicQuestion $question): bool => (int) ($question->payload['product_id'] ?? 0) === $productId)->values()->all();
    }

    /** @return list<CharacteristicQuestion> the open proposals for a category, for its page in the admin */
    public function openForCategory(Category $category): array
    {
        return CharacteristicQuestion::query()->open()->where('kind', CharacteristicQuestion::FORM)->orderBy('id')->get()
            ->filter(fn (CharacteristicQuestion $question): bool => (int) ($question->payload['category_id'] ?? 0) === (int) $category->id)->values()->all();
    }

    /**
     * The model's choice among the candidates. Nothing when it cannot be reached: the next product
     * of the category brings the question again.
     *
     * @param  Collection<int, AttributeDefinition>  $form
     * @param  array<int, array{definition: AttributeDefinition, value: string, held: bool, unit: ?string, variant_id: ?int}>  $card  everything the product has
     * @param  array<int, array{definition: AttributeDefinition, value: string, held: bool, unit: ?string, variant_id: ?int}>  $candidates  what the form lacks
     * @param  Collection<int, CharacteristicQuestion>  $declined
     * @return list<array{key: string, reason: string, section: string}>
     */
    private function advise(Category $target, Collection $form, Product $product, array $card, array $candidates, Collection $declined): array
    {
        $coverage = $this->standard->coverage($target);
        $note = trim((string) ($target->standardSource('note')?->card_standard ?? ''));
        $answer = $this->ask([
            'category' => $target->name,
            'form' => $form->map(fn (AttributeDefinition $definition): array => array_filter([
                'key' => $definition->key, 'name' => self::name($definition), 'example' => $definition->pivot->example,
                'importance' => $definition->pivot->is_required ? 'required' : 'desired',
            ], fn (mixed $value): bool => $value !== null && $value !== ''))->values()->all(),
            'operator_note' => $note === '' ? null : $note,
            'declined' => $declined->map(fn (CharacteristicQuestion $question): string => (string) ($question->payload['name'] ?? ''))
                ->filter()->unique()->values()->all(),
            'product' => ['title' => $product->title, 'model' => $product->model, 'characteristics' => collect($card)
                ->map(fn (array $row): array => ['name' => self::name($row['definition']), 'value' => mb_substr($row['value'], 0, 120)])->values()->take(80)->all()],
            'products_in_category' => $coverage['total'],
            'candidates' => collect($candidates)->map(fn (array $row, int $id): array => ['key' => $row['definition']->key,
                'name' => self::name($row['definition']), 'value' => mb_substr($row['value'], 0, 120),
                'products_with_it' => $coverage['filled'][$id] ?? 0])->values()->all(),
        ]);
        $picks = [];
        foreach ((array) ($answer['proposals'] ?? []) as $pick) {
            if (! is_array($pick) || ! is_string($pick['key'] ?? null)) {
                continue;
            }
            $picks[] = ['key' => $pick['key'], 'reason' => mb_substr(trim((string) ($pick['reason'] ?? '')), 0, 160),
                'section' => ($pick['section'] ?? null) === CategoryStandard::EXTRA ? CategoryStandard::EXTRA : CategoryStandard::MAIN];
        }

        return array_slice($picks, 0, FormAdvisorAgent::MAX_PROPOSALS);
    }

    /** @param array<string, mixed> $payload */
    private function ask(array $payload): ?array
    {
        $prompt = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $run = AiRun::query()->create([
            'provider' => $this->settings->providerFor('attribute_dictionary'),
            'model' => $this->settings->modelFor('attribute_dictionary'),
            'status' => 'running', 'prompt' => mb_substr((string) $prompt, 0, 60000), 'started_at' => now(),
            'activity' => ['stage' => 'form_proposals'],
        ]);
        try {
            $response = (new FormAdvisorAgent)->prompt((string) $prompt, provider: $run->provider, model: $run->model,
                timeout: (int) config('services.attribute_dictionary.form_timeout', 60));
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);

            return null;
        }
        $run->update(['status' => 'completed', 'usage' => $response->usage->toArray(), 'invocation_id' => $response->invocationId,
            'response' => $response->toArray(), 'completed_at' => now()]);

        return $response->toArray();
    }

    private static function name(AttributeDefinition $definition): string
    {
        return (string) ($definition->translations['en'] ?? $definition->label);
    }
}
