<?php

namespace App\Filament\Resources\ProductGalleryRecipes\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductGalleryRecipeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $json = fn (mixed $state): string => $state === null || $state === []
            ? '—'
            : (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—');

        return $schema->components([
            Section::make('Сайт и состояние')
                ->schema([
                    TextEntry::make('domain')->label('Домен')->copyable(),
                    TextEntry::make('path_pattern')->label('Шаблон пути'),
                    TextEntry::make('status')
                        ->label('Состояние')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'active' => 'Активен',
                            'learning' => 'Обучается',
                            'disabled' => 'Отключён',
                            default => $state,
                        })
                        ->color(fn (string $state): string => match ($state) {
                            'active' => 'success',
                            'learning' => 'warning',
                            'disabled' => 'gray',
                            default => 'gray',
                        }),
                    IconEntry::make('source_blocked')->label('Источник заблокирован')->boolean(),
                    TextEntry::make('source_block_reason')->label('Причина блокировки')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(4)
                ->columnSpanFull(),
            Section::make('Диагностика')
                ->description('Устойчивые признаки шаблона страницы, использованные для повторного применения и переобучения рецепта.')
                ->schema([
                    TextEntry::make('region')->label('Регион')->placeholder('—'),
                    TextEntry::make('sample_path')->label('Пример пути')->placeholder('—')->copyable(),
                    TextEntry::make('layout_fingerprint')->label('Layout fingerprint')->placeholder('—')->copyable()->fontFamily('mono'),
                    TextEntry::make('last_observed_layout_fingerprint')->label('Последний наблюдённый fingerprint')->placeholder('—')->copyable()->fontFamily('mono'),
                    TextEntry::make('last_failure_kind')->label('Тип последнего сбоя')->placeholder('—')->badge(),
                    TextEntry::make('retry_after')->label('Повтор не раньше')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                    TextEntry::make('consecutive_hard_blocks')->label('Подряд жёстких блокировок')->placeholder('0'),
                    TextEntry::make('hard_block_urls')->label('URL с жёсткой блокировкой')->formatStateUsing($json)->columnSpanFull(),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),
            Section::make('Статистика запусков')
                ->schema([
                    TextEntry::make('success_count')->label('Успехов'),
                    TextEntry::make('failure_count')->label('Ошибок'),
                    TextEntry::make('last_success_at')->label('Последний успех')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                    TextEntry::make('last_failure_at')->label('Последняя ошибка')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                    TextEntry::make('last_error')->label('Текст последней ошибки')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(4)
                ->columnSpanFull(),
            Section::make('AI-рецепт')
                ->description('Разрешены только CSS-селекторы, атрибуты изображений и жёстко ограниченные клики. Ручное изменение — через отдельное опасное действие в шапке страницы.')
                ->schema([
                    TextEntry::make('recipe.reason')->label('Объяснение AI')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('recipe.confidence')->label('Уверенность AI')->placeholder('—'),
                    TextEntry::make('recipe.gallery_present')->label('Галерея подтверждена')->placeholder('—'),
                    TextEntry::make('recipe.expected_image_count')->label('Ожидается фото')->placeholder('—'),
                    TextEntry::make('recipe')->label('Полный JSON-рецепт')->formatStateUsing($json)->copyable()->columnSpanFull(),
                ])
                ->columns(3)
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }
}
