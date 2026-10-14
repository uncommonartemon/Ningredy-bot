<?php

namespace App\Filament\Resources\AttributeGroups\Schemas;

use App\Filament\Schemas\RelatedCatalogForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttributeGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('catalog-groups.group'))
                ->description(__('catalog-groups.form_help'))
                ->schema(RelatedCatalogForms::attributeGroup())->columns(2),
        ]);
    }
}
