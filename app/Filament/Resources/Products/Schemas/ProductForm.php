<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Schemas\ProductAttributeFields;
use App\Filament\Schemas\ProductPhotoUpload;
use App\Filament\Schemas\ProductTranslations;
use App\Filament\Schemas\RelatedCatalogForms;
use App\Filament\Support\ProductOptions;
use App\Filament\Support\ProductVariantFormState;
use App\Filament\Support\StockUnitRows;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupPrice;
use App\Services\Products\ManualProductAttributes;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductSourceTypes;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->dense()->columns(1)->components([
            Grid::make(['default' => 1, 'lg' => 3])
                ->extraAttributes(fn ($livewire): array => method_exists($livewire, 'pollProductAi')
                    && in_array($livewire->productAiStatus, ['queued', 'running'], true)
                    ? ['wire:poll.3s' => 'pollProductAi'] : [])
                ->schema([
                    Section::make(__('Товар'))->schema([
                        TextInput::make('title')->label(__('Название'))->required()->maxLength(255)->columnSpanFull(),
                        Select::make('category_id')->label(__('Категория'))->relationship('category', 'name')
                            ->options(fn ($livewire): array => Category::query()->orderBy('name')->pluck('name', 'id')->all() + (method_exists($livewire, 'getProductAiOptions') ? $livewire->getProductAiOptions('category') : []))
                            ->getOptionLabelUsing(fn ($value, $livewire): ?string => (method_exists($livewire, 'getProductAiOptions') ? ($livewire->getProductAiOptions('category')[$value] ?? null) : null) ?? Category::find($value)?->name)
                            ->searchable()->preload()->required()->live()->createOptionForm(RelatedCatalogForms::category())
                            ->createOptionUsing(fn (array $data): int => RelatedCatalogForms::createCategory($data))
                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                $category = Category::find($state);
                                if ($category?->product_type_affinity) {
                                    $set('product_type', $category->product_type_affinity);
                                }
                                $set('variants', app(ManualProductAttributes::class)->applyCategory($get('variants') ?? [], $state));
                            }),
                        Select::make('brand_id')->label(__('Бренд'))->relationship('brand', 'name')->searchable()->preload()
                            ->options(fn ($livewire): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all() + (method_exists($livewire, 'getProductAiOptions') ? $livewire->getProductAiOptions('brand') : []))
                            ->getOptionLabelUsing(fn ($value, $livewire): ?string => (method_exists($livewire, 'getProductAiOptions') ? ($livewire->getProductAiOptions('brand')[$value] ?? null) : null) ?? Brand::find($value)?->name)
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Название'))->required()->maxLength(255)->unique(table: Brand::class, column: 'name'),
                                TextInput::make('country')->label(__('Страна'))->maxLength(255),
                            ]),
                        TextInput::make('model')->label(__('Модель'))->maxLength(255),
                        Hidden::make('product_type')->default('other')
                            ->dehydrateStateUsing(function (Get $get, ?string $state): string {
                                $affinity = Category::query()->whereKey($get('category_id'))->value('product_type_affinity');

                                return array_key_exists($affinity ?? '', ProductOptions::types())
                                    ? $affinity
                                    : (array_key_exists($state ?? '', ProductOptions::types()) ? $state : 'other');
                            }),
                    ])->compact()->gridContainer()->columns(['default' => 1, '@md' => 2, '@xl' => 3])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Section::make(__('Публикация'))->schema([
                        Select::make('status')->label(__('Статус'))->options(ProductOptions::statuses())->default('published')->required(),
                        Toggle::make('is_active')->label(__('Показывать в каталоге'))->default(true),
                        Toggle::make('is_featured')->label(__('Рекомендуемый')),
                    ])->compact(),
                    Section::make(__('Характеристики и конфигурации'))
                        ->contained(false)->schema([self::variants()])->columnSpanFull(),
                    Section::make(__('Описание'))
                        ->compact()->columnSpan(['default' => 1, 'lg' => 2])
                        ->schema([Textarea::make('description')->label(__('Описание'))->hiddenLabel()->rows(3)->autosize()->columnSpanFull()]),
                    Group::make([
                        Section::make(__('Фотографии'))->compact()->collapsed()
                            ->schema([ProductPhotoUpload::multiple()->dehydrated(false)]),
                        Section::make(__('Переводы'))->compact()->schema([ProductTranslations::make()])->collapsed(),
                        Section::make(__('Дополнительно'))->schema([
                            DateTimePicker::make('published_at')->label(__('Дата публикации')),
                            TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                        ])->compact()->collapsed(),
                    ])->columnSpan(1),
                ]),
            Section::make(__('Источники'))->schema([
                Repeater::make('sources')->relationship()->label(__('Источники данных'))->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Название'))->width('30%'),
                        TableColumn::make('URL'),
                        TableColumn::make(__('Тип'))->width('8rem'),
                    ])->compact()->schema([
                        TextInput::make('title')->label(__('Название'))->required()->maxLength(500),
                        TextInput::make('url')->label('URL')->url()->required(),
                        Select::make('source_type')->label(__('Тип'))->options(ProductSourceTypes::options())->default('web'),
                    ])->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::sourceData($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::sourceData($data))
                    ->defaultItems(0)
                    ->addActionLabel(__('Добавить источник'))->columnSpanFull(),
            ])->compact()->columnSpanFull()->collapsed(),
        ]);
    }

    private static function variants(): Repeater
    {
        return Repeater::make('variants')->relationship()->label(__('Конфигурации'))->hiddenLabel()
            ->schema([
                ProductAttributeFields::make(),
                Group::make([
                    Select::make('condition')->label(__('Состояние'))->options(ProductCondition::options())
                        ->default('new')->required()->live(),
                    TextInput::make('price')->label(__('Цена'))->numeric()->minValue(0),
                    TextInput::make('currency')->label(__('Валюта'))->default('CZK')->length(3),
                    Select::make('stock_status')->label(__('Наличие'))->options(ProductOptions::stockStatuses())->default('unknown')->required(),
                    TextInput::make('quantity')->label(__('Количество'))->integer()->minValue(0)->default(0)->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set, mixed $state) => $set('stock_units',
                            StockUnitRows::resize($get('stock_units') ?? [], (int) $state))),
                    Toggle::make('is_default')->label(__('Основной вариант'))->fixIndistinctState()
                        ->default(fn (Get $get): bool => count($get('../../variants') ?? []) <= 1),
                ])->columns(['default' => 1, 'sm' => 2, 'xl' => 3])->columnSpanFull(),
                ...self::groupPrices(),
                self::stockUnits(),
                Section::make(__('Дополнительные данные конфигурации'))->schema([
                    TextInput::make('name')->label(__('Название конфигурации'))->maxLength(255)
                        ->placeholder(__('Например: 16 GB / 512 GB'))->columnSpanFull(),
                    TextInput::make('sku')->label('SKU')->maxLength(255)
                        ->rules(fn (Get $get): array => [self::uniqueOfferIdentifier($get, 'sku')])
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('condition', $get('condition') ?? 'new')),
                    TextInput::make('mpn')->label('MPN')->maxLength(255),
                    TextInput::make('gtin')->label('GTIN')->maxLength(255)
                        ->rules(fn (Get $get): array => [self::uniqueOfferIdentifier($get, 'gtin')])
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('condition', $get('condition') ?? 'new')),
                    TextInput::make('color')->label(__('Цвет'))->maxLength(255),
                    TextInput::make('compare_at_price')->label(__('Старая цена'))->numeric()->minValue(0),
                    TextInput::make('warranty_months')->label(__('Гарантия, месяцев'))->integer()->minValue(0),

                    Toggle::make('is_active')->label(__('Активен'))->default(true),
                ])->compact()->columns(['default' => 1, 'md' => 3])->collapsed()->columnSpanFull(),
                Section::make(__('Переводы конфигурации'))->compact()->schema([ProductTranslations::make()])->collapsed(),
            ])->defaultItems(1)->columns(1)
            // The prices for groups are rows of their own; the form shows them as fields of the configuration.
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => [...$data, 'group_prices' => isset($data['id'])
                ? CustomerGroupPrice::query()->where('product_variant_id', $data['id'])->pluck('price', 'customer_group_id')
                    ->map(fn (mixed $price): string => rtrim(rtrim((string) $price, '0'), '.'))->all()
                : [],
                // A row per unit in stock, as many as the quantity.
                'stock_units' => StockUnitRows::resize(isset($data['id']) ? StockUnitRows::of((int) $data['id']) : [], (int) ($data['quantity'] ?? 0))])
            ->minItems(fn (string $operation): int => $operation === 'create' ? 1 : 0)
            ->deletable(fn (Repeater $component): bool => count($component->getRawState() ?? []) > 1)
            ->afterStateHydrated(fn (Repeater $component) => $component->rawState(ProductVariantFormState::ensureDefault($component->getRawState() ?? [])))
            ->afterStateUpdated(fn (Repeater $component) => $component->rawState(ProductVariantFormState::ensureDefault($component->getRawState() ?? [])))
            ->cloneable()
            ->cloneAction(fn (Action $action): Action => $action->label(__('catalog-form.copy_variant'))
                ->tooltip(__('catalog-form.copy_variant_help'))
                ->action(function (array $arguments, Repeater $component): void {
                    $items = $component->getRawState();
                    $items[$component->generateUuid()] = ProductVariantFormState::copy($items[$arguments['item']]);
                    $component->rawState($items);
                    $component->collapsed(false, shouldMakeComponentCollapsible: false);
                    $component->callAfterStateUpdated();
                }))
            ->itemLabel(fn (array $state): string => ($state['name'] ?? '') ?: (($state['sku'] ?? '') ?: __('Конфигурация товара')))
            ->addActionLabel(__('Добавить конфигурацию'))->collapsible()->columnSpanFull();
    }

    /**
     * A price field for every group of customers (operator, 2026-11-11): as many prices as
     * there are groups; one left empty means the group sees the ordinary price.
     *
     * @return list<Fieldset>
     */
    private static function groupPrices(): array
    {
        $fields = CustomerGroup::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (CustomerGroup $group): TextInput => TextInput::make("group_prices.{$group->id}")
                ->label($group->name.($group->is_active ? '' : ' · '.__('выключена')))
                ->numeric()->minValue(0)->placeholder(__('Как обычная цена')))->all();

        return $fields === [] ? [] : [Fieldset::make(__('Цены для групп клиентов'))->schema($fields)
            ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])->columnSpanFull()];
    }

    /**
     * A row per physical item in stock (operator, 2026-11-11: three near-identical used drives
     * are three units, each with a number of its own). The rows follow the quantity, and
     * removing a row lowers it.
     */
    private static function stockUnits(): Section
    {
        return Section::make(fn (Get $get): string => __('Единицы на складе').': '.count($get('stock_units') ?? []))
            ->description(__('Каждая штука в наличии — своя строка со своим номером. Добавить штуку: кнопка «Добавить единицу» или количество выше. Для своего учёта: на сайте этого не видно. Убранная строка с данными считается проданной по текущей цене; исправить это можно в разделе «Учёт».'))
            ->schema([
                Repeater::make('stock_units')->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Номер'))->width('7.5rem'),
                        TableColumn::make(__('Серийный номер')),
                        TableColumn::make(__('Цена закупки'))->width('9rem'),
                        TableColumn::make(__('Дата закупки'))->width('11rem'),
                        TableColumn::make(__('Поставщик')),
                        TableColumn::make(__('Заметка')),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('code')->hiddenLabel()->disabled()->dehydrated(false)->placeholder(__('новая')),
                        TextInput::make('serial_number')->hiddenLabel()->maxLength(255),
                        TextInput::make('purchase_price')->hiddenLabel()->numeric()->minValue(0),
                        DatePicker::make('purchased_at')->hiddenLabel()->maxDate(now()),
                        TextInput::make('supplier')->hiddenLabel()->maxLength(255),
                        TextInput::make('note')->hiddenLabel()->maxLength(2000),
                    ])
                    ->compact()->addActionLabel(__('Добавить единицу'))->reorderable(false)->defaultItems(0)
                    // A row added is an item that came in, a row taken away is that item out of the stock:
                    // the quantity follows either way.
                    ->afterStateUpdated(fn (Set $set, ?array $state) => $set('quantity', count($state ?? []))),
            ])
            // Open by default: the table is what the operator looks for under the quantity (2026-11-11, «не вижу как устроено»).
            // Always there, even with nothing in stock: this is where an item is added (2026-11-11, «не вижу где добавление единицы»).
            ->compact()->collapsible()->columnSpanFull();
    }

    private static function sourceData(array $data): array
    {
        $data['domain'] = parse_url($data['url'], PHP_URL_HOST);

        return $data;
    }

    private static function uniqueOfferIdentifier(Get $get, string $field): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($get, $field): void {
            if (blank($value)) {
                return;
            }
            $condition = $get('condition') ?? 'new';
            $matches = collect($get('../../variants') ?? [])->filter(fn ($row) => ($row[$field] ?? null) === $value && ($row['condition'] ?? 'new') === $condition);
            if ($matches->count() > 1) {
                $fail(__('validation.unique', ['attribute' => strtoupper($field)]));
            }
        };
    }
}
