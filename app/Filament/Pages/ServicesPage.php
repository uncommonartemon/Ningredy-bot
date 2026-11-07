<?php

namespace App\Filament\Pages;

use App\Filament\Pages\LocalizedPage as Page;
use App\Services\ProductSearch\Codes\CodeDatabases;
use App\Services\ProductSearch\Codes\ProductCodeLookup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The outside services the bot looks products up in: each can be switched off and
 * logged in to here, with what it did lately, and what exists for the components
 * they do not cover (operator, 2026-11-06: «страничка с сервисами, вот айскет где
 * можно откл или включить, залогиниться»).
 */
class ServicesPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Сервисы';

    protected static ?string $title = 'Сервисы';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'icecat_enabled' => CodeDatabases::enabled('icecat'),
            'upcitemdb_enabled' => CodeDatabases::enabled('upcitemdb'),
            'icecat_username' => CodeDatabases::icecatUsernameSource() === 'admin' ? CodeDatabases::icecatUsername() : null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->dense()->components([
            Section::make('Icecat')->description(__('Бесплатная база характеристик и фото по коду товара (EAN, артикул). Бот обращается к ней первой.'))->schema([
                Toggle::make('icecat_enabled')->label(__('Включён'))
                    ->helperText(__('Выключен: бот не спрашивает эту базу и не берёт страницы и фото с её сайта, поиск идёт по остальным источникам. Действует на новые поиски.')),
                TextEntry::make('icecat_state')->label(__('Состояние'))->state(fn (): string => $this->icecatState())
                    ->badge()->color(fn (): string => match (true) {
                        ! CodeDatabases::enabled('icecat') => 'gray',
                        CodeDatabases::icecatUsername() === null, CodeDatabases::lastIcecatAnswer() === 'bad_login' => 'danger',
                        default => 'success',
                    }),
                TextEntry::make('icecat_stats')->label(__('За 7 дней'))->state(fn (): string => $this->statsLine('icecat')),
                TextInput::make('icecat_username')->label(__('Логин Open Icecat'))->maxLength(100)
                    ->placeholder(fn (): ?string => CodeDatabases::icecatUsernameSource() === 'env' ? __('задан в .env (ICECAT_USERNAME)') : null)
                    ->helperText(__('Как получить: зарегистрируйтесь бесплатно на icecat.biz/register, выберите Open Icecat, подтвердите почту и впишите сюда логин. Пароль и ключ не нужны. Логин отсюда важнее строки ICECAT_USERNAME в .env; пустое поле — берётся .env.')),
                TextEntry::make('icecat_scope')->label(__('Что есть в бесплатном Icecat'))
                    ->state(__('Ноутбуки и ПК (HP, Lenovo, ASUS, Acer, MSI…), SSD Samsung, Kingston, Seagate, память Kingston и Samsung. Закрыты (только в платном Full Icecat, от ~€375 в месяц): Intel, AMD, WD, Crucial, KIOXIA и некоторые другие.')),
            ]),
            Section::make('UPCitemdb')->description(__('Подсказка по штрихкоду: бренд и модель по EAN/UPC. Пробный доступ без регистрации, до 90 запросов в сутки.'))->schema([
                Toggle::make('upcitemdb_enabled')->label(__('Включён'))
                    ->helperText(__('Выключен: бот не спрашивает эту базу. Действует на новые поиски.')),
                TextEntry::make('upcitemdb_today')->label(__('Сегодня'))->state(fn (): string => __('использовано :used из :limit', [
                    'used' => CodeDatabases::upcitemdbUsedToday(), 'limit' => (int) config('services.upcitemdb.daily_limit', 90),
                ])),
                TextEntry::make('upcitemdb_stats')->label(__('За 7 дней'))->state(fn (): string => $this->statsLine('upcitemdb')),
            ]),
            Section::make(__('Для комплектующих: что есть кроме Icecat'))->description(__('Справка, проверено 2026-11-02. Бот находит эти страницы обычным веб-поиском; API, кроме перечисленных, не подключены.'))
                ->schema(array_map(fn (string $key, array $advice): TextEntry => TextEntry::make('advice_'.$key)
                    ->label(__($advice['title']))->state(__($advice['text'])), array_keys(CodeDatabases::COMPONENT_ADVICE), CodeDatabases::COMPONENT_ADVICE)),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('services-form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('saveServices')->label(__('Сохранить'))->icon('heroicon-m-check')->submit('save'),
                    Action::make('checkIcecatLogin')->label(__('Проверить логин'))->icon('heroicon-m-signal')->color('gray')
                        ->action(fn () => $this->checkIcecatLogin()),
                    Action::make('registerIcecat')->label(__('Регистрация в Icecat'))->icon('heroicon-m-arrow-top-right-on-square')
                        ->color('gray')->url(CodeDatabases::ICECAT_REGISTER_URL, shouldOpenInNewTab: true),
                ])]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        CodeDatabases::saveIcecatUsername($state['icecat_username'] ?? null);
        CodeDatabases::setEnabled('icecat', (bool) ($state['icecat_enabled'] ?? true));
        CodeDatabases::setEnabled('upcitemdb', (bool) ($state['upcitemdb_enabled'] ?? true));

        Notification::make()->title(__('Сохранено'))
            ->body(match (true) {
                ! CodeDatabases::enabled('icecat') => __('Icecat выключен: новые поиски идут без него.'),
                CodeDatabases::icecatUsername() === null => __('Icecat не подключён: логина нет ни здесь, ни в .env.'),
                default => __('Icecat подключён. Новые поиски уже используют его.'),
            })
            ->success()->send();
    }

    /** Asks Icecat about the login in the field, or the one in use: the operator hears at once whether it is right. */
    public function checkIcecatLogin(): void
    {
        $typed = trim((string) ($this->data['icecat_username'] ?? ''));
        $username = $typed !== '' ? $typed : CodeDatabases::icecatUsername();
        if ($username === null) {
            Notification::make()->title(__('Логин не указан'))->body(__('Впишите логин Open Icecat и нажмите ещё раз.'))->warning()->send();

            return;
        }
        $answer = app(ProductCodeLookup::class)->checkIcecatLogin($username);
        match ($answer['status']) {
            'accepted' => Notification::make()->title(__('Логин работает'))->body(__('Icecat принял логин. Не забудьте сохранить, если меняли его.'))->success()->send(),
            'unknown_user' => Notification::make()->title(__('Логин не принят'))->body(__('Icecat не знает такого пользователя. Проверьте написание: нужно имя из профиля на icecat.biz, не почта.'))->danger()->send(),
            default => Notification::make()->title(__('Проверка не удалась'))->body(__('Icecat не ответил, попробуйте ещё раз.').' '.$answer['message'])->warning()->send(),
        };
    }

    private function icecatState(): string
    {
        if (! CodeDatabases::enabled('icecat')) {
            return __('Выключен');
        }
        if (CodeDatabases::icecatUsername() !== null && CodeDatabases::lastIcecatAnswer() === 'bad_login') {
            return __('Логин не принят');
        }

        return match (CodeDatabases::icecatUsernameSource()) {
            'admin' => __('Подключён (логин из админки)'),
            'env' => __('Подключён (логин из .env)'),
            default => __('Не подключён'),
        };
    }

    private function statsLine(string $source): string
    {
        $stats = CodeDatabases::stats()[$source] ?? [];
        if ($stats === []) {
            return __('запросов не было');
        }
        $names = ['found' => __('найдено'), 'not_found' => __('нет в базе'), 'restricted' => __('бренд закрыт'),
            'not_configured' => __('не подключено'), 'quota' => __('лимит'), 'error' => __('ошибка'),
            'disabled' => __('выключено'), 'bad_login' => __('логин не принят')];

        return implode(', ', array_map(fn (string $decision, int $count): string => ($names[$decision] ?? $decision).': '.$count,
            array_keys($stats), $stats));
    }
}
