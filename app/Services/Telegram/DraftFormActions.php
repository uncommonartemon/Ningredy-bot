<?php

namespace App\Services\Telegram;

use App\Models\AttributeDefinition;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Catalog\AttributeDictionary;
use App\Services\Catalog\CatalogMap;
use App\Services\Catalog\CatalogPlacement;
use App\Services\Catalog\CategoryPlacement;
use App\Services\Catalog\CategoryStandard;
use Illuminate\Support\Facades\Cache;

/**
 * What the operator does with the form report of a draft in Telegram (decision of 2026-11-13):
 * look for a field not found, write its value, pick one of the example's variants, or keep an
 * inexact value as it is. The card comes back after each, with the report updated.
 *
 * The card also says where the product goes in the catalog map, and when it sits in a node that
 * has kinds, that its kind is not chosen and why; the operator picks the kind here (2026-11-25).
 */
class DraftFormActions
{
    /** Fields shown at once; the rest after these are settled. */
    public const int PER_MESSAGE = 6;

    /** The product types as the operator reads them in the chat, whatever the interface language. */
    public const array TYPE_NAMES = ['laptop' => 'ноутбук', 'desktop' => 'готовый ПК', 'component' => 'комплектующая', 'other' => 'другая техника'];

    public function __construct(private readonly CategoryStandard $standard) {}

    /** @return list<array{text: string, callback_data: string}>|null the card's button, when something in the form is left */
    public function cardButton(ProductDraft $draft): ?array
    {
        $statuses = $this->standard->statuses($draft);
        $missing = count(array_filter($statuses, fn (array $field): bool => $field['status'] === 'missing'));
        $inexact = count(array_filter($statuses, fn (array $field): bool => $field['status'] === 'inexact'));
        if ($missing === 0 && $inexact === 0) {
            return null;
        }

        return [['text' => '🧩 Форма: '.implode(' · ', array_filter([$missing > 0 ? $missing.' ❓' : null, $inexact > 0 ? $inexact.' ⚠️' : null])),
            'callback_data' => "draft:form:{$draft->id}"]];
    }

    /** @return array{text: string, keyboard: array<string, mixed>} the message with a row of buttons per field */
    public function menu(ProductDraft $draft): array
    {
        $open = array_values(array_filter($this->standard->statuses($draft), fn (array $field): bool => $field['status'] !== 'filled'));
        if ($open === []) {
            return ['text' => '🧩 Форма заполнена.', 'keyboard' => ['inline_keyboard' => [[['text' => '← К карточке', 'callback_data' => "draft:review:{$draft->id}"]]]]];
        }
        $ids = AttributeDefinition::query()->whereIn('key', array_column($open, 'key'))->pluck('id', 'key');
        $rows = [];
        $lines = ['🧩 Форма черновика #'.$draft->id.' — что осталось:'];
        if (array_filter($open, fn (array $field): bool => $field['status'] === 'missing') !== []) {
            $rows[] = [['text' => '🔎 Доискать всё ненайденное', 'callback_data' => "df:all:{$draft->id}"]];
        }
        foreach (array_slice($open, 0, self::PER_MESSAGE) as $field) {
            $id = (int) ($ids[$field['key']] ?? 0);
            if ($field['status'] === 'missing') {
                $lines[] = '❓ '.$field['name'].($field['required'] ? ' (обязательное)' : '');
                $rows[] = [['text' => '🔎 '.mb_substr($field['name'], 0, 24), 'callback_data' => "df:find:{$draft->id}:{$id}"],
                    ['text' => '✍️ Вписать', 'callback_data' => "df:write:{$draft->id}:{$id}"]];

                continue;
            }
            $lines[] = '⚠️ '.$field['name'].' — «'.mb_substr((string) $field['value'], 0, 40).'»'.($field['reason'] ? ': '.mb_substr($field['reason'], 0, 80) : '');
            $variants = array_slice($field['variants'], 0, 3);
            $rows[] = $variants !== []
                ? [...array_map(fn (string $variant, int $index): array => ['text' => mb_substr($variant, 0, 20), 'callback_data' => "df:set:{$draft->id}:{$id}:{$index}"],
                    $variants, array_keys($variants)), ['text' => '✅ Верно', 'callback_data' => "df:ok:{$draft->id}:{$id}"]]
                : [['text' => '✅ Верно', 'callback_data' => "df:ok:{$draft->id}:{$id}"], ['text' => '✏️ Исправить', 'callback_data' => "df:write:{$draft->id}:{$id}"]];
        }
        if (count($open) > self::PER_MESSAGE) {
            $lines[] = '… и ещё '.(count($open) - self::PER_MESSAGE).': покажу, когда эти будут решены.';
        }
        $rows[] = [['text' => '← К карточке', 'callback_data' => "draft:review:{$draft->id}"]];

        return ['text' => implode("\n", $lines), 'keyboard' => ['inline_keyboard' => $rows]];
    }

    /** The value the operator gave or picked, written as their word; the card's check is kept. */
    public function write(ProductDraft $draft, TelegramUpdate $update, AttributeDefinition $definition, string $value): void
    {
        $value = trim($value);
        $specifications = (array) ($draft->specifications ?? []);
        $row = ['key' => $definition->key, 'name' => (string) ($definition->translations['en'] ?? $definition->label), 'value' => $value,
            'source_url' => null, 'source_excerpt' => $value, 'operator_stated' => true];
        $replaced = false;
        foreach ($specifications as $index => $specification) {
            if (is_array($specification) && app(AttributeDictionary::class)->find(
                is_string($specification['key'] ?? null) ? $specification['key'] : null,
                is_string($specification['name'] ?? null) ? $specification['name'] : null)?->is($definition)) {
                $specifications[$index] = [...$specification, ...$row, 'key' => $specification['key'] ?? $definition->key];
                $replaced = true;

                break;
            }
        }
        if (! $replaced) {
            $specifications[] = $row;
        }
        $editor = app(TelegramDraftEditor::class);
        $editor->apply($draft, $update, $editor->proposalFor($draft, $update,
            ['fields' => ['specifications' => array_values($specifications)], 'commercial' => []],
            'Значение поля формы от оператора: '.($definition->translations['en'] ?? $definition->label).' — '.$value));
        $this->accept($draft->fresh(), $definition->key);
    }

    /**
     * The card's line about the product's place: the catalog path it goes to on «Добавить», and the
     * real cause when the server settled it or its kind is still open. Null for a manual card that
     * names no category yet: its own line asks for one.
     */
    public function categoryLine(ProductDraft $draft): ?string
    {
        if (blank($draft->category) && $draft->isManualUpload()) {
            return null;
        }
        $map = app(CatalogMap::class);
        $placement = app(CatalogPlacement::class)->place($draft->product_type, $draft->category);
        if ($placement->category === null) {
            return '📂 Категория не определена: '.($placement->candidates === []
                ? 'в каталоге нет категории для этого типа товара.'
                : 'этому типу товара подходят несколько разделов. Напишите, в какой поставить.');
        }
        $named = fn (?string $slug): string => '«'.($slug !== null && $map->node($slug) !== null ? $map->path($slug) : (string) $slug).'»';
        $type = fn (?string $value): string => self::TYPE_NAMES[$value] ?? (string) $value;
        $record = (array) ($draft->category_placement ?? []);
        $cause = match (true) {
            // The draft's own category is not where «Добавить» puts it: that is the cause to name.
            $placement->reason === CategoryPlacement::TYPE_CONFLICT => 'категория черновика '.$named($draft->category).' — для другого типа товара, а этот — '.$type($draft->product_type),
            $placement->reason === CategoryPlacement::UNKNOWN => 'категории «'.$draft->category.'» нет в каталоге',
            $placement->reason === CategoryPlacement::NONE_CHOSEN => 'категория не указана',
            // What the search asked for, while the draft still sits where the server put it.
            ($record['placed'] ?? null) !== $draft->category => null,
            ($record['reason'] ?? null) === CategoryPlacement::TYPE_CONFLICT => 'поиск назвал '.$named($record['requested'] ?? null).', а тип товара — '.$type($record['product_type'] ?? null),
            ($record['reason'] ?? null) === CategoryPlacement::UNKNOWN => 'поиск назвал «'.($record['requested'] ?? '').'», такой категории нет',
            ($record['reason'] ?? null) === CategoryPlacement::NONE_CHOSEN => 'поиск не выбрал категорию',
            $placement->awaitsKind() && ($record['reason'] ?? null) === CategoryPlacement::CHOSEN => 'поиск выбрал общую категорию',
            default => null,
        };
        $line = '📂 '.$map->path((string) $placement->slug());

        return $line.($placement->awaitsKind() ? ' — вид не выбран' : '').($cause !== null ? ' ('.$cause.')' : '');
    }

    /** @return list<array{text: string, callback_data: string}>|null the card's button when the product's kind is open */
    public function kindButton(ProductDraft $draft): ?array
    {
        return app(CatalogPlacement::class)->place($draft->product_type, $draft->category)->awaitsKind()
            ? [['text' => '📂 Выбрать вид', 'callback_data' => "draft:kind:{$draft->id}"]] : null;
    }

    /** @return array{text: string, keyboard: array<string, mixed>} one button per kind of the node the product sits in */
    public function kindMenu(ProductDraft $draft): array
    {
        $map = app(CatalogMap::class);
        $placement = app(CatalogPlacement::class)->place($draft->product_type, $draft->category);
        $back = [['text' => '← К карточке', 'callback_data' => "draft:review:{$draft->id}"]];
        if (! $placement->awaitsKind()) {
            return ['text' => '📂 Вид уже выбран: '.$map->path((string) $placement->slug()).'.', 'keyboard' => ['inline_keyboard' => [$back]]];
        }
        $buttons = array_map(fn (array $kind): array => ['text' => mb_substr($kind['name'], 0, 30), 'callback_data' => "dk:{$draft->id}:{$kind['id']}"],
            $map->kinds((string) $placement->slug()));

        return ['text' => '📂 Черновик #'.$draft->id.': '.$map->path((string) $placement->slug()).' — какой вид?',
            'keyboard' => ['inline_keyboard' => [...array_chunk($buttons, 2), $back]]];
    }

    /**
     * The kind the operator picked, written through the draft editor like any correction of theirs:
     * the type follows the category, and a kind of the card's own category keeps the card's check.
     */
    public function chooseKind(ProductDraft $draft, TelegramUpdate $update, int $categoryId): string
    {
        $placement = app(CatalogPlacement::class)->place($draft->product_type, $draft->category);
        $kind = collect(app(CatalogMap::class)->kinds((string) $placement->slug()))->firstWhere('id', $categoryId);
        if (! $placement->awaitsKind() || $kind === null) {
            return 'Этот вид уже не подходит: откройте карточку заново.';
        }
        $editor = app(TelegramDraftEditor::class);
        $editor->apply($draft, $update, $editor->proposalFor($draft, $update,
            ['fields' => ['category' => $kind['slug']], 'commercial' => []], 'Вид выбран оператором: '.$kind['name']));

        return 'Вид: '.$kind['name'];
    }

    /** An inexact value the operator keeps: it is not marked again on this draft. */
    public function accept(ProductDraft $draft, string $key): void
    {
        $review = (array) ($draft->form_review ?? []);
        $review['accepted'] = array_values(array_unique([...((array) ($review['accepted'] ?? [])), $key]));
        $draft->forceFill(['form_review' => $review])->saveQuietly();
    }

    /** The next plain message of this operator is the value of this field. */
    public function awaitValue(string $chatId, string $userId, int $draftId, int $definitionId): void
    {
        Cache::put(self::key($chatId, $userId), ['draft' => $draftId, 'definition' => $definitionId], now()->addMinutes(15));
    }

    /** @return array{draft: int, definition: int}|null the field whose value is awaited, taken once */
    public function awaited(string $chatId, string $userId): ?array
    {
        $awaited = Cache::pull(self::key($chatId, $userId));

        return is_array($awaited) ? $awaited : null;
    }

    public function forget(string $chatId, string $userId): void
    {
        Cache::forget(self::key($chatId, $userId));
    }

    private static function key(string $chatId, string $userId): string
    {
        return "draft-form-value:{$chatId}:{$userId}";
    }
}
