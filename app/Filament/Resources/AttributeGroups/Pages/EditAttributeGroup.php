<?php

namespace App\Filament\Resources\AttributeGroups\Pages;

use App\Filament\Resources\AttributeGroups\AttributeGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAttributeGroup extends EditRecord
{
    protected static string $resource = AttributeGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->modalDescription(__('catalog-groups.delete_help'))];
    }
}
