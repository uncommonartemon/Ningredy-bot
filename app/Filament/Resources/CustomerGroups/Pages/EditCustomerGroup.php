<?php

namespace App\Filament\Resources\CustomerGroups\Pages;

use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use App\Models\CustomerGroup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCustomerGroup extends EditRecord
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A link that went to the wrong person is replaced; the old one stops working.
            Action::make('newInvitation')
                ->label(__('Новая ссылка'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Прежняя ссылка перестанет работать. Уже зарегистрированные клиенты останутся в группе.'))
                ->action(function (): void {
                    $this->getRecord()->update(['invite_code' => CustomerGroup::newInviteCode()]);
                    $this->fillForm();
                    Notification::make()->title(__('Ссылка заменена'))->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
