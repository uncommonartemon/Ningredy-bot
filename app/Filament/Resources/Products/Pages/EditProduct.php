<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Concerns\GuardsCatalogEditRevision;
use App\Filament\Resources\Products\Pages\Concerns\HandlesProductPhotoUploads;
use App\Filament\Resources\Products\Pages\Concerns\InteractsWithProductFormAssistant;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Schemas\ProductSaleActions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditProduct extends EditRecord
{
    use GuardsCatalogEditRevision {
        beforeValidate as private guardProductAiEditRevision;
    }
    use HandlesProductPhotoUploads {
        afterSave as private saveProductPhotosAfterSave;
    }
    use InteractsWithProductFormAssistant;

    protected static string $resource = ProductResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public static bool $formActionsAreSticky = false;

    protected function beforeValidate(): void
    {
        $this->guardProductAiEditRevision();
        $this->materializeProductAiProposals();
    }

    protected function afterSave(): void
    {
        $this->saveProductPhotosAfterSave();
        $this->rememberCatalogRevision();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openCatalog')->label(__('Открыть на сайте'))->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => route('products.show', $this->record), true)
                ->visible(fn (): bool => $this->record->status === 'published' && $this->record->is_active),
            ViewAction::make(),
            $this->productAiAssistantAction(),
            $this->getSaveFormAction()->formId('form')->keyBindings([]),
            // Two different buttons (operator, 2026-11-11): the card goes to sleep and comes back, or is deleted for good.
            ProductSaleActions::takeOff()->after(fn () => $this->saleChanged()),
            Action::make('putBackOnSale')->label(__('Вернуть в продажу'))->icon(Heroicon::OutlinedEye)->color('gray')
                ->visible(fn (): bool => ! $this->record->isOnSale())
                ->action(function (Action $action): void {
                    $this->record->putBackOnSale();
                    $this->saleChanged();
                    $action->success();
                })
                ->successNotificationTitle(__('Товар снова в продаже')),
            ProductSaleActions::delete(DeleteAction::make()),
        ];
    }

    /** The button wrote past the open form: the form shows the new state and saving stays allowed. */
    private function saleChanged(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['is_active', 'status']);
        $this->rememberCatalogRevision();
    }
}
