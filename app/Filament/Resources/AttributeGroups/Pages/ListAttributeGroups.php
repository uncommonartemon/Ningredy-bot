<?php

namespace App\Filament\Resources\AttributeGroups\Pages;

use App\Filament\Concerns\InteractsWithCatalogCleanup;
use App\Filament\Resources\AttributeGroups\AttributeGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributeGroups extends ListRecords
{
    use InteractsWithCatalogCleanup;

    protected static string $resource = AttributeGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), $this->catalogCleanupAction('attribute_groups')];
    }
}
