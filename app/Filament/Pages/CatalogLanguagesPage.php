<?php

namespace App\Filament\Pages;

use App\Filament\Pages\LocalizedPage as Page;
use App\Jobs\TranslateCatalogNames;
use App\Jobs\TranslateProductText;
use App\Services\Catalog\CatalogNames;
use App\Services\Products\CatalogLocales;
use App\Services\Products\CatalogTextTranslator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Operator, 2026-11-01: the shop speaks Czech and Ukrainian; English is the text the
 * bot reads on the web and checks facts against, not a language the shop must show.
 * Which languages a card is translated into, which the shop offers and which the
 * operator reads in Telegram are chosen here.
 */
class CatalogLanguagesPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Языки каталога';

    protected static ?string $title = 'Языки каталога';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'translations' => array_keys(CatalogLocales::contentTranslations()),
            'storefront' => CatalogLocales::storefrontLocales(),
            'telegram_card' => CatalogLocales::telegramCardLocale(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->dense()->components([
            Section::make(__('Языки текста товаров'))->schema([
                CheckboxList::make('translations')->label(__('Переводить карточки на'))
                    ->options(CatalogLocales::TRANSLATION_OPTIONS)->columns(3)->live()
                    ->helperText(__('Бот пишет карточку по-английски — это исходник для сверки с сайтами — и сразу переводит её на отмеченные языки.')),
                CheckboxList::make('storefront')->label(__('Языки витрины'))
                    ->options(CatalogLocales::STOREFRONT_OPTIONS)->columns(3)->required()
                    ->helperText(__('Кнопки языков на сайте. Первый отмеченный — основной: если у товара нет перевода на выбранный язык, показывается он, потом английский.')),
                Select::make('telegram_card')->label(__('Язык карточки в Telegram'))
                    ->options(fn (Get $get): array => [CatalogLocales::CONTENT_SOURCE => __('Английский (исходный текст)'),
                        ...array_intersect_key(CatalogLocales::TRANSLATION_OPTIONS, array_flip((array) $get('translations')))])
                    ->required()
                    ->helperText(__('На каком языке бот показывает описание карточки перед добавлением в каталог.')),
            ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('catalog-languages-form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('saveLanguages')->label(__('Сохранить языки'))->icon('heroicon-m-check')->submit('save'),
                ])]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('translateMissing')
                ->label(__('Перевести недостающее'))
                ->icon(Heroicon::OutlinedLanguage)
                ->requiresConfirmation()
                ->modalDescription(__('Переведёт на отмеченные языки товары каталога, у которых перевода ещё нет. Это платные вызовы AI, меньше цента за товар.'))
                ->action(function (CatalogTextTranslator $translator, CatalogNames $names): void {
                    $ids = $translator->pendingProductIds();
                    foreach ($ids as $id) {
                        TranslateProductText::dispatch($id);
                    }
                    // Names of categories, groups and characteristics: asked again even if an
                    // earlier request left some of them unfilled.
                    $unnamed = count($names->missing());
                    if ($unnamed > 0) {
                        TranslateCatalogNames::dispatch(askAgain: true);
                    }
                    Notification::make()
                        ->title($ids === [] && $unnamed === 0 ? __('Все товары уже переведены') : __('Перевод запущен'))
                        ->body(implode(' ', array_filter([
                            $ids === [] ? null : __('Товаров без перевода: :count. Переводы появятся на сайте по мере работы.', ['count' => count($ids)]),
                            $unnamed === 0 ? null : __('Названий категорий, групп и характеристик без перевода: :count.', ['count' => $unnamed]),
                        ])) ?: null)
                        ->success()
                        ->send();
                }),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        CatalogLocales::save((array) ($data['translations'] ?? []), (array) ($data['storefront'] ?? []), (string) ($data['telegram_card'] ?? ''));
        $this->form->fill([
            'translations' => array_keys(CatalogLocales::contentTranslations()),
            'storefront' => CatalogLocales::storefrontLocales(),
            'telegram_card' => CatalogLocales::telegramCardLocale(),
        ]);

        Notification::make()->title(__('Языки сохранены'))->success()->send();
    }
}
