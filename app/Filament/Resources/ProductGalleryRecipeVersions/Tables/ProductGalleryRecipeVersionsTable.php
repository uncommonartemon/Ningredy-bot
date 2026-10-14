<?php

namespace App\Filament\Resources\ProductGalleryRecipeVersions\Tables;

use App\Models\ProductGalleryRecipeVersion;
use App\Services\Products\ProductGalleryRecipeRestorer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductGalleryRecipeVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('product_gallery_recipe_id')->label(__('Рецепт #'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('domain')->label(__('Домен'))->searchable()->sortable(),
                TextColumn::make('product_url')->label(__('Страница'))->limit(50)->url(fn (ProductGalleryRecipeVersion $record): string => $record->product_url)->openUrlInNewTab()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trigger')->label(__('Причина'))->badge(),
                TextColumn::make('status')
                    ->label(__('Результат'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'promoted' => 'success',
                        'rejected' => 'warning',
                        'failed' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('provider')->label(__('Провайдер'))->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model')->label(__('Модель'))->toggleable(),
                TextColumn::make('score')->label(__('Оценка'))->numeric(2)->placeholder('—'),
                TextColumn::make('result.candidate_count')->label(__('Новых фото'))->placeholder('—'),
                TextColumn::make('result.previous_count')->label(__('Было фото'))->placeholder('—'),
                TextColumn::make('error')->label(__('Ошибка'))->limit(60)->placeholder('—')
                    ->tooltip(fn (ProductGalleryRecipeVersion $record): ?string => $record->error),
                TextColumn::make('created_at')->label(__('Запуск'))->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('promoted_at')->label(__('Опубликована'))->dateTime('d.m.Y H:i:s')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'promoted' => __('Опубликован'),
                    'rejected' => __('Отклонён'),
                    'failed' => __('Ошибка'),
                    'training' => __('Обучение'),
                    'testing' => __('Проверка'),
                ]),
                Filter::make('domain')
                    ->schema([
                        TextInput::make('value')->label(__('Домен')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->where('domain', $data['value']),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                        ? __('Домен: ').$data['value']
                        : null),
            ])
            ->recordActions([
                Action::make('rollback')
                    ->label(__('Восстановить'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->disabled(fn (ProductGalleryRecipeVersion $record): bool => ! app(ProductGalleryRecipeRestorer::class)->canRestore($record))
                    ->tooltip(__('Доступны только ранее опубликованные версии с успешной проверкой.'))
                    ->action(function (ProductGalleryRecipeVersion $record): void {
                        app(ProductGalleryRecipeRestorer::class)->restore($record);
                        Notification::make()->title(__('Версия рецепта восстановлена'))->success()->send();
                    }),
            ]);
    }
}
