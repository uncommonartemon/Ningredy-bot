<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Pages;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use App\Filament\Resources\ProductGalleryRecipeVersions\ProductGalleryRecipeVersionResource;
use App\Jobs\TrainProductGalleryRecipe;
use App\Models\ProductGalleryRecipe;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProductGalleryRecipe extends ViewRecord
{
    protected static string $resource = ProductGalleryRecipeResource::class;

    protected function getHeaderActions(): array
    {
        /** @var ProductGalleryRecipe $record */
        $record = $this->record;

        return [
            Action::make('toggleStatus')
                ->label(fn (): string => $record->status === 'disabled' ? 'Включить' : 'Отключить')
                ->icon(fn (): string => $record->status === 'disabled' ? 'heroicon-o-play' : 'heroicon-o-pause')
                ->color(fn (): string => $record->status === 'disabled' ? 'success' : 'gray')
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['status' => $record->status === 'disabled' ? 'active' : 'disabled']);
                    Notification::make()
                        ->title($record->status === 'disabled' ? 'Рецепт отключён' : 'Рецепт включён')
                        ->success()
                        ->send();
                }),
            Action::make('toggleBlocked')
                ->label(fn (): string => $record->source_blocked ? 'Разблокировать домен' : 'Заблокировать домен')
                ->icon(fn (): string => $record->source_blocked ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                ->color(fn (): string => $record->source_blocked ? 'success' : 'danger')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $record->source_blocked
                    ? 'Домен снова будет участвовать в Web Search и обработке источников.'
                    : 'Домен не будет участвовать ни в Web Search, ни в HTML/Playwright-обработке ни для одного товара.')
                ->schema(fn (): array => $record->source_blocked ? [] : [
                    Textarea::make('reason')
                        ->label('Причина блокировки')
                        ->rows(3),
                ])
                ->action(function (array $data) use ($record): void {
                    $record->update([
                        'source_blocked' => ! $record->source_blocked,
                        'source_block_reason' => $record->source_blocked ? null : ($data['reason'] ?? null),
                        'source_blocked_at' => $record->source_blocked ? null : now(),
                    ]);
                    Notification::make()
                        ->title($record->source_blocked ? 'Домен заблокирован' : 'Домен разблокирован')
                        ->success()
                        ->send();
                }),
            Action::make('retrain')
                ->label('Проверить / переобучить')
                ->icon('heroicon-o-sparkles')
                ->schema([
                    TextInput::make('product_url')
                        ->label('Ссылка на товарную карточку')
                        ->url()
                        ->required()
                        ->default(fn (): string => 'https://'.$record->domain.'/'),
                ])
                ->action(function (array $data): void {
                    TrainProductGalleryRecipe::dispatch($data['product_url']);
                    Notification::make()
                        ->title('Обучение поставлено в очередь')
                        ->body('История и результат появятся в журнале версий рецепта.')
                        ->success()
                        ->send();
                }),
            Action::make('viewVersions')
                ->label('Посмотреть версии')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => ProductGalleryRecipeVersionResource::getUrl('index', [
                    'tableFilters' => ['domain' => ['value' => $record->domain]],
                ])),
            Action::make('editRecipeJson')
                ->label('Изменить JSON вручную')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Ручное редактирование рецепта')
                ->modalDescription('Опасное действие: неверный JSON или селекторы сломают автоматический сбор фотографий для этого домена. Разрешены только CSS-селекторы, атрибуты изображений и ограниченные клики — произвольный JavaScript не исполняется.')
                ->modalSubmitActionLabel('Сохранить JSON')
                ->schema([
                    Textarea::make('recipe_json')
                        ->label('Рецепт (JSON)')
                        ->rows(20)
                        ->default(fn (): string => json_encode(
                            $record->recipe ?? [],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ) ?: '{}')
                        ->rules([
                            fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                if (json_decode((string) $value, true) === null && trim((string) $value) !== 'null') {
                                    $fail('Некорректный JSON.');
                                }
                            },
                        ])
                        ->required(),
                ])
                ->action(function (array $data) use ($record): void {
                    $record->update(['recipe' => json_decode($data['recipe_json'], true) ?? []]);
                    Notification::make()
                        ->title('Рецепт обновлён вручную')
                        ->warning()
                        ->send();
                }),
            EditAction::make()
                ->label('Быстрое редактирование'),
        ];
    }
}
