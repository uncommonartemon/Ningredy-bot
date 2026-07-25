<?php

namespace App\Filament\Resources\ImageSourcePriorities;

use App\Filament\Resources\ImageSourcePriorities\Pages\CreateImageSourcePriority;
use App\Filament\Resources\ImageSourcePriorities\Pages\EditImageSourcePriority;
use App\Filament\Resources\ImageSourcePriorities\Pages\ListImageSourcePriorities;
use App\Filament\Resources\ImageSourcePriorities\Schemas\ImageSourcePriorityForm;
use App\Filament\Resources\ImageSourcePriorities\Tables\ImageSourcePrioritiesTable;
use App\Models\ImageSourcePriority;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ImageSourcePriorityResource extends Resource
{
    protected static ?string $model = ImageSourcePriority::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Источники фото';

    protected static ?string $modelLabel = 'источник фото';

    protected static ?string $pluralModelLabel = 'источники фото';

    protected static string|UnitEnum|null $navigationGroup = 'AI и автоматизация';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ImageSourcePriorityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImageSourcePrioritiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImageSourcePriorities::route('/'),
            'create' => CreateImageSourcePriority::route('/create'),
            'edit' => EditImageSourcePriority::route('/{record}/edit'),
        ];
    }
}
