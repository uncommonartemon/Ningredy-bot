<?php

namespace App\Filament\Resources\CustomerGroups\Pages;

use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerGroup extends CreateRecord
{
    protected static string $resource = CustomerGroupResource::class;

    /** The new group opens at once: its invitation link is the next thing the admin needs. */
    protected function getRedirectUrl(): string
    {
        return CustomerGroupResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
