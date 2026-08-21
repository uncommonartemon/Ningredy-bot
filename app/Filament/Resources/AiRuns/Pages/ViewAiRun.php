<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewAiRun extends ViewRecord
{
    protected static string $resource = AiRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openTelegramUpdate')
                ->label('Открыть Telegram update')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->telegram_update_id))
                ->url(fn (): string => TelegramUpdateResource::getUrl('view', ['record' => $this->record->telegram_update_id])),
        ];
    }
}
