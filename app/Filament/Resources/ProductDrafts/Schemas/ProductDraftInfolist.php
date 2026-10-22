<?php

namespace App\Filament\Resources\ProductDrafts\Schemas;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductSourceAttempts\ProductSourceAttemptResource;
use App\Models\ProductDraft;
use App\Services\Catalog\AttributePresentation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Collection;

class ProductDraftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->dense()
            ->components([
                Section::make(__('Товар'))
                    ->schema([
                        TextEntry::make('title')->label(__('Название')),
                        TextEntry::make('brand')->placeholder('—'),
                        TextEntry::make('model')->placeholder('—'),
                        TextEntry::make('color')->placeholder('—'),
                        TextEntry::make('product_type')->label(__('Тип товара'))->placeholder('—'),
                        TextEntry::make('category')->label(__('Категория'))->placeholder('—'),
                        TextEntry::make('confidence')->label(__('Уверенность AI'))->numeric(2),
                        TextEntry::make('status')
                            ->label(__('Статус'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'approved' => __('Подтверждён'),
                                'rejected' => __('Отклонён'),
                                default => __('Ожидает проверки'),
                            }),
                        TextEntry::make('product.id')->label(__('Опубликованный товар'))->placeholder('—')
                            ->url(fn (ProductDraft $record): ?string => $record->approved_product_id
                                ? ProductResource::getUrl('view', ['record' => $record->approved_product_id])
                                : null),
                        TextEntry::make('description')->label(__('Описание'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('research_notes')->label(__('Внутренние заметки исследования'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make(__('Состояние поиска'))
                    ->schema([
                        TextEntry::make('gallery_status')
                            ->label(__('Статус галереи'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'complete' => __('Полная'),
                                'partial' => __('Частичная'),
                                'missing' => __('Не найдена'),
                                default => __('Ожидание'),
                            }),
                        TextEntry::make('gallery_search_stop_reason')
                            ->label(__('Причина остановки'))
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'cost_budget' => __('Денежный лимит'),
                                'stalled' => __('Остановлен досрочно'),
                                'time_budget' => __('Временной лимит'),
                                'exhausted' => __('Источники исчерпаны'),
                                'specifications_unreconciled' => __('Характеристики не сверены'),
                                default => '—',
                            })
                            ->placeholder('—'),
                        TextEntry::make('images_staged_at')->label(__('Фото сохранены'))->dateTime('d.m.Y H:i:s')->placeholder('—'),
                        TextEntry::make('gallery_notes')->label(__('Заметка'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                Section::make(__('Источники'))
                    ->schema([
                        TextEntry::make('primary_source_url')->label(__('Основной источник'))->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—')->columnSpanFull(),
                        TextEntry::make('official_source_url')->label(__('Официальный источник'))->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—')->columnSpanFull(),
                        RepeatableEntry::make('sources')
                            ->label(__('Все источники'))
                            ->schema([
                                TextEntry::make('title')->label(__('Название')),
                                TextEntry::make('url')->label('URL')->url(fn ($state): ?string => $state)->openUrlInNewTab(),
                                TextEntry::make('type')->label(__('Тип'))->badge(),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Характеристики'))
                    ->schema([
                        RepeatableEntry::make('attribute_groups')
                            ->label(null)
                            ->state(fn (ProductDraft $record): array => AttributePresentation::specifications($record->specifications ?? [])
                                ->groupBy(fn (array $specification): string => (string) ($specification['group']['id'] ?? 'ungrouped'))
                                ->map(fn (Collection $specifications): array => [
                                    'name' => $specifications->first()['group']['translations'][app()->getLocale()]
                                        ?? $specifications->first()['group']['name'] ?? __('catalog-groups.ungrouped'),
                                    'specifications' => $specifications->values()->all(),
                                ])->values()->all())
                            ->schema([
                                TextEntry::make('name')->hiddenLabel()->weight(FontWeight::SemiBold),
                                RepeatableEntry::make('specifications')->hiddenLabel()->schema([
                                    TextEntry::make('name')->label(__('Название')),
                                    TextEntry::make('value')->label(__('Значение')),
                                ])->columns(2)->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make(__('Журнал действий'))
                    ->description(__('Полный журнал попыток источников — Web Search, HTML, Playwright, Vision.'))
                    ->schema([
                        TextEntry::make('attempts_link')
                            ->label(null)
                            ->state(__('Открыть попытки источников этого черновика →'))
                            ->url(fn (ProductDraft $record): string => ProductSourceAttemptResource::getUrl('index', [
                                'tableFilters' => ['product_draft_id' => ['value' => $record->id]],
                            ])),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make(__('Проверка'))
                    ->schema([
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('reviewer.name')->label(__('Администратор'))->placeholder('—'),
                        TextEntry::make('reviewed_by_telegram_user_id')->label('Telegram reviewer')->placeholder('—'),
                        TextEntry::make('rejection_reason')->label(__('Причина отклонения'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('created_at')->dateTime()->placeholder('—'),
                        TextEntry::make('updated_at')->dateTime()->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}
