<?php

namespace App\Filament\Resources\ProductDrafts\Pages;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductDraft extends EditRecord
{
    protected static string $resource = ProductDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
