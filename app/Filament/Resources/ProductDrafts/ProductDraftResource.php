<?php

namespace App\Filament\Resources\ProductDrafts;

use App\Filament\Resources\ProductDrafts\Pages\EditProductDraft;
use App\Filament\Resources\ProductDrafts\Pages\ListProductDrafts;
use App\Filament\Resources\ProductDrafts\Pages\ViewProductDraft;
use App\Filament\Resources\ProductDrafts\Schemas\ProductDraftForm;
use App\Filament\Resources\ProductDrafts\Schemas\ProductDraftInfolist;
use App\Filament\Resources\ProductDrafts\Tables\ProductDraftsTable;
use App\Models\ProductDraft;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductDraftResource extends Resource
{
    protected static ?string $model = ProductDraft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Черновики AI';

    protected static ?string $modelLabel = 'черновик';

    protected static ?string $pluralModelLabel = 'черновики AI';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ProductDraftForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductDraftInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductDraftsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductDrafts::route('/'),
            'view' => ViewProductDraft::route('/{record}'),
            'edit' => EditProductDraft::route('/{record}/edit'),
        ];
    }
}
