<?php

namespace App\Filament\Pages;

use App\Filament\Pages\LocalizedPage as Page;
use App\Services\ProductSearch\Codes\CodeDatabases;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
 * The databases the bot looks products up in by their code, and what exists for the
 * components they do not cover (operator, 2026-11-02: «сделай в админке подсказку к
 * сервисам… есть аналогичные для комплектующих? ссд, проца, видеокарт?»).
 */
class CodeDatabasesPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Базы по кодам';

    protected static ?string $title = 'Базы по кодам';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['icecat_username' => CodeDatabases::icecatUsernameSource() === 'admin' ? CodeDatabases::icecatUsername() : null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->dense()->components([
            Section::make('Icecat')->description(__('Бесплатная база характеристик и фото по коду товара (EAN, артикул). Бот обращается к ней первой.'))->schema([
                TextEntry::make('icecat_state')->label(__('Состояние'))->state(fn (): string => $this->icecatState())
                    ->badge()->color(fn (): string => CodeDatabases::icecatUsername() === null ? 'danger' : 'success'),
                TextEntry::make('icecat_stats')->label(__('За 7 дней'))->state(fn (): string => $this->statsLine('icecat')),
                TextInput::make('icecat_username')->label(__('Логин Open Icecat'))->maxLength(100)
                    ->placeholder(fn (): ?string => CodeDatabases::icecatUsernameSource() === 'env' ? __('задан в .env (ICECAT_USERNAME)') : null)
                    ->helperText(__('Как получить: зарегистрируйтесь бесплатно на icecat.biz/register, выберите Open Icecat, подтвердите почту и впишите сюда логин. Пароль и ключ не нужны. Логин отсюда важнее строки ICECAT_USERNAME в .env; пустое поле — берётся .env.')),
                TextEntry::make('icecat_scope')->label(__('Что есть в бесплатном Icecat'))
                    ->state(__('Ноутбуки и ПК (HP, Lenovo, ASUS, Acer, MSI…), SSD Samsung, Kingston, Seagate, память Kingston и Samsung. Закрыты (только в платном Full Icecat, от ~€375 в месяц): Intel, AMD, WD, Crucial, KIOXIA и некоторые другие.')),
            ]),
            Section::make('UPCitemdb')->description(__('Подсказка по штрихкоду: бренд и модель по EAN/UPC. Пробный доступ без регистрации, до 90 запросов в сутки.'))->schema([
                TextEntry::make('upcitemdb_stats')->label(__('За 7 дней'))->state(fn (): string => $this->statsLine('upcitemdb')),
            ]),
            Section::make(__('Для комплектующих: что есть кроме Icecat'))->description(__('Справка, проверено 2026-11-02. Бот находит эти страницы обычным веб-поиском; API, как у Icecat, у них нет.'))
                ->schema(array_map(fn (string $key, array $advice): TextEntry => TextEntry::make('advice_'.$key)
                    ->label(__($advice['title']))->state(__($advice['text'])), array_keys(CodeDatabases::COMPONENT_ADVICE), CodeDatabases::COMPONENT_ADVICE)),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('code-databases-form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('saveCodeDatabases')->label(__('Сохранить'))->icon('heroicon-m-check')->submit('save'),
                    Action::make('registerIcecat')->label(__('Регистрация в Icecat'))->icon('heroicon-m-arrow-top-right-on-square')
                        ->color('gray')->url(CodeDatabases::ICECAT_REGISTER_URL, shouldOpenInNewTab: true),
                ])]),
        ]);
    }

    public function save(): void
    {
        CodeDatabases::saveIcecatUsername($this->form->getState()['icecat_username'] ?? null);

        Notification::make()->title(__('Сохранено'))
            ->body(CodeDatabases::icecatUsername() === null ? __('Icecat не подключён: логина нет ни здесь, ни в .env.') : __('Icecat подключён. Новые поиски уже используют его.'))
            ->success()->send();
    }

    private function icecatState(): string
    {
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
            'not_configured' => __('не подключено'), 'quota' => __('лимит'), 'error' => __('ошибка')];

        return implode(', ', array_map(fn (string $decision, int $count): string => ($names[$decision] ?? $decision).': '.$count,
            array_keys($stats), $stats));
    }
}
