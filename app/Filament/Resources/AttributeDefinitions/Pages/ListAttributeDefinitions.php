<?php

namespace App\Filament\Resources\AttributeDefinitions\Pages;

use App\Filament\Concerns\InteractsWithCatalogCleanup;
use App\Filament\Resources\AttributeDefinitions\AttributeDefinitionResource;
use App\Filament\Support\CharacteristicDictionaryActions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributeDefinitions extends ListRecords
{
    use InteractsWithCatalogCleanup;

    protected static string $resource = AttributeDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), CharacteristicDictionaryActions::review(), $this->catalogCleanupAction('attributes')];
    }
}
