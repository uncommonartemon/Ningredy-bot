<?php

namespace App\Filament\Resources\AttributeDefinitions\Pages;

use App\Filament\Resources\AttributeDefinitions\AttributeDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributeDefinitions extends ListRecords
{
    protected static string $resource = AttributeDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
