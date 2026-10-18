<?php

namespace App\Filament\Schemas;

use App\Services\Admin\DatabaseValueRedactor;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class FailureDiagnosisInfolist
{
    public static function section(string $path): Section
    {
        $text = fn (mixed $state): string => app(DatabaseValueRedactor::class)->text($state);

        return Section::make(__('Диагностика отказа'))
            ->description(__('Слова агента и проверяемая причина сервера показаны отдельно. Пустой комментарий не заменяется догадкой.'))
            ->visible(fn ($record): bool => is_array(data_get($record, $path)) || in_array($record->status, ['failed', 'blocked', 'interrupted'], true))
            ->schema([
                TextEntry::make($path.'.version')->label(__('Формат'))->badge()
                    ->placeholder(__('Старая запись: структурированная диагностика не сохранена')),
                TextEntry::make($path.'.stage')->label(__('Этап'))->placeholder('—'),
                TextEntry::make($path.'.failure_kind')->label(__('Тип отказа'))->placeholder('—'),
                TextEntry::make($path.'.server_reason')->label(__('Причина сервера'))->formatStateUsing($text)->placeholder('—')->columnSpanFull(),
                TextEntry::make($path.'.agent_comment')->label(__('Комментарий агента'))->formatStateUsing($text)
                    ->placeholder(__('Комментарий агента не сохранён'))->columnSpanFull(),
                TextEntry::make($path.'.blocked_by')->label(__('Что остановило действие'))->formatStateUsing($text)->placeholder('—')->columnSpanFull(),
                TextEntry::make($path.'.suggested_next_action')->label(__('Предложенное следующее действие'))->formatStateUsing($text)->placeholder('—')->columnSpanFull(),
                TextEntry::make($path.'.retained')->label(__('Что сохранено'))->formatStateUsing($text)->placeholder('—')->columnSpanFull(),
            ])->columns(3)->columnSpanFull();
    }
}
