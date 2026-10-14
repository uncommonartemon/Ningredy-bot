<?php

namespace App\Filament\Resources\Concerns;

use App\Services\Products\CatalogEditRevision;
use Livewire\Attributes\Locked;

trait GuardsCatalogEditRevision
{
    #[Locked]
    public ?string $catalogEditRevision = null;

    protected function fillForm(): void
    {
        // The form and its revision must describe the same database state:
        // a concurrent write during initial hydration must not be blessed by
        // an afterFill fingerprint taken after the newer write.
        $this->getRecord()->getConnection()->transaction(function (): void {
            app(CatalogEditRevision::class)->fingerprint($this->getRecord(), lock: true);
            $this->getRecord()->refresh();
            parent::fillForm();
        });
    }

    protected function afterFill(): void
    {
        $this->rememberCatalogRevision();
    }

    protected function beforeValidate(): void
    {
        app(CatalogEditRevision::class)->assertCurrent($this->getRecord(), $this->catalogEditRevision);
    }

    protected function rememberCatalogRevision(): void
    {
        $this->catalogEditRevision = app(CatalogEditRevision::class)->fingerprint($this->getRecord());
    }
}
