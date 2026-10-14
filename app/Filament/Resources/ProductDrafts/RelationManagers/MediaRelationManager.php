<?php

namespace App\Filament\Resources\ProductDrafts\RelationManagers;

use App\Filament\Resources\LocalizedRelationManager as RelationManager;
use App\Models\ProductDraftMedia;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
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
            ImageEntry::make('path')->label(__('Фото'))->disk('public')->columnSpanFull(),
            TextEntry::make('source_url')->label(__('Источник'))->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—'),
            TextEntry::make('role')->label(__('Назначение'))->badge(),
            TextEntry::make('verification_status')->label(__('Проверка'))->badge(),
            TextEntry::make('verification_notes')->label(__('Комментарий проверки'))->placeholder('—')->columnSpanFull(),
            TextEntry::make('dimensions')->label(__('Размер'))
                ->state(fn (ProductDraftMedia $record): string => ($record->width && $record->height) ? "{$record->width}×{$record->height}" : '—'),
            TextEntry::make('file_size')->label(__('Вес, КБ'))->state(fn (ProductDraftMedia $record): ?string => $record->file_size ? number_format($record->file_size / 1024, 0) : null)->placeholder('—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('path')
                    ->label(__('Фото'))
                    ->disk('public')
                    ->square()
                    ->imageSize(84),
                TextColumn::make('role')
                    ->label(__('Назначение'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'primary' => __('Главное'),
                        'gallery' => __('Галерея'),
                        default => __('Деталь'),
                    }),
                IconColumn::make('is_primary')->label(__('Главное'))->boolean(),
                TextColumn::make('verification_status')
                    ->label(__('Проверка'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'verified' => 'Vision',
                        'source_verified' => __('Подтверждено источником'),
                        'manual' => __('Вручную'),
                        'rejected' => __('Отклонено'),
                        'hint_override' => __('Нарушает подсказку'),
                        default => __('Не проверено'),
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
                    ->label(__('Размер'))
                    ->state(fn (ProductDraftMedia $record): string => ($record->width && $record->height)
                        ? "{$record->width}×{$record->height}"
                        : '—'),
                TextColumn::make('source_url')
                    ->label(__('Источник'))
                    ->limit(40)
                    ->placeholder('—')
                    ->url(fn (?string $state): ?string => $state, true)
                    ->toggleable(),
                TextColumn::make('sort_order')->label(__('Порядок'))->sortable(),
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
            ->emptyStateHeading(__('Фото ещё не найдены'))
            ->emptyStateDescription(__('Галерея заполняется автоматически поиском фото.'));
    }
}
