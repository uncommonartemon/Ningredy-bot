<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Schemas\ProductCommercialAction;
use App\Filament\Support\ProductOptions;
use App\Models\Product;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['defaultVariant', 'category', 'brand']))
            ->extraAttributes(['class' => 'products-table-compact'])
            ->reorderableColumns()
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(),
                TextColumn::make('title')->label(__('Название'))
                    ->searchable(['title', 'model', 'description'])->sortable()
                    ->extraCellAttributes(['class' => 'products-title-cell'])
                    ->extraAttributes(fn (Product $record): array => ['title' => $record->title])
                    ->tooltip(fn (Product $record): string => $record->title),
                TextColumn::make('defaultVariant.sku')->label('SKU')->placeholder('—')->copyable()
                    ->extraCellAttributes(['class' => 'products-sku-cell'])
                    ->extraAttributes(fn (Product $record): array => ['title' => $record->defaultVariant?->sku ?? ''])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('variants', fn (Builder $variant): Builder => $variant
                            ->where('sku', 'like', '%'.$search.'%')
                            ->orWhere('mpn', 'like', '%'.$search.'%')
                            ->orWhere('gtin', 'like', '%'.$search.'%')))
                    ->tooltip(fn (Product $record): string => implode(' · ', array_filter([
                        $record->defaultVariant?->sku, __('product-table.primary_variant'),
                    ])))->toggleable(),
                TextColumn::make('category.name')->label(__('Категория'))->sortable()->toggleable(),
                TextColumn::make('defaultVariant.price')->label(__('Цена'))->numeric(decimalPlaces: 2)
                    ->suffix(fn (Product $record): string => filled($record->defaultVariant?->currency) ? ' '.$record->defaultVariant->currency : '')
                    ->tooltip(__('product-table.primary_variant'))->placeholder('—')->alignEnd()->toggleable(),
                TextColumn::make('visibility')->label(__('product-table.visibility'))->badge()
                    ->state(fn (Product $record): string => $record->status === 'published'
                        ? ($record->is_active ? 'visible' : 'hidden') : $record->status)
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'visible' => __('product-table.visible'), 'hidden' => __('product-table.hidden'),
                        default => ProductOptions::statuses()[$state] ?? __('Черновик'),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'visible' => 'success', 'draft' => 'warning', default => 'gray',
                    })->toggleable(),
                TextColumn::make('brand.name')->label(__('Бренд'))->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model')->label(__('Модель'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_type')->label(__('Тип'))->badge()
                    ->formatStateUsing(fn (string $state): string => ProductOptions::types()[$state] ?? __('Другая техника'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variants_count')->label(__('Варианты'))->counts('variants')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('Статус'))->badge()
                    ->formatStateUsing(fn (string $state): string => ProductOptions::statuses()[$state] ?? __('Черновик'))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success', 'archived' => 'gray', default => 'warning',
                    })->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')->label(__('product-table.show_on_site'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_featured')->label(__('Рекомендуемый'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('slug')->label('Slug')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('canonical_key')->label(__('Ключ дедупликации'))->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confidence')->label(__('Увер. AI'))->numeric(decimalPlaces: 2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort_order')->label(__('Порядок'))->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')->label(__('Публикация'))->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Создан'))->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('Изменён'))->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('status')->label(__('Статус'))->options(ProductOptions::statuses()),
                SelectFilter::make('category_id')->label(__('Категория'))->relationship('category', 'name'),
                SelectFilter::make('brand_id')->label(__('Бренд'))->relationship('brand', 'name'),
                SelectFilter::make('product_type')->label(__('Тип'))->options(ProductOptions::types()),
                TernaryFilter::make('is_active')->label(__('product-table.show_on_site')),
                TernaryFilter::make('is_featured')->label(__('Рекомендуемый')),
            ])->recordActions([ActionGroup::make([ProductCommercialAction::make(), ViewAction::make(), EditAction::make()])])
            ->recordUrl(fn ($record): string => ProductResource::getUrl('edit', ['record' => $record]));
    }
}
