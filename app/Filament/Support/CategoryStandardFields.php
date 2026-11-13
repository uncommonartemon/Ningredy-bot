<?php

namespace App\Filament\Support;

use App\Filament\Schemas\RelatedCatalogForms;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryStandard;
use App\Services\Catalog\TitleComposer;
use App\Services\Products\ManualProductAttributes;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * The standard of a category in its form (decision of 2026-11-13): the table of characteristics
 * the bot fills for every product of the category, and the title built from them.
 */
final class CategoryStandardFields
{
    /** @var array<int, array{total: int, filled: array<int, int>, brand: int, model: int}> */
    private static array $coverage = [];

    public static function form(): Repeater
    {
        return Repeater::make('standard_form')->label(__('Форма категории'))
            ->helperText(__('Что бот ищет и заполняет у каждого товара категории, в этом порядке. Пример — как писать значение; несколько через « / » — допустимые варианты. Производитель и модель товара есть всегда. Пустая форма — действует форма родительской категории.'))
            ->table([
                TableColumn::make(__('Параметр'))->width('30%'),
                TableColumn::make(__('Пример')),
                TableColumn::make(__('Важность'))->width('10rem'),
                TableColumn::make(__('Раздел'))->width('11rem'),
                TableColumn::make(__('Заполнено'))->width('7rem'),
            ])->compact()
            ->schema([
                Select::make('attribute_definition_id')->hiddenLabel()->required()->searchable()->live()
                    ->options(fn (): array => RelatedCatalogForms::attributeOptions())
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                    ->createOptionForm(RelatedCatalogForms::attribute())
                    ->createOptionUsing(fn (array $data): int => AttributeDefinition::query()->create($data)->id)
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state, ?Category $record): void {
                        if (blank($get('example')) && $record !== null && is_numeric($state)) {
                            $set('example', app(CategoryStandard::class)->frequentValue($record, (int) $state));
                        }
                    }),
                TextInput::make('example')->hiddenLabel()->maxLength(255)->placeholder('16 GB · DIMM / SO-DIMM')
                    ->datalist(fn (Get $get): array => is_numeric($get('attribute_definition_id'))
                        ? app(ManualProductAttributes::class)->suggestions((int) $get('attribute_definition_id'), null) : []),
                Select::make('is_required')->hiddenLabel()->selectablePlaceholder(false)->default('0')
                    ->options(['1' => __('Обязательно'), '0' => __('Желательно')]),
                Select::make('section')->hiddenLabel()->selectablePlaceholder(false)->default(CategoryStandard::MAIN)
                    ->options([CategoryStandard::MAIN => __('Основные'), CategoryStandard::EXTRA => __('Дополнительные')]),
                TextEntry::make('coverage')->hiddenLabel()->placeholder('—')
                    ->state(function (Get $get, ?Category $record): ?string {
                        if ($record === null || ! is_numeric($get('attribute_definition_id'))) {
                            return null;
                        }
                        $coverage = self::coverage($record);

                        return $coverage['total'] === 0 ? null
                            : ($coverage['filled'][(int) $get('attribute_definition_id')] ?? 0).' '.__('из').' '.$coverage['total'];
                    }),
            ])
            ->reorderable()->defaultItems(0)->addActionLabel(__('Добавить параметр'))
            ->dehydrated(false)
            ->afterStateHydrated(fn (Repeater $component, ?Category $record) => $component->state(self::keyed(
                $record === null ? [] : app(CategoryStandard::class)->formRows($record))))
            ->saveRelationshipsUsing(fn (Category $record, ?array $state) => app(CategoryStandard::class)->syncForm($record, array_values($state ?? [])))
            ->hintAction(fn (Repeater $component): Action => self::collectAction($component))
            ->hintAction(fn (Repeater $component): Action => self::pasteAction($component))
            ->hintAction(fn (Repeater $component): Action => self::copyAction($component))
            ->columnSpanFull();
    }

    public static function title(): Repeater
    {
        return Repeater::make('title_parts')->label(__('Название товара'))
            ->helperText(fn (Get $get, ?Category $record): string => self::titlePreview(array_values((array) $get('title_parts')), $record))
            ->table([
                TableColumn::make(__('Элемент'))->width('30%'),
                TableColumn::make(__('Свой текст')),
                TableColumn::make(__('Перед'))->width('6rem'),
                TableColumn::make(__('После'))->width('6rem'),
                TableColumn::make(__('Слитно со следующим'))->width('9rem'),
            ])->compact()
            ->schema([
                Select::make('part')->hiddenLabel()->required()->searchable()->live()
                    ->options(fn (Get $get, ?Category $record): array => self::partOptions((array) $get('../../standard_form'), $record)),
                TextInput::make('text')->hiddenLabel()->maxLength(60)->live(onBlur: true)
                    ->visible(fn (Get $get): bool => $get('part') === 'text'),
                TextInput::make('before')->hiddenLabel()->maxLength(10)->live(onBlur: true),
                TextInput::make('after')->hiddenLabel()->maxLength(10)->live(onBlur: true),
                Toggle::make('glue')->hiddenLabel()->live(),
            ])
            ->reorderable()->defaultItems(0)->addActionLabel(__('Добавить элемент'))
            ->dehydrateStateUsing(fn (?array $state): ?array => $state === null || $state === [] ? null : array_values($state))
            ->live()
            ->columnSpanFull();
    }

    public static function note(): Textarea
    {
        return Textarea::make('card_standard')->label(__('Пометка к стандарту'))->rows(3)->maxLength(1000)
            ->helperText(__('Всё, чего не выразить таблицей: например, «без слов Brand new, Tray, Used в названии и значениях». Бот учитывает это у каждого товара категории.'))
            ->columnSpanFull();
    }

    public static function formOnly(): Toggle
    {
        return Toggle::make('form_only')->label(__('Писать в товар только поля формы'))
            ->helperText(__('Выключено: в товар пишется всё найденное, а характеристики вне формы бот предлагает добавить в форму. Включено: пишутся только поля формы, найденное сверх неё ждёт вашего решения.'));
    }

    /**
     * A sample of the title on the category's latest product: what it is and what it becomes.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    public static function titlePreview(array $parts, ?Category $record): string
    {
        if ($parts === []) {
            return (string) __('Пусто — название пишет бот, как раньше. Элементы перетаскиваются; пустой элемент выпадает вместе со своими «перед» и «после».');
        }
        $line = __('Шаблон').': '.TitleComposer::template($parts);
        $product = $record === null ? null : Product::query()->whereIn('category_id', app(CategoryStandard::class)->treeIds($record))->latest('id')->first();
        if ($product === null) {
            return $line;
        }
        $composer = app(TitleComposer::class);
        $built = $composer->build($parts, $composer->productFacts($product));

        return $line.' · '.__('Пример').': «'.$product->title.'» → «'.($built ?? __('не собралось: нет производителя, модели или кода')).'»';
    }

    /** @return array<string, string> */
    private static function partOptions(array $formState, ?Category $record): array
    {
        $ids = collect($formState)->pluck('attribute_definition_id')->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id);
        if ($ids->isEmpty() && $record !== null) {
            $ids = app(CategoryStandard::class)->form($record)->pluck('id');
        }
        $characteristics = AttributeDefinition::query()->whereIn('id', $ids)->get()
            ->sortBy(fn (AttributeDefinition $definition): int => (int) $ids->search($definition->id))
            ->mapWithKeys(fn (AttributeDefinition $definition): array => [$definition->key => __($definition->label)])->all();

        return ['brand' => (string) __('Производитель'), 'model' => (string) __('Модель'), 'mpn' => (string) __('Код производителя (P/N)'),
            'sku' => 'SKU', 'color' => (string) __('Цвет'), 'condition' => (string) __('Состояние (кроме нового)'), 'text' => (string) __('Свой текст'),
            ...$characteristics];
    }

    private static function collectAction(Repeater $component): Action
    {
        return Action::make('collectStandardForm')->label(__('Собрать из товаров'))->icon('heroicon-o-arrow-down-on-square-stack')
            ->visible(fn (?Category $record): bool => $record !== null)
            ->requiresConfirmation()->modalDescription(__('Добавит в форму характеристики, которые уже есть у товаров этой категории: самые частые первыми, пример — самое частое значение, «обязательно» — если есть у девяти товаров из десяти. Уже стоящие строки не меняются.'))
            ->action(function (?Category $record) use ($component): void {
                $rows = array_values((array) $component->getRawState());
                $added = app(CategoryStandard::class)->collectFromProducts($record, $rows);
                $component->rawState(self::keyed([...$rows, ...array_map(fn (array $row): array => [...$row, 'is_required' => $row['is_required'] ? '1' : '0'], $added)]));
                Notification::make()->success()->title(__('Добавлено строк: :count. Проверьте и нажмите «Сохранить».', ['count' => count($added)]))->send();
            });
    }

    private static function pasteAction(Repeater $component): Action
    {
        return Action::make('pasteStandardForm')->label(__('Вставить таблицу'))->icon('heroicon-o-clipboard-document-list')
            ->modalHeading(__('Вставить форму таблицей'))
            ->schema([
                Textarea::make('table')->label(__('Таблица'))->required()->rows(12)->maxLength(20000)
                    ->placeholder("Параметр\tПример\tВажность\nОбъём\t16 GB\tОбязательно\nНапряжение\t1.2 V\tЖелательно")
                    ->helperText(__('Строка — параметр, пример, важность; через табуляцию, «|» или «;». Строка-заголовок «Дополнительные …» открывает второй раздел. Параметры находятся в словаре; незнакомые бот сверяет со словарём и создаёт новые только при необходимости.')),
            ])
            ->action(function (array $data) use ($component): void {
                $result = app(CategoryStandard::class)->fromPastedTable((string) $data['table']);
                $rows = array_values((array) $component->getRawState());
                $taken = collect($rows)->pluck('attribute_definition_id')->map(fn (mixed $id): int => (int) $id)->flip();
                $new = array_values(array_filter($result['rows'], fn (array $row): bool => ! $taken->has($row['attribute_definition_id'])));
                $component->rawState(self::keyed([...$rows, ...array_map(fn (array $row): array => [...$row, 'is_required' => $row['is_required'] ? '1' : '0'], $new)]));
                Notification::make()->success()->title(__('Добавлено строк: :count. Проверьте и нажмите «Сохранить».', ['count' => count($new)]))
                    ->body(implode("\n", array_filter([
                        $result['created'] === [] ? null : __('Новые характеристики: :names.', ['names' => implode(', ', $result['created'])]),
                        $result['skipped'] === [] ? null : __('Не распознано: :names.', ['names' => implode(', ', $result['skipped'])]),
                    ])))->send();
            });
    }

    private static function copyAction(Repeater $component): Action
    {
        return Action::make('copyStandardForm')->label(__('Скопировать из категории'))->icon('heroicon-o-document-duplicate')
            ->schema([
                Select::make('category_id')->label(__('Категория'))->required()->searchable()
                    ->options(fn (?Category $record): array => Category::query()->whereKeyNot($record?->id ?? 0)->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->action(function (array $data) use ($component): void {
                $source = Category::query()->find($data['category_id']);
                $copied = $source === null ? [] : app(CategoryStandard::class)->formRows($source);
                $component->rawState(self::keyed(array_map(fn (array $row): array => [...$row, 'is_required' => $row['is_required'] ? '1' : '0'], $copied)));
                Notification::make()->success()->title(__('Скопировано строк: :count. Проверьте и нажмите «Сохранить».', ['count' => count($copied)]))->send();
            });
    }

    /** @return array<string, array<string, mixed>> rows under the keys a repeater holds them by */
    private static function keyed(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $row['is_required'] = ($row['is_required'] ?? false) === true || ($row['is_required'] ?? null) === '1' ? '1' : '0';
            $keyed[(string) Str::uuid()] = $row;
        }

        return $keyed;
    }

    /** @return array{total: int, filled: array<int, int>, brand: int, model: int} */
    private static function coverage(Category $category): array
    {
        return self::$coverage[$category->id] ??= app(CategoryStandard::class)->coverage($category);
    }
}
