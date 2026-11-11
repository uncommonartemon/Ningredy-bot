<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\InteractsWithCatalogCleanup;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\ProductTablePreferences;
use App\Models\User;
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

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('Все')),
            'visible' => Tab::make(__('На сайте'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'published')->where('is_active', true)),
            'hidden' => Tab::make(__('Скрытые'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $hidden) => $hidden
                    ->where('status', '!=', 'published')->orWhere('is_active', false))),
            'sold_out' => Tab::make(__('Нет в наличии'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('variants',
                    fn (Builder $variant) => $variant->where('quantity', '>', 0))),
        ];
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
