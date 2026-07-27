<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Pages;

use App\Filament\Resources\ProductGalleryRecipes\ProductGalleryRecipeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductGalleryRecipe extends EditRecord
{
    protected static string $resource = ProductGalleryRecipeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $recipe = is_array($data['recipe'] ?? null) ? $data['recipe'] : [];

        foreach (['collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors'] as $key) {
            $data[$key] = array_values(array_filter(
                $recipe[$key] ?? [],
                fn (mixed $selector): bool => is_string($selector) && $selector !== '',
            ));
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $recipe = [];

        foreach (['collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors'] as $key) {
            $recipe[$key] = array_values(array_filter(
                $data[$key] ?? [],
                fn (mixed $selector): bool => is_string($selector) && trim($selector) !== '',
            ));
            unset($data[$key]);
        }

        $data['recipe'] = $recipe;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Удалить и обучить заново'),
        ];
    }
}
