<?php

namespace App\Services\Telegram;

use App\Models\AttributeDefinition;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Catalog\AttributeDictionary;
use App\Services\Catalog\CategoryStandard;
use Illuminate\Support\Facades\Cache;

/**
 * What the operator does with the form report of a draft in Telegram (decision of 2026-11-13):
 * look for a field not found, write its value, pick one of the example's variants, or keep an
 * inexact value as it is. The card comes back after each, with the report updated.
 */
class DraftFormActions
{
    /** Fields shown at once; the rest after these are settled. */
    public const int PER_MESSAGE = 6;

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
