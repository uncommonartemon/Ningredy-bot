<?php

namespace App\Filament\Resources\AttributeDefinitions\Pages;

use App\Filament\Resources\AttributeDefinitions\AttributeDefinitionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAttributeDefinition extends EditRecord
{
    protected static string $resource = AttributeDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
