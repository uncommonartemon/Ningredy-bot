<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Models\CharacteristicQuestion;
use App\Models\Product;
use App\Services\Catalog\CategoryStandard;
use App\Services\Catalog\FormProposals;
use App\Services\Catalog\TitleComposer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->proposalsAction(), $this->checkProductsAction(), $this->renameAction(), DeleteAction::make()];
    }

    /**
     * The proposals to the form the bot made from the category's products (decision of
     * 2026-11-13): ticked ones join the form, the rest are a «no» and are not proposed again.
     */
    private function proposalsAction(): Action
    {
        $open = fn (): array => $this->getRecord() instanceof Category ? app(FormProposals::class)->openForCategory($this->getRecord()) : [];

        return Action::make('formProposals')->label(fn (): string => __('Бот предлагает добавить (:count)', ['count' => count($open())]))
            ->icon('heroicon-o-light-bulb')->color('gray')
            ->visible(fn (): bool => $open() !== [])
            ->modalHeading(__('Бот предлагает добавить в форму'))
            ->modalDescription(__('Эти характеристики нашлись у товаров категории, а в форме их нет. Отмеченные войдут в форму; без галочки — «нет», больше не предложу.'))
            ->modalSubmitActionLabel(__('Применить'))
            ->schema(fn (): array => [
                CheckboxList::make('add')->label(__('Добавить в форму'))->bulkToggleable()->columns(1)
                    ->options(collect($open())->mapWithKeys(fn (CharacteristicQuestion $question): array => [$question->id => $question->payload['name']
                        .' — '.mb_substr((string) $question->payload['value'], 0, 60).' (#'.$question->payload['product_id'].')'])->all()),
            ])
            ->action(function (array $data) use ($open): void {
                $ticked = array_flip(array_map('intval', (array) ($data['add'] ?? [])));
                $proposals = app(FormProposals::class);
                foreach ($open() as $question) {
                    $proposals->answer($question, isset($ticked[$question->id]), (string) auth()->user()?->name);
                }
                $this->fillForm();
                Notification::make()->success()->title(__('Форма обновлена.'))->send();
            });
    }

    /**
     * The category's products against its form (decision of 2026-11-13): what each lacks and
     * what is written off the standard, and the values brought to the standard - free, no model.
     */
    private function checkProductsAction(): Action
    {
        return Action::make('checkProducts')->label(__('Проверить товары по форме'))->icon('heroicon-o-clipboard-document-check')->color('gray')
            ->visible(fn (): bool => $this->getRecord() instanceof Category && app(CategoryStandard::class)->form($this->getRecord())->isNotEmpty())
            ->modalHeading(__('Товары категории по форме'))
            ->modalDescription(fn (): string => __('Приведение меняет только запись значений: вариант из примера, число с единицей. Недостающее у товара бот доищет по просьбе в чате: «допиши в товар #15 буферизацию».'))
            ->schema(function (): array {
                $standard = app(CategoryStandard::class);
                $lines = array_map(fn (array $row): string => '#'.$row['id'].' '.e(mb_substr($row['title'], 0, 70)).' — '.$row['filled'].' '.__('из').' '.$row['total']
                    .($row['missing'] === [] ? '' : '; '.__('нет').': '.e(implode(', ', $row['missing'])))
                    .($row['inexact'] === [] ? '' : '; '.__('неточно').': '.e(implode(', ', $row['inexact']))), $standard->productReport($this->getRecord()));
                $changes = $standard->standardizeValues($this->getRecord(), false);

                return [
                    Text::make(new HtmlString(implode('<br>', $lines) ?: e(__('В категории нет товаров.')))),
                    Text::make(new HtmlString('<b>'.e(__('Приведётся значений: :count', ['count' => count($changes)])).'</b><br>'
                        .implode('<br>', array_map(fn (array $change): string => '#'.$change['product'].' '.e($change['name']).': «'.e($change['from']).'» → «'.e($change['to']).'»',
                            array_slice($changes, 0, 40))))),
                ];
            })
            ->modalSubmitActionLabel(__('Привести значения к форме'))
            ->action(function (): void {
                $changes = app(CategoryStandard::class)->standardizeValues($this->getRecord(), true);
                Notification::make()->success()->title(__('Приведено значений: :count.', ['count' => count($changes)]))->send();
            });
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
