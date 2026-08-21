<?php

namespace App\Filament\Resources\ProductSourceDomains\Pages;

use App\Filament\Resources\ProductSourceDomains\ProductSourceDomainResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductSourceDomain extends EditRecord
{
    protected static string $resource = ProductSourceDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
