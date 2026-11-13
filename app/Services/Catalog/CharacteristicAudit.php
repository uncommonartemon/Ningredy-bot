<?php

namespace App\Services\Catalog;

use App\Models\AttributeDefinition;
use App\Models\CharacteristicQuestion;

/**
 * The characteristic audit (decision of 2026-11-13): the dictionary's model reviews every
 * characteristic with its values and products. What it is sure of - duplicates to merge,
 * groups for characteristics without one - is applied at once; what it doubts is put to the
 * operator as a question with two buttons, and the answer is kept so it is not asked again.
 */
class CharacteristicAudit
{
    /** Questions sent at once; the rest wait for the next audit. */
    public const int MAX_QUESTIONS = 20;

    public function __construct(
        private readonly AttributeKeeper $keeper,
        private readonly AttributeDictionary $dictionary,
    ) {}

    /**
     * @return array{merged: list<array>, grouped: int, skipped: list<string>, questions: list<CharacteristicQuestion>, waiting: int}
     */
    public function run(?string $chatId = null): array
    {
        $review = $this->keeper->review();
        $sure = fn (array $items): array => array_values(array_filter($items, fn (array $item): bool => $item['certainty'] === 'sure'));
        $applied = $this->keeper->apply($sure($review['merges']), $sure($review['groups']));

        $open = CharacteristicQuestion::query()->open()->get()
            ->keyBy(fn (CharacteristicQuestion $question): string => CharacteristicQuestion::identity($question->kind, $question->payload));
        $questions = [];
        $doubtful = [
            ...array_map(fn (array $merge): array => [CharacteristicQuestion::MERGE, $merge], array_filter($review['merges'], fn (array $item): bool => $item['certainty'] !== 'sure')),
            ...array_map(fn (array $group): array => [CharacteristicQuestion::GROUP, $group], array_filter($review['groups'], fn (array $item): bool => $item['certainty'] !== 'sure')),
        ];
        foreach ($doubtful as [$kind, $payload]) {
            if (! $this->stillAQuestion($kind, $payload)) {
                continue;
            }
            unset($payload['certainty']);
            $identity = CharacteristicQuestion::identity($kind, $payload);
            $questions[] = $open->get($identity) ?? CharacteristicQuestion::query()->create(['kind' => $kind, 'payload' => $payload, 'chat_id' => $chatId]);
        }

        return [...$applied, 'questions' => array_slice($questions, 0, self::MAX_QUESTIONS),
            'waiting' => max(0, count($questions) - self::MAX_QUESTIONS)];
    }

    /** The operator's answer to a question; the text says what was done. */
    public function answer(CharacteristicQuestion $question, bool $yes, ?string $answeredBy = null): string
    {
        if ($question->status !== 'open') {
            return __('Уже решено.');
        }
        if (! $this->stillAQuestion($question->kind, $question->payload)) {
            $question->update(['status' => 'stale', 'answered_by' => $answeredBy, 'answered_at' => now()]);

            return __('Уже не актуально: характеристика изменилась после вопроса.');
        }
        if (! $yes) {
            $question->update(['status' => 'declined', 'answered_by' => $answeredBy, 'answered_at' => now()]);

            return $question->kind === CharacteristicQuestion::MERGE
                ? __('Оставлены раздельно. Больше не спрошу.')
                : __('Не трогаю. Больше не спрошу.');
        }
        $result = $question->kind === CharacteristicQuestion::MERGE
            ? $this->keeper->apply([$question->payload], [])
            : $this->keeper->apply([], [$question->payload]);
        $question->update(['status' => $result['skipped'] === [] ? 'applied' : 'stale', 'answered_by' => $answeredBy, 'answered_at' => now()]);
        if ($result['skipped'] !== []) {
            return __('Не сделано: :what', ['what' => implode('; ', $result['skipped'])]);
        }
        if ($result['merged'] !== []) {
            $merge = $result['merged'][0];

            return __('✅ Объединено: :from → :into, перенесено значений: :moved.', ['from' => $merge['from'], 'into' => $merge['into'], 'moved' => $merge['moved']]);
        }

        return __('✅ Поставлено в группу «:group».', ['group' => $question->payload['group']['name'] ?? '']);
    }

    public function summaryText(array $result): string
    {
        $lines = ['🧹 Аудит характеристик готов.'];
        $lines[] = $result['merged'] === [] ? 'Бесспорных дублей нет.' : 'Объединено сразу: '.count($result['merged']).'.';
        foreach ($result['merged'] as $merge) {
            $lines[] = '• '.$merge['from'].' → '.$merge['into'].' (перенесено значений: '.$merge['moved'].')';
        }
        $lines[] = 'Поставлено в группы: '.$result['grouped'].'.';
        foreach ($result['skipped'] as $skipped) {
            $lines[] = '⚠️ Не сделано: '.$skipped;
        }
        $lines[] = $result['questions'] === []
            ? 'Сомнительного нет.'
            : 'Сомнительных: '.count($result['questions']).' — ответьте кнопками ниже.'
                .($result['waiting'] > 0 ? ' Ещё '.$result['waiting'].' спрошу в следующий аудит.' : '');

        return implode("\n", $lines);
    }

    public function questionText(CharacteristicQuestion $question): string
    {
        $payload = $question->payload;
        if ($question->kind === CharacteristicQuestion::MERGE) {
            return '❓ Объединить «'.$this->described((string) $payload['from']).'» с «'.$this->described((string) $payload['into']).'»?'
                ."\nОстанется ".$payload['into'].', '.$payload['from'].' станет его другим названием.'
                .(filled($payload['reason'] ?? null) ? "\nСомнение: ".$payload['reason'] : '');
        }

        return '❓ Поставить «'.$this->described((string) $payload['key']).'» в группу «'.($payload['group']['name'] ?? '').'»?'
            .(filled($payload['reason'] ?? null) ? "\nСомнение: ".$payload['reason'] : '');
    }

    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    public function questionButtons(CharacteristicQuestion $question): array
    {
        $merge = $question->kind === CharacteristicQuestion::MERGE;

        return ['inline_keyboard' => [[
            ['text' => $merge ? '✅ Объединить' : '✅ В группу', 'callback_data' => 'chq:y:'.$question->id],
            ['text' => $merge ? '✖️ Оставить раздельно' : '✖️ Не трогать', 'callback_data' => 'chq:n:'.$question->id],
        ]]];
    }

    /** Whether the characteristics a question is about are still as they were when it was asked. */
    private function stillAQuestion(string $kind, array $payload): bool
    {
        $find = fn (mixed $key): ?AttributeDefinition => AttributeDefinition::query()->where('key', (string) $key)->first();
        if ($kind === CharacteristicQuestion::MERGE) {
            return $find($payload['from'] ?? null) !== null && $find($payload['into'] ?? null) !== null;
        }
        $definition = $find($payload['key'] ?? null);

        return $definition !== null && $definition->attribute_group_id === null;
    }

    private function described(string $key): string
    {
        $definition = $this->dictionary->known($key);
        if ($definition === null) {
            return $key;
        }
        $values = $definition->values()->distinct()->limit(3)->pluck('value')->map(fn (string $value): string => mb_substr($value, 0, 30))->all();

        return ($definition->translations['en'] ?? $definition->label).' ('.$key.($values === [] ? '' : ': '.implode(', ', $values)).')';
    }
}
