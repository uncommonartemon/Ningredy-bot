<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\InteractsWithCatalogCleanup;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\ProductTablePreferences;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListProducts extends ListRecords
{
    use InteractsWithCatalogCleanup;

    protected static string $resource = ProductResource::class;

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
