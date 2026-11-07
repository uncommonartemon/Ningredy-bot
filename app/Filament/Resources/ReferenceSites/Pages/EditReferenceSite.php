<?php

namespace App\Filament\Resources\ReferenceSites\Pages;

use App\Filament\Resources\ReferenceSites\ReferenceSiteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReferenceSite extends EditRecord
{
    protected static string $resource = ReferenceSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
