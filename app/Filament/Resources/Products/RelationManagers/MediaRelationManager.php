<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\ProductMedia;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Фотографии';

    protected static ?string $modelLabel = 'фотография';

    protected static ?string $pluralModelLabel = 'фотографии';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Изображение')
                ->description('Загрузите файл на сервер или укажите внешний URL. Для каталога предпочтителен локальный файл.')
                ->schema([
                    Hidden::make('type')->default('image'),
                    Hidden::make('disk')->default('public'),
                    FileUpload::make('path')
                        ->label('Файл на сервере')
                        ->disk('public')
                        ->directory('products/uploads')
                        ->visibility('public')
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                        ->maxSize(8192)
                        ->imageEditor()
                        ->previewable()
                        ->openable()
                        ->downloadable()
                        ->imagePreviewHeight('240')
                        ->required(fn (Get $get): bool => blank($get('url')))
                        ->columnSpanFull(),
                    TextInput::make('url')
                        ->label('Внешний URL')
                        ->url()
                        ->helperText('Используйте только если файл не сохранён на сервере.')
                        ->required(fn (Get $get): bool => blank($get('path')))
                        ->columnSpanFull(),
                    TextInput::make('alt')
                        ->label('Alt / описание фото')
                        ->maxLength(500)
                        ->columnSpanFull(),
                    TextInput::make('source_url')
                        ->label('Страница-источник')
                        ->url()
                        ->columnSpanFull(),
                ]),

            Section::make('Публикация и проверка')
                ->schema([
                    Select::make('role')
                        ->label('Назначение')
                        ->options([
                            'primary' => 'Главное',
                            'secondary' => 'Дополнительное',
                            'detail' => 'Деталь',
                        ])
                        ->default('secondary'),
                    Toggle::make('is_primary')
                        ->label('Главное фото'),
                    TextInput::make('sort_order')
                        ->label('Порядок')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    Select::make('verification_status')
                        ->label('Проверка')
                        ->options([
                            'verified' => 'Проверено Vision',
                            'manual' => 'Подтверждено вручную',
                            'unverified' => 'Не проверено',
                            'rejected' => 'Отклонено',
                        ])
                        ->default('manual')
                        ->required(),
                    TextInput::make('verification_score')
                        ->label('Оценка Vision')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1),
                    TextInput::make('verification_model')
                        ->label('Модель проверки'),
                    DateTimePicker::make('verified_at')
                        ->label('Проверено в'),
                    Textarea::make('verification_notes')
                        ->label('Комментарий проверки')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alt')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('preview')
                    ->label('Фото')
                    ->state(fn (ProductMedia $record): ?string => $record->path ?: $record->url)
                    ->disk(fn (ProductMedia $record): string => $record->disk ?: 'public')
                    ->square()
                    ->imageSize(84)
                    ->url(fn (?string $state): ?string => filled($state)
                        ? (filter_var($state, FILTER_VALIDATE_URL) ? $state : Storage::disk('public')->url($state))
                        : null, true)
                    ->placeholder('Нет файла'),
                TextColumn::make('alt')
                    ->label('Описание')
                    ->placeholder('Без описания')
                    ->wrap()
                    ->limit(60)
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Назначение')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'primary' => 'Главное',
                        'detail' => 'Деталь',
                        default => 'Дополнительное',
                    }),
                IconColumn::make('is_primary')
                    ->label('Главное')
                    ->boolean(),
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
                    ->state(fn (ProductMedia $record): string => ($record->width && $record->height)
                        ? "{$record->width}×{$record->height}"
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mime_type')
                    ->label('Формат')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Добавить фото'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Фотографий пока нет')
            ->emptyStateDescription('Добавьте локальный файл или внешний URL.')
            ->emptyStateActions([
                CreateAction::make()->label('Добавить фото'),
            ]);
    }
}
