<?php

namespace App\Filament\Support;

use App\Filament\Schemas\RelatedCatalogForms;
use App\Models\AttributeDefinition;
use App\Services\Catalog\AttributeDictionary;
use App\Services\Catalog\AttributeKeeper;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use RuntimeException;
use Throwable;

/**
 * The admin's hands on the characteristic dictionary (decision of 2026-11-13): merge a duplicate
 * into the characteristic that stays, and let the dictionary's model propose merges and groups
 * for the whole list, applied only as ticked.
 */
final class CharacteristicDictionaryActions
{
    public static function merge(): Action
    {
        return Action::make('mergeCharacteristic')->label(__('Объединить с…'))->icon('heroicon-o-arrows-pointing-in')->color('gray')
            ->modalHeading(fn (AttributeDefinition $record): string => __('Объединить «:name» с другой характеристикой', ['name' => __($record->label)]))
            ->modalDescription(fn (AttributeDefinition $record): string => __('Значения (:count) переедут в выбранную характеристику, а «:name» станет её другим названием: бот больше не создаст её отдельно. Где у товара уже есть значение выбранной характеристики, остаётся оно.',
                ['count' => $record->values()->count(), 'name' => __($record->label)]))
            ->schema([
                Select::make('into')->label(__('Оставить характеристику'))->required()->searchable()
                    ->options(fn (AttributeDefinition $record): array => array_filter(array_map(
                        fn (array $options): array => array_diff_key($options, [$record->id => true]), RelatedCatalogForms::attributeOptions()))),
            ])
            ->action(function (AttributeDefinition $record, array $data): void {
                $into = AttributeDefinition::query()->find($data['into'] ?? null);
                try {
                    throw_if($into === null, RuntimeException::class, __('Выбранной характеристики уже нет.'));
                    $result = app(AttributeDictionary::class)->merge($record, $into);
                } catch (RuntimeException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->success()
                    ->title(__('Объединено: :from → :into', ['from' => $result['from'], 'into' => $result['into']]))
                    ->body(self::mergeLine($result))->send();
            });
    }

    public static function review(): Action
    {
        return Action::make('reviewDictionary')->label(__('Проверить словарь'))->icon('heroicon-o-sparkles')->color('gray')
            ->modalHeading(__('Проверка словаря характеристик'))
            ->modalDescription(__('Модель прочитала весь словарь. Отметьте, что применить: объединение переносит значения и делает старое название другим названием оставшейся характеристики; группа ставится только характеристикам без группы.'))
            ->modalSubmitActionLabel(__('Применить отмеченное'))
            ->mountUsing(function (Schema $schema): void {
                try {
                    $review = app(AttributeKeeper::class)->review();
                    $schema->fill(['review' => json_encode($review, JSON_UNESCAPED_UNICODE), 'error' => null,
                        'merges' => array_keys($review['merges']), 'groups' => array_keys($review['groups'])]);
                } catch (Throwable $exception) {
                    report($exception);
                    $schema->fill(['review' => json_encode(['merges' => [], 'groups' => []]), 'merges' => [], 'groups' => [],
                        'error' => __('Модель не ответила: :reason', ['reason' => mb_substr($exception->getMessage(), 0, 300)])]);
                }
            })
            ->schema([
                Hidden::make('review'),
                Hidden::make('error'),
                Text::make(fn (Get $get): string => (string) ($get('error') ?: __('Модель не нашла дублей и характеристик, которым нужна группа.')))
                    ->visible(fn (Get $get): bool => filled($get('error')) || self::proposals($get, 'merges') === [] && self::proposals($get, 'groups') === []),
                CheckboxList::make('merges')->label(__('Объединить'))->bulkToggleable()
                    ->options(fn (Get $get): array => array_map(fn (array $merge): string => $merge['from'].' → '.$merge['into'].' — '.$merge['reason'],
                        self::proposals($get, 'merges')))
                    ->visible(fn (Get $get): bool => self::proposals($get, 'merges') !== []),
                CheckboxList::make('groups')->label(__('Поставить в группу'))->bulkToggleable()
                    ->options(fn (Get $get): array => array_map(fn (array $group): string => $group['key'].' → '.$group['group']['name'].' — '.$group['reason'],
                        self::proposals($get, 'groups')))
                    ->visible(fn (Get $get): bool => self::proposals($get, 'groups') !== []),
            ])
            ->action(function (array $data): void {
                $review = json_decode((string) ($data['review'] ?? ''), true);
                $picked = fn (string $kind): array => array_values(array_intersect_key((array) ($review[$kind] ?? []),
                    array_flip(array_map('intval', (array) ($data[$kind] ?? [])))));
                $result = app(AttributeKeeper::class)->apply($picked('merges'), $picked('groups'));
                $lines = array_map(fn (array $merge): string => $merge['from'].' → '.$merge['into'].': '.self::mergeLine($merge), $result['merged']);
                $lines[] = __('Поставлено в группы: :count.', ['count' => $result['grouped']]);
                array_push($lines, ...array_map(fn (string $skipped): string => __('Не сделано: :what', ['what' => $skipped]), $result['skipped']));
                Notification::make()->title(__('Словарь обновлён'))->body(implode("\n", $lines))
                    ->{$result['skipped'] === [] ? 'success' : 'warning'}()->send();
            });
    }

    /** @return array<int, array<string, mixed>> */
    private static function proposals(Get $get, string $kind): array
    {
        $review = json_decode((string) ($get('review') ?? ''), true);

        return is_array($review) && is_array($review[$kind] ?? null) ? $review[$kind] : [];
    }

    /** @param  array{into: string, moved: int, dropped: int, swapped: bool}  $result */
    private static function mergeLine(array $result): string
    {
        return __('перенесено значений: :moved, совпало с уже записанными: :dropped.', ['moved' => $result['moved'], 'dropped' => $result['dropped']])
            .($result['swapped'] ? ' '.__('Ключ :key встроенный, поэтому осталась эта характеристика.', ['key' => $result['into']]) : '');
    }
}
