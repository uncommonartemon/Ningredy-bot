<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Pages;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use App\Jobs\TrainProductGalleryRecipe;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProductGalleryRecipe extends EditRecord
{
    protected static string $resource = ProductGalleryRecipeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $recipe = is_array($data['recipe'] ?? null) ? $data['recipe'] : [];

        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors', 'attributes'] as $key) {
            $data[$key] = array_values(array_filter(
                $recipe[$key] ?? [],
                fn (mixed $value): bool => is_string($value) && $value !== '',
            ));
        }

        $data['max_thumbnail_clicks'] = (int) ($recipe['max_thumbnail_clicks'] ?? 20);
        $data['max_next_clicks'] = (int) ($recipe['max_next_clicks'] ?? 15);
        $data['wait_after_click_ms'] = (int) ($recipe['wait_after_click_ms'] ?? 100);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $recipe = [];

        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors', 'attributes'] as $key) {
            $recipe[$key] = array_values(array_filter(
                $data[$key] ?? [],
                fn (mixed $value): bool => is_string($value) && trim($value) !== '',
            ));
            unset($data[$key]);
        }

        foreach (['max_thumbnail_clicks', 'max_next_clicks', 'wait_after_click_ms'] as $key) {
            $recipe[$key] = (int) ($data[$key] ?? 0);
            unset($data[$key]);
        }

        $data['recipe'] = $recipe;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retrain')
                ->label('Проверить / переобучить')
                ->icon('heroicon-o-sparkles')
                ->schema([
                    TextInput::make('product_url')
                        ->label('Ссылка на товарную карточку')
                        ->url()
                        ->required()
                        ->default(fn (): string => 'https://'.$this->record->domain.'/'),
                ])
                ->action(function (array $data): void {
                    TrainProductGalleryRecipe::dispatch($data['product_url']);
                    Notification::make()
                        ->title('Обучение поставлено в очередь')
                        ->body('История и результат появятся в журнале версий рецепта.')
                        ->success()
                        ->send();
                }),
            DeleteAction::make()
                ->label('Удалить и обучить заново'),
        ];
    }
}
