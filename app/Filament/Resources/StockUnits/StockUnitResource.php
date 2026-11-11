<?php

namespace App\Filament\Resources\StockUnits;

use App\Filament\Resources\LocalizedResource as Resource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockUnits\Pages\ListStockUnits;
use App\Filament\Support\SupplierSelect;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Services\Catalog\StockUnits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every physical item the shop has or had (operator, 2026-11-11). Units appear and leave with
 * a product's quantity, so there is nothing to create here: this is where the books are
 * filled in and corrected. A unit sold or written off stays here after its product is deleted.
 */
class StockUnitResource extends Resource
{
    protected static ?string $model = StockUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Единицы склада';

    protected static ?string $modelLabel = 'единица склада';

    protected static ?string $pluralModelLabel = 'единицы склада';

    protected static string|UnitEnum|null $navigationGroup = 'Склад и учёт';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    /** How many units in stock still lack a purchase price: what the books wait for. */
    public static function getNavigationBadge(): ?string
    {
        $unpriced = StockUnit::query()->inStock()->whereNull('purchase_price')->count();

        return $unpriced > 0 ? (string) $unpriced : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Без цены закупки');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [StockUnit::IN_STOCK => __('В наличии'), StockUnit::SOLD => __('Продана'), StockUnit::WRITTEN_OFF => __('Списана')];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('serial_number')->label(__('Серийный номер'))->maxLength(255),
            SupplierSelect::make(),
            TextInput::make('purchase_price')->label(__('Цена закупки'))->numeric()->minValue(0),
            DatePicker::make('purchased_at')->label(__('Дата закупки'))->maxDate(now()),
            TextInput::make('sold_price')->label(__('Цена продажи'))->numeric()->minValue(0)
                ->visible(fn (?StockUnit $record): bool => $record?->status === StockUnit::SOLD),
            DateTimePicker::make('sold_at')->label(__('Дата продажи'))->seconds(false)->maxDate(now())
                ->visible(fn (?StockUnit $record): bool => $record?->status === StockUnit::SOLD),
            Textarea::make('note')->label(__('Заметка'))->rows(3)->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('variant'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->label(__('Номер'))->searchable()->sortable()->copyable()->fontFamily(FontFamily::Mono),
                // The unit's own copy of the name: it is still there when the product is deleted.
                TextColumn::make('product_title')->label(__('Товар'))->searchable()->limit(46)->placeholder('—')
                    ->tooltip(fn (StockUnit $record): ?string => $record->product_title)
                    ->description(fn (StockUnit $record): ?string => $record->product_variant_id === null
                        ? __('Товар удалён из каталога') : $record->variant?->sku)
                    ->url(fn (StockUnit $record): ?string => $record->variant?->product_id === null ? null
                        : ProductResource::getUrl('edit', ['record' => $record->variant->product_id])),
                TextColumn::make('serial_number')->label(__('Серийный номер'))->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('status')->label(__('Состояние'))->badge()
                    ->formatStateUsing(fn (string $state): string => self::statuses()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        StockUnit::IN_STOCK => 'success', StockUnit::SOLD => 'info', default => 'gray',
                    })->sortable(),
                TextColumn::make('purchase_price')->label(__('Цена закупки'))->numeric(decimalPlaces: 2)->placeholder('—')->alignEnd()->sortable(),
                TextColumn::make('purchased_at')->label(__('Дата закупки'))->date('d.m.Y')->placeholder('—')->sortable(),
                TextColumn::make('supplier')->label(__('Поставщик'))->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('sold_price')->label(__('Цена продажи'))->numeric(decimalPlaces: 2)->placeholder('—')->alignEnd()->sortable(),
                TextColumn::make('sold_at')->label(__('Дата продажи'))->date('d.m.Y')->placeholder('—')->sortable(),
                TextColumn::make('profit')->label(__('Прибыль'))->alignEnd()->placeholder('—')
                    ->state(fn (StockUnit $record): ?float => $record->sold_price === null || $record->purchase_price === null ? null
                        : (float) $record->sold_price - (float) $record->purchase_price)
                    ->numeric(decimalPlaces: 2)
                    ->color(fn (?float $state): string => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger')),
                TextColumn::make('note')->label(__('Заметка'))->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('product_title')->label(__('Товар'))->collapsible(),
                Group::make('supplier')->label(__('Поставщик'))->collapsible(),
            ])
            ->filters([
                Filter::make('unpriced')->label(__('Без цены закупки'))->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('purchase_price')),
                SelectFilter::make('supplier')->label(__('Поставщик'))
                    ->options(fn (): array => Supplier::options()),
            ])
            ->recordActions([
                EditAction::make()->modalHeading(fn (StockUnit $record): string => __('Единица :code', ['code' => $record->code])),
                ActionGroup::make([
                    Action::make('sell')->label(__('Продана'))->icon(Heroicon::OutlinedBanknotes)
                        ->visible(fn (StockUnit $record): bool => $record->status === StockUnit::IN_STOCK)
                        ->schema([
                            TextInput::make('sold_price')->label(__('Цена продажи'))->numeric()->minValue(0)->required()
                                ->default(fn (StockUnit $record): ?string => $record->variant?->price),
                            DateTimePicker::make('sold_at')->label(__('Дата продажи'))->seconds(false)->maxDate(now())->default(now())->required(),
                        ])
                        ->action(fn (StockUnit $record, array $data) => app(StockUnits::class)->sell($record, (string) $data['sold_price'], $data['sold_at'])),
                    Action::make('writeOff')->label(__('Списать'))->icon(Heroicon::OutlinedArchiveBoxXMark)->color('gray')
                        ->visible(fn (StockUnit $record): bool => $record->status === StockUnit::IN_STOCK)
                        ->requiresConfirmation()
                        ->modalDescription(__('Единица уйдёт из наличия без продажи: потеряна, сломана, пошла на запчасти.'))
                        ->action(fn (StockUnit $record) => app(StockUnits::class)->writeOff($record)),
                    Action::make('restore')->label(__('Вернуть в наличие'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                        // A unit whose product was deleted has no shelf to return to.
                        ->visible(fn (StockUnit $record): bool => $record->status !== StockUnit::IN_STOCK && $record->product_variant_id !== null)
                        ->requiresConfirmation()
                        ->modalDescription(__('Количество товара на сайте вырастет на одну штуку.'))
                        ->action(fn (StockUnit $record) => app(StockUnits::class)->restore($record)),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockUnits::route('/')];
    }
}
