<?php

namespace App\Filament\Schemas;

use App\Filament\Support\ProductOptions;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Small native dialogs shared by related-record selects. */
final class RelatedCatalogForms
{
    public static function attribute(): array
    {
        return [
            TextInput::make('label')->label(__('Название'))->required()->maxLength(255),
            self::attributeGroupSelect(),
            Select::make('data_type')->label(__('Тип данных'))->options([
                'text' => __('Текст'), 'number' => __('Число'), 'boolean' => __('Да/нет'),
            ])->default('text')->required(),
            TextInput::make('default_unit')->label(__('Единица по умолчанию'))->maxLength(24),
        ];
    }

    public static function attributeGroup(): array
    {
        return [
            TextInput::make('name')->label(__('catalog-groups.english_name'))->required()->maxLength(255)
                ->unique(table: AttributeGroup::class, column: 'name', ignorable: fn (?Model $record) => $record instanceof AttributeGroup ? $record : null, ignoreRecord: false)
                ->rules([fn (?Model $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                    if (AttributeGroup::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($record instanceof AttributeGroup && $record->exists, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
                        $fail(__('validation.unique', ['attribute' => __('catalog-groups.english_name')]));
                    }
                }]),
            TextInput::make('translations.cs')->label(__('catalog-groups.czech_name'))->maxLength(255),
            TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0)->required(),
            Toggle::make('highlight_in_filters')->label(__('catalog-groups.highlight'))->default(false)
                ->helperText(__('catalog-groups.highlight_help')),
        ];
    }

    public static function attributeGroupSelect(): Select
    {
        return Select::make('attribute_group_id')->label(__('catalog-groups.group'))
            ->options(fn (): array => AttributeGroup::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')
                ->get()->mapWithKeys(fn (AttributeGroup $group): array => [$group->id => $group->localizedName()])->all())
            ->searchable()->preload()->placeholder(__('catalog-groups.ungrouped'))
            ->createOptionForm(self::attributeGroup())
            ->createOptionUsing(fn (array $data): int => AttributeGroup::query()->create($data)->id)
            ->helperText(__('catalog-groups.optional_help'));
    }

    /** Native optgroups, also used by the nested attribute dialog in category forms. */
    public static function attributeOptions(): array
    {
        return AttributeDefinition::query()->with('group')->get()
            ->sortBy(fn (AttributeDefinition $definition): array => [
                $definition->group === null ? 1 : 0, $definition->group?->sort_order ?? 0,
                mb_strtolower($definition->group?->name ?? ''), $definition->group?->id ?? 0,
                $definition->sort_order, $definition->key,
            ])
            ->groupBy(fn (AttributeDefinition $definition): string => $definition->group?->localizedName() ?? __('catalog-groups.ungrouped'))
            ->map(fn ($definitions): array => $definitions->mapWithKeys(fn (AttributeDefinition $definition): array => [$definition->id => __($definition->label)])->all())
            ->all();
    }

    public static function category(): array
    {
        return [
            TextInput::make('name')->label(__('Название на английском'))->required()->maxLength(255)
                ->unique(table: Category::class, column: 'name'),
            Select::make('product_type_affinity')->label(__('Тип товаров категории'))
                ->options(ProductOptions::types())->required(),
            Select::make('parent_id')->label(__('Родительская категория'))
                ->options(fn (): array => Category::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->preload(),
            self::defaultAttributes()->dehydrated(true),
            Toggle::make('is_active')->label(__('Активна'))->default(true),
        ];
    }

    public static function defaultAttributes(): Select
    {
        return Select::make('default_attribute_ids')->label(__('Стартовые характеристики'))
            ->options(fn (): array => self::attributeOptions())
            ->multiple()->searchable()->preload()->reorderable()->default([])
            ->createOptionForm(self::attribute())
            ->createOptionUsing(fn (array $data): int => AttributeDefinition::query()->create($data)->id);
    }

    public static function createCategory(array $data): int
    {
        return DB::transaction(function () use ($data): int {
            $defaultIds = $data['default_attribute_ids'] ?? [];
            unset($data['default_attribute_ids']);
            $category = Category::query()->create($data);
            $category->syncDefaultAttributes($defaultIds);

            return $category->id;
        });
    }
}
