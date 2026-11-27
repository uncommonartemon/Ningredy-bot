<?php

namespace App\Filament\Pages;

use App\Filament\Pages\LocalizedPage as Page;
use App\Models\Product;
use App\Services\Catalog\StorefrontShowcase;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Decision of 2026-11-27: the shop picks the products its start page shows first. The first
 * three are the plates of the first screen; all of them lead the row of new products.
 */
class StorefrontShowcasePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Витрина';

    protected static ?string $title = 'Витрина';

    protected static ?int $navigationSort = 5;

    private const SEARCH_RESULTS = 50;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['products' => array_map('strval', StorefrontShowcase::ids())]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Главная страница сайта'))->schema([
                Select::make('products')->label(__('Товары на главной'))
                    ->multiple()->searchable()->reorderable()->maxItems(StorefrontShowcase::LIMIT)
                    ->getSearchResultsUsing(fn (string $search): array => $this->options($this->found($search)))
                    ->getOptionLabelsUsing(fn (array $values): array => $this->options(
                        Product::query()->whereIn('id', $values)->get()))
                    ->searchPrompt(__('Название, бренд, модель или код'))
                    ->noSearchResultsMessage(__('Таких товаров нет'))
                    ->helperText(__('Первые три — карточки на первом экране сайта; все выбранные идут первыми в ряду новых товаров, в этом порядке. Товар, снятый с продажи или без фото, на сайте пропускается. Если ничего не выбрано, главная сама берёт новые товары с фото.')),
            ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('storefront-showcase-form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('saveShowcase')->label(__('Сохранить витрину'))->icon('heroicon-m-check')->submit('save'),
                ])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $saved = StorefrontShowcase::save((array) ($data['products'] ?? []));
        $this->form->fill(['products' => array_map('strval', $saved)]);

        Notification::make()->title(__('Витрина сохранена'))->success()->send();
    }

    /** @return Collection<int, Product> products whose title, brand, model or a code holds the words */
    private function found(string $search): Collection
    {
        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return Product::query()
            ->where(fn (Builder $match) => $match->where('title', 'like', $term)->orWhere('model', 'like', $term)
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', $term))
                ->orWhereHas('variants', fn (Builder $variant) => $variant
                    ->where(fn (Builder $code) => $code->where('sku', 'like', $term)->orWhere('mpn', 'like', $term)->orWhere('gtin', 'like', $term))))
            ->orderByDesc('is_active')->orderByDesc('published_at')->orderByDesc('id')
            ->limit(self::SEARCH_RESULTS)->get();
    }

    /**
     * One line per product: what it is, its price and whether the site can show it.
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, string>
     */
    private function options(Collection $products): array
    {
        $products->loadMissing(['brand:id,name', 'variants' => fn ($query) => $query->available()->orderByDesc('is_default')->orderBy('id')])
            ->loadCount(['media as photos_count' => fn (Builder $media) => $media->where('type', 'image')
                ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])]);

        return $products->mapWithKeys(function (Product $product): array {
            $variant = $product->variants->whereNotNull('price')->first();

            return [(string) $product->id => implode(' · ', array_filter([
                '#'.$product->id.' '.$product->title,
                $product->brand?->name,
                $variant ? number_format((float) $variant->price, 0, ',', ' ').' '.($variant->currency ?: 'CZK') : __('без цены'),
                $product->isOnSale() ? __('в продаже') : __('снят с продажи'),
                $product->photos_count > 0 ? __('с фото') : __('без фото'),
            ]))];
        })->all();
    }
}
