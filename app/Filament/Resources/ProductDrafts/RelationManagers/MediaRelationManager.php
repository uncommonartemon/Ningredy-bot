<?php

namespace App\Filament\Resources\ProductDrafts\RelationManagers;

use App\Models\ProductDraftMedia;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Галерея';

    protected static ?string $modelLabel = 'фотография';

    protected static ?string $pluralModelLabel = 'фотографии';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ImageEntry::make('path')->label('Фото')->disk('public')->columnSpanFull(),
            TextEntry::make('source_url')->label('Источник')->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—'),
            TextEntry::make('role')->label('Назначение')->badge(),
            TextEntry::make('verification_status')->label('Проверка')->badge(),
            TextEntry::make('verification_notes')->label('Комментарий проверки')->placeholder('—')->columnSpanFull(),
            TextEntry::make('dimensions')->label('Размер')
                ->state(fn (ProductDraftMedia $record): string => ($record->width && $record->height) ? "{$record->width}×{$record->height}" : '—'),
            TextEntry::make('file_size')->label('Вес, КБ')->state(fn (ProductDraftMedia $record): ?string => $record->file_size ? number_format($record->file_size / 1024, 0) : null)->placeholder('—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('path')
                    ->label('Фото')
                    ->disk('public')
                    ->square()
                    ->imageSize(84),
                TextColumn::make('role')
                    ->label('Назначение')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'primary' => 'Главное',
                        'gallery' => 'Галерея',
                        default => 'Деталь',
                    }),
                IconColumn::make('is_primary')->label('Главное')->boolean(),
                TextColumn::make('verification_status')
                    ->label('Проверка')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'verified' => 'Vision',
                        'source_verified' => 'Подтверждено источником',
                        'manual' => 'Вручную',
                        'rejected' => 'Отклонено',
                        'hint_override' => 'Нарушает подсказку',
                        default => 'Не проверено',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'verified' => 'success',
                        'source_verified' => 'success',
                        'manual' => 'info',
                        'rejected' => 'danger',
                        'hint_override' => 'warning',
                        default => 'warning',
                    }),
                TextColumn::make('dimensions')
                    ->label('Размер')
                    ->state(fn (ProductDraftMedia $record): string => ($record->width && $record->height)
                        ? "{$record->width}×{$record->height}"
                        : '—'),
                TextColumn::make('source_url')
                    ->label('Источник')
                    ->limit(40)
                    ->placeholder('—')
                    ->url(fn (?string $state): ?string => $state, true)
                    ->toggleable(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Фото ещё не найдены')
            ->emptyStateDescription('Галерея заполняется автоматически поиском фото.');
    }
}
