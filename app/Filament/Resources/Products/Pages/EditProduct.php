<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openCatalog')->label('Открыть на сайте')->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => route('products.show', $this->record), true)
                ->visible(fn (): bool => $this->record->status === 'published' && $this->record->is_active),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
