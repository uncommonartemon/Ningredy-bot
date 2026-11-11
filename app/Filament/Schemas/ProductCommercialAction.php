<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\ProductOptions;
use App\Models\Product;
use App\Models\StockUnit;
use App\Services\Catalog\StockUnits;
use App\Services\Products\ProductCommercialEditor;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

final class ProductCommercialAction
{
    public static function make(): Action
    {
        return self::form(Action::make('editCommercial')
            ->label(__('catalog-quick-edit.action'))
            ->icon('heroicon-o-currency-dollar')
            ->modalHeading(__('catalog-quick-edit.action'))
            ->modalSubmitActionLabel(__('Сохранить'))
            ->successNotificationTitle(__('catalog-quick-edit.saved')));
    }

    /**
     * The same product came in again (operator, 2026-11-11: «повторы будут однозначно»): its
     * sleeping card goes back on sale together with how many items there are now and what
     * they cost, in one step.
     */
    public static function putBackOnSale(): Action
    {
        return self::form(Action::make('putBackOnSale')
            ->label(__('Вернуть в продажу'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (Product $record): bool => ! $record->isOnSale())
            ->modalHeading(__('Вернуть в продажу'))
            ->modalSubmitActionLabel(__('Вернуть в продажу'))
            ->successNotificationTitle(__('Товар снова в продаже')), putsBack: true);
    }

    private static function form(Action $action, bool $putsBack = false): Action
    {
        return $action
            ->authorize(fn (Product $record): bool => ProductResource::canEdit($record))
            ->disabled(fn (Product $record): bool => ! $record->variants()->exists())
            ->modalDescription(fn (Product $record): string => $record->title)
            ->fillForm(function (Product $record) use ($putsBack): array {
                $values = app(ProductCommercialEditor::class)->values($record);

                // What came in again is at least one item, and it is in stock.
                return $putsBack && $values['variant_id'] !== null
                    ? [...$values, 'quantity' => max(1, (int) $values['quantity']), 'stock_status' => 'in_stock']
                    : $values;
            })
            ->schema([
                Select::make('variant_id')->label(__('Конфигурация'))
                    ->options(fn (Product $record): array => $record->variants()->orderByDesc('is_default')->orderBy('id')->get()
                        ->mapWithKeys(fn ($variant): array => [$variant->id => implode(' · ', array_filter([
                            '#'.$variant->id, $variant->name ?: $variant->sku, $variant->color,
                        ]))])->all())
                    ->required()->live()->columnSpanFull()
                    ->afterStateUpdated(function (Set $set, Product $record, $state): void {
                        foreach (app(ProductCommercialEditor::class)->values($record, $state ? (int) $state : null) as $field => $value) {
                            $set($field, $value);
                        }
                    }),
                Hidden::make('commercial_revision')->required(),
                TextInput::make('price')->label(__('Цена'))->numeric()->minValue(0),
                TextInput::make('currency')->label(__('Валюта'))->required()->length(3)->alpha(),
                Select::make('stock_status')->label(__('Наличие'))->options(ProductOptions::stockStatuses())->required(),
                TextInput::make('quantity')->label(__('Количество'))->integer()->minValue(0)->required(),
                TextInput::make('warranty_months')->label(__('Гарантия, месяцев'))->integer()->minValue(0),
                // For the books: what is written here goes into the rows of the items this change adds.
                Fieldset::make(__('Закупка новых штук'))->columns(3)->columnSpanFull()->schema([
                    TextInput::make('purchase_price')->label(__('Цена закупки'))->numeric()->minValue(0),
                    DatePicker::make('purchased_at')->label(__('Дата закупки'))->maxDate(now()),
                    TextInput::make('supplier')->label(__('Поставщик'))->maxLength(255),
                ]),
            ])
            ->action(function (Product $record, array $data, Action $action, $livewire) use ($putsBack): void {
                $known = StockUnit::query()->where('product_variant_id', $data['variant_id'] ?? null)->pluck('id')->all();
                try {
                    $variant = app(ProductCommercialEditor::class)->save($record, $data);
                } catch (ValidationException $exception) {
                    $statePath = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => [$statePath.'.'.$field => $messages])->all());
                }
                app(StockUnits::class)->describeArrivals($variant, $known, $data);
                if ($putsBack) {
                    $record->putBackOnSale();
                }
                $action->success();
            });
    }
}
