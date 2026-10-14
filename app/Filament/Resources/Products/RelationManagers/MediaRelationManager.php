<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Resources\LocalizedRelationManager as RelationManager;
use App\Filament\Schemas\ProductPhotoUpload;
use App\Models\ProductMedia;
use App\Services\Products\ProductMediaFormData;
use App\Services\Products\ProductMediaUploader;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Фотографии';

    protected static ?string $modelLabel = 'фотография';

    protected static ?string $pluralModelLabel = 'фотографии';

    protected function getListeners(): array
    {
        return [
            ...parent::getListeners(),
            'product-photos-updated.'.$this->getOwnerRecord()->getKey() => '$refresh',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Изображение'))
                ->description(__('Загрузите файл на сервер или укажите внешний URL. Для каталога предпочтителен локальный файл.'))
                ->schema([
                    $this->variantField(),
                    Hidden::make('type')->default('image'),
                    Hidden::make('disk')->default('public'),
                    ProductPhotoUpload::make('path')
                        ->live()
                        ->label(__('Файл на сервере'))
                        ->directory('products/uploads')
                        ->previewable()
                        ->openable()
                        ->downloadable()
                        ->required(fn (Get $get): bool => blank($get('url')))
                        ->columnSpanFull(),
                    TextInput::make('url')
                        ->label(__('Внешний URL'))
                        ->url()
                        ->afterStateHydrated(function (Get $get, Set $set): void {
                            if (filled($get('path'))) {
                                $set('url', null);
                            }
                        })
                        ->disabled(fn (Get $get): bool => filled($get('path')))
                        ->helperText(__('Используйте только если файл не сохранён на сервере.'))
                        ->required(fn (Get $get): bool => blank($get('path')))
                        ->columnSpanFull(),
                    TextInput::make('alt')
                        ->label(__('Alt / описание фото'))
                        ->maxLength(500)
                        ->columnSpanFull(),
                    TextInput::make('source_url')
                        ->label(__('Страница-источник'))
                        ->url()
                        ->columnSpanFull(),
                ]),

            Section::make(__('Публикация и проверка'))
                ->schema([
                    Select::make('role')
                        ->label(__('Назначение'))
                        ->options([
                            'primary' => __('Главное'),
                            'secondary' => __('Дополнительное'),
                            'detail' => __('Деталь'),
                        ])
                        ->default('secondary'),
                    Toggle::make('is_primary')
                        ->label(__('Главное фото')),
                    TextInput::make('sort_order')
                        ->label(__('Порядок'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    Select::make('verification_status')
                        ->label(__('Проверка'))
                        ->options([
                            'verified' => __('Проверено Vision'),
                            'source_verified' => __('Подтверждено источником'),
                            'manual' => __('Подтверждено вручную'),
                            'unverified' => __('Не проверено'),
                            'rejected' => __('Отклонено'),
                        ])
                        ->default('manual')
                        ->required(),
                    TextInput::make('verification_score')
                        ->label(__('Оценка Vision'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1),
                    TextInput::make('verification_model')
                        ->label(__('Модель проверки')),
                    DateTimePicker::make('verified_at')
                        ->label(__('Проверено в')),
                    Textarea::make('verification_notes')
                        ->label(__('Комментарий проверки'))
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
                    ->label(__('Фото'))
                    ->state(fn (ProductMedia $record): ?string => $record->path ?: $record->url)
                    ->disk(fn (ProductMedia $record): string => $record->disk ?: 'public')
                    ->square()
                    ->imageSize(84)
                    ->url(fn (?string $state): ?string => filled($state)
                        ? (filter_var($state, FILTER_VALIDATE_URL) ? $state : Storage::disk('public')->url($state))
                        : null, true)
                    ->placeholder(__('Нет файла')),
                TextColumn::make('alt')
                    ->label(__('Описание'))
                    ->placeholder(__('Без описания'))
                    ->wrap()
                    ->limit(60)
                    ->searchable(),
                TextColumn::make('variant.name')
                    ->label(__('Конфигурация'))
                    ->state(fn (ProductMedia $record): ?string => $record->product_variant_id
                        ? ($record->variant?->name ?: '#'.$record->product_variant_id) : null)
                    ->placeholder(__('catalog-quick-edit.shared_photos')),
                TextColumn::make('role')
                    ->label(__('Назначение'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'primary' => __('Главное'),
                        'detail' => __('Деталь'),
                        default => __('Дополнительное'),
                    }),
                IconColumn::make('is_primary')
                    ->label(__('Главное'))
                    ->boolean(),
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
                    ->state(fn (ProductMedia $record): string => ($record->width && $record->height)
                        ? "{$record->width}×{$record->height}"
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mime_type')
                    ->label(__('Формат'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort_order')
                    ->label(__('Порядок'))
                    ->sortable(),
            ])
            ->headerActions([
                $this->uploadPhotosAction(),
                CreateAction::make()->label(__('Добавить фото по ссылке'))
                    ->mutateDataUsing(fn (array $data): array => $this->prepareMediaData($data)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->mutateDataUsing(fn (array $data, ProductMedia $record): array => $this->prepareMediaData($data, $record)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('Фотографий пока нет'))
            ->emptyStateDescription(__('Добавьте локальный файл или внешний URL.'))
            ->emptyStateActions([
                $this->uploadPhotosAction(),
            ]);
    }

    private function uploadPhotosAction(): Action
    {
        return Action::make('uploadPhotos')
            ->label(__('Загрузить фотографии'))
            ->icon('heroicon-o-arrow-up-tray')
            ->schema([
                $this->variantField()->default(fn () => $this->getOwnerRecord()->defaultVariant()->value('id')),
                ProductPhotoUpload::multiple()->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(ProductMediaUploader::class)->upload($this->getOwnerRecord(), $data['new_photos'],
                        ($data['product_variant_id'] ?? null) ? (int) $data['product_variant_id'] : null, useDefaultVariant: false);
                } catch (ValidationException $exception) {
                    $this->throwActionValidation($exception);
                }
            })
            ->successNotificationTitle(__('Фотографии добавлены'));
    }

    private function prepareMediaData(array $data, ?ProductMedia $record = null): array
    {
        try {
            return app(ProductMediaFormData::class)->prepare($this->getOwnerRecord(), $data, $record);
        } catch (ValidationException $exception) {
            $this->throwActionValidation($exception);
        }
    }

    private function throwActionValidation(ValidationException $exception): never
    {
        $statePath = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        throw ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $field): array => [$statePath.'.'.$field => $messages])->all());
    }

    private function variantField(): Select
    {
        return Select::make('product_variant_id')->label(__('Конфигурация'))
            ->placeholder(__('catalog-quick-edit.shared_photos'))->searchable()->nullable()
            ->options(fn (): array => $this->getOwnerRecord()->variants()->orderByDesc('is_default')->orderBy('id')->get()
                ->mapWithKeys(fn ($variant): array => [$variant->id => implode(' · ', array_filter([
                    '#'.$variant->id, $variant->name ?: $variant->sku, $variant->color,
                ]))])->all())
            ->rules([Rule::exists('product_variants', 'id')->where('product_id', $this->getOwnerRecord()->id)])
            ->columnSpanFull();
    }
}
