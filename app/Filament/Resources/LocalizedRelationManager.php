<?php

namespace App\Filament\Resources;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

abstract class LocalizedRelationManager extends RelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(parent::getTitle($ownerRecord, $pageClass));
    }

    protected static function getModelLabel(): ?string
    {
        $label = parent::getModelLabel();

        return $label === null ? null : __($label);
    }

    protected static function getPluralModelLabel(): ?string
    {
        $label = parent::getPluralModelLabel();

        return $label === null ? null : __($label);
    }
}
