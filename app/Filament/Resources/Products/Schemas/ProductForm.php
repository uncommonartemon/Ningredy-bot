<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Schemas\ProductAttributeFields;
use App\Filament\Schemas\ProductPhotoUpload;
use App\Filament\Schemas\ProductTranslations;
use App\Filament\Schemas\RelatedCatalogForms;
use App\Filament\Support\ProductOptions;
use App\Filament\Support\ProductVariantFormState;
use App\Models\Brand;
use App\Models\Category;
use App\Services\Products\ManualProductAttributes;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductSourceTypes;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Group::make([
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
                    ])->columns(2),

                    Section::make(__('Характеристики и конфигурации'))
                        ->description(__('Выберите готовые значения или впишите свои. Новые значения появятся в подсказках после сохранения товара. Пустые стартовые строки можно не заполнять.'))
                        ->schema([self::variants()]),

                    Section::make(__('Фотографии'))
                        ->description(__('Перетащите фотографии сюда. Они сохранятся вместе с товаром.'))
                        ->schema([ProductPhotoUpload::multiple()->dehydrated(false)]),

                    Section::make(__('Описание'))
                        ->schema([Textarea::make('description')->label(__('Описание'))->hiddenLabel()->rows(5)->columnSpanFull()]),
                    Section::make(__('Переводы'))->schema([ProductTranslations::make()])->collapsed(),
                ])->columnSpan(['default' => 1, 'lg' => 2]),

                Group::make([
                    View::make('filament.products.form-assistant')
                        ->visible(fn ($livewire): bool => method_exists($livewire, 'sendProductAiMessage')),
                    Section::make(__('Публикация'))->schema([
                        Select::make('status')->label(__('Статус'))->options(ProductOptions::statuses())->default('published')->required(),
                        Toggle::make('is_active')->label(__('Показывать в каталоге'))->default(true),
                        Toggle::make('is_featured')->label(__('Рекомендуемый')),
                    ]),
                    Section::make(__('Дополнительно'))->schema([
                        DateTimePicker::make('published_at')->label(__('Дата публикации')),
                        TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                    ])->collapsed(),
                ])->columnSpan(1),
            ]),
            Section::make(__('Источники'))->schema([
                Repeater::make('sources')->relationship()->label(__('Источники данных'))->schema([
                    TextInput::make('title')->label(__('Название'))->required()->maxLength(500),
                    TextInput::make('url')->label('URL')->url()->required()->columnSpan(2),
                    Select::make('source_type')->label(__('Тип'))->options(ProductSourceTypes::options())->default('web'),
                ])->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::sourceData($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::sourceData($data))
                    ->columns(['default' => 1, 'md' => 4])->defaultItems(0)
                    ->addActionLabel(__('Добавить источник'))->columnSpanFull(),
            ])->columnSpanFull()->collapsed(),
        ]);
    }

    private static function variants(): Repeater
    {
        return Repeater::make('variants')->relationship()->label(__('Конфигурации'))
            ->schema([
                ProductAttributeFields::make(),
                Section::make(__('Цена и наличие'))->schema([
                    Select::make('condition')->label(__('Состояние'))->options(ProductCondition::options())
                        ->default('new')->required()->live()->columnSpanFull(),
                    TextInput::make('price')->label(__('Цена'))->numeric()->minValue(0),
                    TextInput::make('currency')->label(__('Валюта'))->default('CZK')->length(3),
                    Select::make('stock_status')->label(__('Наличие'))->options(ProductOptions::stockStatuses())->default('unknown')->required(),
                    TextInput::make('quantity')->label(__('Количество'))->integer()->minValue(0)->default(0)->required(),
                    Toggle::make('is_default')->label(__('Основной вариант'))->fixIndistinctState()
                        ->default(fn (Get $get): bool => count($get('../../variants') ?? []) <= 1),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
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
                ])->columns(['default' => 1, 'md' => 3])->collapsed()->columnSpanFull(),
                Section::make(__('Переводы конфигурации'))->schema([ProductTranslations::make()])->collapsed(),
            ])->defaultItems(1)->columns(1)
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
