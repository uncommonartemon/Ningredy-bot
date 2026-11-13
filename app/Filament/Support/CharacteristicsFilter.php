<?php

namespace App\Filament\Support;

use App\Services\Catalog\CatalogFilterQuery;
use App\Services\Catalog\ProductAttributeFilters;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The product list filtered by characteristics, as the storefront is (decision of 2026-11-12).
 * The characteristics depend on the category: a laptop is filtered by memory and screen, a
 * drive by interface and speed. So the fields appear once a category is chosen in the
 * category filter, each with the values that category's products carry.
 */
final class CharacteristicsFilter
{
    /** The category filter's value, as a field inside this filter reads it. */
    private const string CATEGORY = '../category_id.value';

    public static function make(): Filter
    {
        $filters = fn (): ProductAttributeFilters => app(ProductAttributeFilters::class);

        return Filter::make('characteristics')
            ->columnSpanFull()
            ->schema(fn (): array => [
                Fieldset::make(__('Характеристики'))->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                    Text::make(__('Выберите категорию — здесь появятся фильтры по её характеристикам.'))
                        ->visible(fn (Get $get): bool => $filters()->forCategory($get(self::CATEGORY)) === [])
                        ->columnSpanFull(),
                    ...collect($filters()->labels())->map(fn (string $label, string $key): Select => Select::make($key)
                        ->label(fn (Get $get): string => $filters()->forCategory($get(self::CATEGORY))[$key]['label'] ?? $label)
                        ->multiple()->searchable()
                        ->options(fn (Get $get): array => $filters()->forCategory($get(self::CATEGORY))[$key]['options'] ?? [])
                        ->visible(fn (Get $get): bool => isset($filters()->forCategory($get(self::CATEGORY))[$key])))
                        ->values()->all(),
                ]),
            ])
            ->query(function (Builder $query, array $data): void {
                $chosen = self::chosen($data);
                if ($chosen !== []) {
                    $query->whereHas('variants', fn (Builder $variants) => app(CatalogFilterQuery::class)->attributes($variants, $chosen));
                }
            })
            ->indicateUsing(function (array $data) use ($filters): array {
                $labels = $filters()->labels();

                return collect(self::chosen($data))->map(fn (array $values, string $key): Indicator => Indicator::make(
                    ($labels[$key] ?? $key).': '.implode(', ', array_map(fn (string $value): string => $filters()->optionName($key, $value), $values)),
                )->removeField($key))->values()->all();
            });
    }

    /**
     * The values chosen in this filter's fields: key => non-empty list of values.
     *
     * @return array<string, list<string>>
     */
    public static function chosen(mixed $data): array
    {
        $chosen = [];
        foreach (is_array($data) ? $data : [] as $key => $values) {
            if (! is_string($key) || preg_match('/^[a-z0-9_]{1,100}$/', $key) !== 1 || ! is_array($values)) {
                continue;
            }
            $values = array_values(array_unique(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== '' && mb_strlen($value) <= 255)));
            if ($values !== []) {
                $chosen[$key] = $values;
            }
        }

        return $chosen;
    }
}
