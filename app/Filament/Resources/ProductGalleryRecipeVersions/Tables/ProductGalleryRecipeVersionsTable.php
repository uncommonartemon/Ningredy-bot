<?php

namespace App\Filament\Resources\ProductGalleryRecipeVersions\Tables;

use App\Models\ProductGalleryRecipeVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductGalleryRecipeVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('domain')->label('Домен')->searchable()->sortable(),
                TextColumn::make('trigger')->label('Причина')->badge(),
                TextColumn::make('status')
                    ->label('Результат')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'promoted' => 'success',
                        'rejected' => 'warning',
                        'failed' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('model')->label('Модель')->toggleable(),
                TextColumn::make('score')->label('Оценка')->numeric(2)->placeholder('—'),
                TextColumn::make('result.candidate_count')->label('Новых фото')->placeholder('—'),
                TextColumn::make('result.previous_count')->label('Было фото')->placeholder('—'),
                TextColumn::make('error')->label('Ошибка')->limit(60)->placeholder('—')
                    ->tooltip(fn (ProductGalleryRecipeVersion $record): ?string => $record->error),
                TextColumn::make('created_at')->label('Запуск')->dateTime('d.m.Y H:i:s')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'promoted' => 'Опубликован',
                    'rejected' => 'Отклонён',
                    'failed' => 'Ошибка',
                    'training' => 'Обучение',
                    'testing' => 'Проверка',
                ]),
            ])
            ->recordActions([
                Action::make('rollback')
                    ->label('Восстановить')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (ProductGalleryRecipeVersion $record): bool => is_array($record->recipe)
                        && $record->recipe !== []
                        && $record->galleryRecipe !== null)
                    ->action(function (ProductGalleryRecipeVersion $record): void {
                        $recipe = $record->galleryRecipe;
                        $previous = $recipe->recipe;
                        $recipe->update([
                            'recipe' => $record->recipe,
                            'status' => 'active',
                            'last_error' => null,
                        ]);
                        ProductGalleryRecipeVersion::query()->create([
                            'product_gallery_recipe_id' => $recipe->id,
                            'domain' => $recipe->domain,
                            'product_url' => $record->product_url,
                            'trigger' => 'manual_rollback',
                            'status' => 'promoted',
                            'provider' => $record->provider,
                            'model' => $record->model,
                            'previous_recipe' => $previous,
                            'recipe' => $record->recipe,
                            'result' => ['restored_version_id' => $record->id],
                            'score' => $record->score,
                            'promoted_at' => now(),
                        ]);
                        Notification::make()->title('Версия рецепта восстановлена')->success()->send();
                    }),
            ]);
    }
}
