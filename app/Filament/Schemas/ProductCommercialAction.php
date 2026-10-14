<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\ProductOptions;
use App\Models\Product;
use App\Services\Products\ProductCommercialEditor;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

final class ProductCommercialAction
{
    public static function make(): Action
    {
        return Action::make('editCommercial')
            ->label(__('catalog-quick-edit.action'))
            ->icon('heroicon-o-currency-dollar')
            ->authorize(fn (Product $record): bool => ProductResource::canEdit($record))
            ->disabled(fn (Product $record): bool => ! $record->variants()->exists())
            ->modalHeading(__('catalog-quick-edit.action'))
            ->modalDescription(fn (Product $record): string => $record->title)
            ->modalSubmitActionLabel(__('Сохранить'))
            ->fillForm(fn (Product $record): array => app(ProductCommercialEditor::class)->values($record))
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
            ])
            ->action(function (Product $record, array $data, Action $action, $livewire): void {
                try {
                    app(ProductCommercialEditor::class)->save($record, $data);
                } catch (ValidationException $exception) {
                    $statePath = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => [$statePath.'.'.$field => $messages])->all());
                }
                $action->success();
            })
            ->successNotificationTitle(__('catalog-quick-edit.saved'));
    }
}
