<?php

namespace App\Filament\Resources\ProductDrafts\Pages;

use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProductDraft extends ViewRecord
{
    protected static string $resource = ProductDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
