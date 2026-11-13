<?php

namespace App\Filament\Support;

use App\Models\Supplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * The supplier of a unit, picked from the list or added on the spot (operator's decision, 2026-11-11). One definition for every form that
 * names a supplier: the product's table of units, a unit's own form, the purchase of new items.
 */
final class SupplierSelect
{
    public static function make(): Select
    {
        return Select::make('supplier')->label(__('Поставщик'))
            ->options(fn (): array => Supplier::options())
            ->searchable()
            ->createOptionForm([
                TextInput::make('name')->label(__('Название поставщика'))->required()->maxLength(255),
                Textarea::make('note')->label(__('Заметка'))->rows(2)->maxLength(2000),
            ])
            ->createOptionModalHeading(__('Новый поставщик'))
            ->createOptionUsing(fn (array $data): ?string => Supplier::named($data['name'] ?? null, $data['note'] ?? null));
    }
}
