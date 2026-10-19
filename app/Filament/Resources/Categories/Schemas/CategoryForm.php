<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Schemas\RelatedCatalogForms;
use App\Models\Category;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->dense()
            // EditRecord::defaultForm()/CreateRecord's equivalent fall back
            // to columns(2) on this top-level schema whenever it never calls
            // ->columns() itself - our single Grid below then only fills one
            // of those two tracks, halving the usable width for no reason.
            ->columns(1)
            ->components([
                // The layout follows the product page: what the category is on the
                // left, publication on the right, the longer settings below.
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Section::make(__('Категория'))
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('Название на английском'))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Каноническое имя и запасной вариант для API.'))
                                    ->required()->maxLength(255),
                                Select::make('product_type_affinity')
                                    ->label(__('Тип товаров категории'))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Агент и валидатор используют это поле, чтобы категория не противоречила типу товара.'))
                                    ->options([
                                        'laptop' => __('Ноутбук'),
                                        'desktop' => __('Настольный компьютер'),
                                        'component' => __('Компонент / периферия'),
                                        'other' => __('Другая техника'),
                                    ])
                                    ->required(),
                                Select::make('parent_id')
                                    ->label(__('Родительская категория'))
                                    ->relationship('parent', 'name', modifyQueryUsing: fn ($query, ?Category $record) => $query->whereNotIn('id', $record?->excludedParentIds() ?? []))
                                    ->rules([fn (?Category $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record): void {
                                        if (filled($value) && ! ($record ?? new Category)->acceptsParent((int) $value)) {
                                            $fail(__('Категория не может быть родителем самой себя или своего предка.'));
                                        }
                                    }])
                                    ->createOptionForm(RelatedCatalogForms::category())
                                    ->createOptionUsing(fn (array $data): int => RelatedCatalogForms::createCategory($data))
                                    ->searchable()
                                    ->preload(),
                            ])
                            ->gridContainer()->columns(['default' => 1, '@md' => 2, '@xl' => 3])
                            ->columnSpan(['default' => 1, 'lg' => 2]),
                        Section::make(__('Публикация'))
                            ->schema([
                                Toggle::make('is_active')->label(__('Активна'))->default(true),
                                TextInput::make('sort_order')->label(__('Порядок'))->numeric()->minValue(0)->default(0),
                            ]),
                        Section::make(__('Характеристики нового товара'))
                            ->description(__('Эти строки появятся пустыми после выбора категории. Значения заполняются у товара; состав и порядок можно изменить здесь.'))
                            ->schema([
                                RelatedCatalogForms::defaultAttributes()->dehydrated(false)
                                    ->afterStateHydrated(fn (Select $component, ?Category $record) => $component->state(
                                        $record?->attributeDefinitions()->wherePivot('is_default', true)->pluck('attribute_definitions.id')->all() ?? []
                                    ))
                                    ->saveRelationshipsUsing(fn (Category $record, ?array $state) => $record->syncDefaultAttributes($state ?? [])),
                            ])
                            ->columnSpanFull(),
                        Section::make(__('Поиск фотографий'))
                            ->schema([
                                ToggleButtons::make('gallery_search_strategy')
                                    ->label(__('Стратегия'))
                                    ->options([
                                        Category::GALLERY_SEARCH_AUTO => __('Авто'),
                                        Category::GALLERY_SEARCH_VISION_FIRST => 'Vision-first',
                                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'Playwright-first',
                                    ])
                                    ->icons([
                                        Category::GALLERY_SEARCH_AUTO => 'heroicon-o-bolt',
                                        Category::GALLERY_SEARCH_VISION_FIRST => 'heroicon-o-eye',
                                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => 'heroicon-o-cursor-arrow-rays',
                                    ])
                                    ->tooltips([
                                        Category::GALLERY_SEARCH_AUTO => __('Стандартный сценарий: Playwright обучается, когда статики не хватает.'),
                                        Category::GALLERY_SEARCH_VISION_FIRST => __('Только статичные фото и уже готовый рецепт домена — новый рецепт не обучается.'),
                                        Category::GALLERY_SEARCH_PLAYWRIGHT_FIRST => __('Галерея сразу раскрывается браузером.'),
                                    ])
                                    ->default(Category::GALLERY_SEARCH_AUTO)
                                    ->inline()
                                    ->grouped()
                                    ->required()
                                    ->columnSpanFull(),
                                TextInput::make('minimum_verified_images')
                                    ->label(__('Минимум проверенных фото'))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Успех при этом количестве; если источник отдаёт больше, галерея заполняется до общего лимита 10.'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10)
                                    ->default(3)
                                    ->required(),
                                TextInput::make('minimum_image_width')
                                    ->label(__('Мин. ширина фото, px'))
                                    ->placeholder(__('глобальное'))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Пусто = глобальное значение из настроек AI.'))
                                    ->numeric()
                                    ->minValue(100)
                                    ->maxValue(4000)
                                    ->suffix('px'),
                                TextInput::make('minimum_image_height')
                                    ->label(__('Мин. высота фото, px'))
                                    ->placeholder(__('глобальное'))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Пусто = глобальное значение (по умолчанию — любая).'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(4000)
                                    ->suffix('px'),
                                Textarea::make('product_search_hint')
                                    ->label(__('Подсказка агенту при поиске'))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Например: «не используй фото товара в коробке». Агент видит правило после определения категории.'))
                                    ->rows(2)
                                    ->autosize()
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                            ])
                            ->columns(['default' => 1, 'md' => 3])
                            ->columnSpan(['default' => 1, 'lg' => 2]),
                        Section::make(__('Переводы'))
                            ->schema([
                                Repeater::make('translations')
                                    ->relationship()
                                    ->label(__('Названия категории'))
                                    ->hiddenLabel()
                                    ->table([
                                        TableColumn::make(__('Язык'))->width('9rem'),
                                        TableColumn::make(__('Название')),
                                    ])
                                    ->compact()
                                    ->schema([
                                        Select::make('locale')->label(__('Язык'))->options([
                                            'en' => 'English',
                                            'cs' => 'Čeština',
                                            'uk' => __('Українська'),
                                        ])->disableOptionsWhenSelectedInSiblingRepeaterItems()->required(),
                                        TextInput::make('name')->label(__('Название'))->required()->maxLength(255),
                                    ])
                                    ->defaultItems(0)
                                    ->addActionLabel(__('Добавить перевод'))
                                    ->columnSpanFull(),
                            ])
                            ->collapsed(),
                    ]),
            ]);
    }
}
