<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\InteractsWithCatalogCleanup;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Schemas\ProductCategoryActions;
use App\Filament\Support\ProductTablePreferences;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\CardTranslationState;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    use InteractsWithCatalogCleanup;

    protected static string $resource = ProductResource::class;

    /** @var list<int>|null products with a text lacking a shop language */
    private ?array $untranslated = null;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('Все')),
            'visible' => Tab::make(__('На сайте'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'published')->where('is_active', true)),
            // Asleep: off the storefront, the card kept for when the same product comes in again.
            'hidden' => Tab::make(__('Сняты с продажи'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $hidden) => $hidden
                    ->where('status', '!=', 'published')->orWhere('is_active', false))),
            'sold_out' => Tab::make(__('Нет в наличии'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('variants',
                    fn (Builder $variant) => $variant->where('quantity', '>', 0))),
            // In a category that has kinds, the product's kind is still to be chosen (decision of 2026-11-25);
            // the tab shows while any wait, and stays for whoever is moving the last of them.
            'awaiting_kind' => Tab::make(__('Вид не выбран'))
                ->badge(fn (): int => $this->awaitingKindCount())->badgeColor('warning')
                ->visible(fn (): bool => $this->activeTab === 'awaiting_kind' || $this->awaitingKindCount() > 0)
                ->modifyQueryUsing(fn (Builder $query) => ProductCategoryActions::awaitingKind($query)),
            // A text a buyer reads still lacks a shop language (decision of 2026-11-27); the bot asks for it
            // by itself, and the tab shows while any wait, as the status page's «Переводы» does.
            'untranslated' => Tab::make(__('Без перевода'))
                ->badge(fn (): int => count($this->untranslatedIds()))->badgeColor('warning')
                ->visible(fn (): bool => $this->activeTab === 'untranslated' || $this->untranslatedIds() !== [])
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey($this->untranslatedIds())),
        ];
    }

    private function awaitingKindCount(): int
    {
        // Counted at each drawing: a move in this same request changes it.
        return ProductCategoryActions::awaitingKind(Product::query())->count();
    }

    /** @return list<int> read once per request: the whole catalog's texts are looked at */
    private function untranslatedIds(): array
    {
        return $this->untranslated ??= app(CardTranslationState::class)->pendingProductIds();
    }

    public function table(Table $table): Table
    {
        // Database preferences survive logout and cannot leak through a shared session.
        return parent::table($table)->persistFiltersInSession(false);
    }

    public function getTableColumnsSessionKey(): string
    {
        return parent::getTableColumnsSessionKey().'.compact-v1';
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        return parent::getHasReorderedTableColumnsSessionKey().'.compact-v1';
    }

    public function bootedInteractsWithTable(): void
    {
        $isMounting = $this->shouldMountInteractsWithTable;

        if ($isMounting) {
            $filters = $this->tableFilters;

            if ($filters === null && ! request()->query->has('filters') && ($user = $this->preferencesUser())) {
                $filters = ProductTablePreferences::read($user);
            }

            $this->tableFilters = ProductTablePreferences::normalize($filters ?? []);
        }

        parent::bootedInteractsWithTable();

        if ($isMounting) {
            $this->saveAppliedFilters();
        }
    }

    protected function handleTableFilterUpdates(): void
    {
        $this->tableFilters = ProductTablePreferences::normalize($this->tableFilters ?? []);
        parent::handleTableFilterUpdates();
        $this->saveAppliedFilters();
    }

    private function saveAppliedFilters(): void
    {
        if ($user = $this->preferencesUser()) {
            ProductTablePreferences::save($user, $this->tableFilters ?? []);
        }
    }

    private function preferencesUser(): ?User
    {
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentPanel();

        return $user instanceof User && $panel && $user->canAccessPanel($panel) ? $user : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->catalogCleanupAction('catalog'),
        ];
    }
}
