<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\TitleComposer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->renameAction(), DeleteAction::make()];
    }

    /**
     * The category's products renamed by its title template (decision of 2026-11-13): the
     * operator sees «was → becomes» and ticks what to replace; a title written by hand is
     * never replaced without that.
     */
    private function renameAction(): Action
    {
        return Action::make('renameByTemplate')->label(__('Переименовать товары по шаблону'))->icon('heroicon-o-pencil-square')->color('gray')
            ->visible(fn (): bool => $this->getRecord() instanceof Category && filled($this->getRecord()->standardSource('title')?->title_parts))
            ->modalHeading(__('Переименовать товары категории по шаблону'))
            ->modalDescription(__('Отмечены все, чьё название шаблон меняет. Адрес страницы товара не меняется; переводы названия бот сделает заново.'))
            ->modalSubmitActionLabel(__('Переименовать отмеченные'))
            ->schema(fn (): array => [
                CheckboxList::make('products')->label(__('Было → станет'))->bulkToggleable()->columns(1)
                    ->options(collect(app(TitleComposer::class)->renames($this->getRecord()))
                        ->map(fn (array $rename): string => '«'.$rename['from'].'» → «'.$rename['to'].'»')->all()),
            ])
            ->fillForm(fn (): array => ['products' => array_keys(app(TitleComposer::class)->renames($this->getRecord()))])
            ->action(function (array $data): void {
                $composer = app(TitleComposer::class);
                $renames = $composer->renames($this->getRecord());
                $done = 0;
                foreach ((array) ($data['products'] ?? []) as $id) {
                    $product = Product::query()->find((int) $id);
                    if ($product !== null && isset($renames[$product->id])) {
                        $composer->retitle($product, $renames[$product->id]['to']);
                        $done++;
                    }
                }
                Notification::make()->success()->title(__('Переименовано товаров: :count.', ['count' => $done]))->send();
            });
    }
}
