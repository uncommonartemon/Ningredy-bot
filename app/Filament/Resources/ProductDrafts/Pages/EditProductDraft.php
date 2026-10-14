<?php

namespace App\Filament\Resources\ProductDrafts\Pages;

use App\Filament\Resources\Concerns\GuardsCatalogEditRevision;
use App\Filament\Resources\ProductDrafts\ProductDraftResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductDraft extends EditRecord
{
    use GuardsCatalogEditRevision;

    protected static string $resource = ProductDraftResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function afterSave(): void
    {
        $this->rememberCatalogRevision();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
