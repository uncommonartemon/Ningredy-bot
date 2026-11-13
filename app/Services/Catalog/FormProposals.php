<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CharacteristicQuestion;
use App\Models\Product;
use App\Services\Products\ManualProductAttributes;
use Illuminate\Support\Facades\DB;

/**
 * After a product is formed, what it has beyond its category's form is put to the operator
 * (decision of 2026-11-13): «➕ to the form» makes the bot look for it in every product of the
 * category, «✖️ not needed» is kept and not asked again for that category. In the strict mode
 * those values were held back from the product; «➕» writes them too.
 */
class FormProposals
{
    /** Questions in one message. */
    public const int PER_MESSAGE = 8;

    public function __construct(private readonly CategoryStandard $standard) {}

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
        $target = $category->standardSource('form');
        $formDefinitions = $this->standard->form($category);
        // Only a form the operator worked on is completed this way: the starter rows of an untouched
        // category have no example, and every product of it would bring a list of proposals.
        if ($target === null || $formDefinitions->every(fn (AttributeDefinition $definition): bool => blank($definition->pivot->example))) {
            return [];
        }
        $form = $formDefinitions->pluck('id')->flip();
        $identifiers = AttributeDefinition::query()->whereIn('key', AttributeDefinition::IDENTIFIER_KEYS)->pluck('id')->flip();
        $asked = CharacteristicQuestion::query()->where('kind', CharacteristicQuestion::FORM)->whereIn('status', ['open', 'declined'])->get()
            ->filter(fn (CharacteristicQuestion $question): bool => (int) ($question->payload['category_id'] ?? 0) === (int) $target->id)
            ->map(fn (CharacteristicQuestion $question): int => (int) ($question->payload['definition_id'] ?? 0))->flip();
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
        $questions = [];
        foreach ($found as $id => $row) {
            if ($form->has($id) || $identifiers->has($id) || $asked->has($id)) {
                continue;
            }
            $definition = $row['definition'];
            $questions[] = CharacteristicQuestion::query()->create(['kind' => CharacteristicQuestion::FORM, 'payload' => [
                'category_id' => $target->id, 'category' => $target->name, 'definition_id' => $definition->id, 'key' => $definition->key,
                'name' => (string) ($definition->translations['ru'] ?? __($definition->label)), 'value' => mb_substr($row['value'], 0, 255),
                'product_id' => $product->id, 'held' => $row['held'], 'unit' => $row['unit'], 'variant_id' => $row['variant_id'],
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
            $category->attributeDefinitions()->syncWithoutDetaching([$definition->id => [
                'is_default' => true, 'example' => $payload['value'] ?? null,
                'section' => (clone $inForm)->count() < 8 ? CategoryStandard::MAIN : CategoryStandard::EXTRA,
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

    /** @return array{text: string, keyboard: array<string, mixed>}|null the open proposals for a product, with their buttons */
    public function message(int $productId): ?array
    {
        $open = $this->open($productId);
        if ($open === []) {
            return null;
        }
        $first = $open[0]->payload;
        $lines = ['🧩 В форме «'.$first['category'].'» нет характеристик, которые нашлись у товара #'.$productId.':'];
        $rows = [];
        foreach (array_slice($open, 0, self::PER_MESSAGE) as $question) {
            $lines[] = '• '.$question->payload['name'].' — '.mb_substr((string) $question->payload['value'], 0, 60);
            $rows[] = [['text' => '➕ '.mb_substr((string) $question->payload['name'], 0, 28), 'callback_data' => 'chq:y:'.$question->id],
                ['text' => '✖️ Не надо', 'callback_data' => 'chq:n:'.$question->id]];
        }
        $lines[] = 'Добавить в форму? Тогда бот будет искать их у каждого товара этой категории.';
        $rows[] = [['text' => '➕ Все', 'callback_data' => 'chqp:y:'.$productId], ['text' => '✖️ Никакие', 'callback_data' => 'chqp:n:'.$productId]];

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
}
