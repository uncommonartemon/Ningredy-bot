<?php

namespace App\Filament\Resources\ProductSourceDomains\Pages;

use App\Filament\Resources\ProductSourceDomains\ProductSourceDomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductSourceDomains extends ListRecords
{
    protected static string $resource = ProductSourceDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
