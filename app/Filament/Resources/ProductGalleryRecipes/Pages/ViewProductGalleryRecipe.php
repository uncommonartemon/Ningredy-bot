<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Pages;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use App\Filament\Resources\ProductGalleryRecipeVersions\ProductGalleryRecipeVersionResource;
use App\Jobs\TrainProductGalleryRecipe;
use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductGalleryRecipeRouter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

class ViewProductGalleryRecipe extends ViewRecord
{
    protected static string $resource = ProductGalleryRecipeResource::class;

    protected function getHeaderActions(): array
    {
        /** @var ProductGalleryRecipe $record */
        $record = $this->record;

        return [
            Action::make('toggleStatus')
                ->label(fn (): string => $record->status === 'disabled' ? __('Включить') : __('Отключить'))
                ->icon(fn (): string => $record->status === 'disabled' ? 'heroicon-o-play' : 'heroicon-o-pause')
                ->color(fn (): string => $record->status === 'disabled' ? 'success' : 'gray')
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['status' => $record->status === 'disabled' ? 'active' : 'disabled']);
                    Notification::make()
                        ->title($record->status === 'disabled' ? __('Рецепт отключён') : __('Рецепт включён'))
                        ->success()
                        ->send();
                }),
            Action::make('toggleBlocked')
                ->label(fn (): string => $this->domainBlockIsSet() ? __('Разблокировать домен') : __('Заблокировать домен'))
                ->icon(fn (): string => $this->domainBlockIsSet() ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                ->color(fn (): string => $this->domainBlockIsSet() ? 'success' : 'danger')
                ->requiresConfirmation()
                ->fillForm(fn (): array => ['block' => ! $this->domainBlockIsSet(), 'domain' => $this->record->domain])
                ->modalDescription(__('Действие относится ко всему домену. Независимые блокировки отдельных путей сохраняются.'))
                ->schema([
                    Hidden::make('block')->required()->rules(['boolean']),
                    Hidden::make('domain')->required(),
                    Textarea::make('reason')->label(__('Причина блокировки'))->rows(3)
                        ->visible(fn (Get $get): bool => (bool) $get('block')),
                ])
                ->action(function (array $data): void {
                    $record = $this->record->fresh();
                    if ($record->domain !== $data['domain']) {
                        throw ValidationException::withMessages(['domain' => __('Домен изменился. Откройте действие заново.')]);
                    }
                    $router = app(ProductGalleryRecipeRouter::class);
                    $blocking = (bool) $data['block'];
                    $blocking
                        ? $router->blockDomain($record->domain, trim((string) ($data['reason'] ?? '')) ?: __('Заблокировано вручную оператором в панели.'))
                        : $router->unblockDomain($record->domain);
                    Notification::make()->title($blocking ? __('Домен заблокирован') : __('Домен разблокирован'))
                        ->success()->send();
                    if (! $this->record->fresh()) {
                        $this->redirect(ProductGalleryRecipeResource::getUrl('index'));

                        return;
                    }
                    $this->record->refresh();
                }),
            Action::make('retrain')
                ->label(__('Проверить / переобучить'))
                ->icon('heroicon-o-sparkles')
                ->schema([
                    TextInput::make('product_url')
                        ->label(__('Ссылка на товарную карточку'))
                        ->url()
                        ->required()
                        ->default(fn (): string => 'https://'.$record->domain.'/'),
                ])
                ->action(function (array $data): void {
                    TrainProductGalleryRecipe::dispatch($data['product_url']);
                    Notification::make()
                        ->title(__('Обучение поставлено в очередь'))
                        ->body(__('История и результат появятся в журнале версий рецепта.'))
                        ->success()
                        ->send();
                }),
            Action::make('viewVersions')
                ->label(__('Посмотреть версии'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => ProductGalleryRecipeVersionResource::getUrl('index', [
                    'tableFilters' => ['domain' => ['value' => $record->domain]],
                ])),
            Action::make('editRecipeJson')
                ->label(__('Изменить JSON вручную'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Ручное редактирование рецепта'))
                ->modalDescription(__('Опасное действие: неверный JSON или селекторы сломают автоматический сбор фотографий для этого домена. Разрешены только CSS-селекторы, атрибуты изображений и ограниченные клики — произвольный JavaScript не исполняется.'))
                ->modalSubmitActionLabel(__('Сохранить JSON'))
                ->schema([
                    Textarea::make('recipe_json')
                        ->label(__('Рецепт (JSON)'))
                        ->rows(20)
                        ->default(fn (): string => json_encode(
                            $record->recipe ?? [],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ) ?: '{}')
                        ->rules([
                            fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                if (json_decode((string) $value, true) === null && trim((string) $value) !== 'null') {
                                    $fail(__('Некорректный JSON.'));
                                }
                            },
                        ])
                        ->required(),
                ])
                ->action(function (array $data) use ($record): void {
                    $record->update(['recipe' => json_decode($data['recipe_json'], true) ?? []]);
                    Notification::make()
                        ->title(__('Рецепт обновлён вручную'))
                        ->warning()
                        ->send();
                }),
            EditAction::make()
                ->label(__('Быстрое редактирование')),
        ];
    }

    private function domainBlockIsSet(): bool
    {
        return ProductGalleryRecipe::query()->where('domain', $this->record->domain)
            ->where('path_pattern', '*')->where('source_blocked', true)->exists();
    }
}
