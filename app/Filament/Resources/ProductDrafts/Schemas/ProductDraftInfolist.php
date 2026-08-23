<?php

namespace App\Filament\Resources\ProductDrafts\Schemas;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductSourceAttempts\ProductSourceAttemptResource;
use App\Models\ProductDraft;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductDraftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Товар')
                    ->schema([
                        TextEntry::make('title')->label('Название'),
                        TextEntry::make('brand')->placeholder('—'),
                        TextEntry::make('model')->placeholder('—'),
                        TextEntry::make('color')->placeholder('—'),
                        TextEntry::make('product_type')->label('Тип товара')->placeholder('—'),
                        TextEntry::make('category')->label('Категория')->placeholder('—'),
                        TextEntry::make('confidence')->label('Уверенность AI')->numeric(2),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'approved' => 'Подтверждён',
                                'rejected' => 'Отклонён',
                                default => 'Ожидает проверки',
                            }),
                        TextEntry::make('product.id')->label('Опубликованный товар')->placeholder('—')
                            ->url(fn (ProductDraft $record): ?string => $record->approved_product_id
                                ? ProductResource::getUrl('view', ['record' => $record->approved_product_id])
                                : null),
                        TextEntry::make('description')->label('Описание')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('research_notes')->label('Внутренние заметки исследования')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make('Состояние поиска')
                    ->schema([
                        TextEntry::make('gallery_status')
                            ->label('Статус галереи')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'complete' => 'Полная',
                                'partial' => 'Частичная',
                                'missing' => 'Не найдена',
                                default => 'Ожидание',
                            }),
                        TextEntry::make('gallery_search_stop_reason')
                            ->label('Причина остановки')
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'cost_budget' => 'Денежный лимит',
                                'time_budget' => 'Временной лимит',
                                'exhausted' => 'Источники исчерпаны',
                                default => '—',
                            })
                            ->placeholder('—'),
                        TextEntry::make('images_staged_at')->label('Фото сохранены')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                        TextEntry::make('gallery_notes')->label('Заметка')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                Section::make('Источники')
                    ->schema([
                        TextEntry::make('primary_source_url')->label('Основной источник')->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—')->columnSpanFull(),
                        TextEntry::make('official_source_url')->label('Официальный источник')->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—')->columnSpanFull(),
                        RepeatableEntry::make('sources')
                            ->label('Все источники')
                            ->schema([
                                TextEntry::make('title')->label('Название'),
                                TextEntry::make('url')->label('URL')->url(fn ($state): ?string => $state)->openUrlInNewTab(),
                                TextEntry::make('type')->label('Тип')->badge(),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Характеристики')
                    ->schema([
                        RepeatableEntry::make('specifications')
                            ->label(null)
                            ->schema([
                                TextEntry::make('name')->label('Название'),
                                TextEntry::make('value')->label('Значение'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make('Журнал действий')
                    ->description('Полный журнал попыток источников — Web Search, HTML, Playwright, Vision.')
                    ->schema([
                        TextEntry::make('attempts_link')
                            ->label(null)
                            ->state('Открыть попытки источников этого черновика →')
                            ->url(fn (ProductDraft $record): string => ProductSourceAttemptResource::getUrl('index', [
                                'tableFilters' => ['product_draft_id' => ['value' => $record->id]],
                            ])),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make('Проверка')
                    ->schema([
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('reviewer.name')->label('Администратор')->placeholder('—'),
                        TextEntry::make('reviewed_by_telegram_user_id')->label('Telegram reviewer')->placeholder('—'),
                        TextEntry::make('rejection_reason')->label('Причина отклонения')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('created_at')->dateTime()->placeholder('—'),
                        TextEntry::make('updated_at')->dateTime()->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}
