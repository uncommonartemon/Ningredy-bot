<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\InteractsWithCatalogCleanup;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CatalogMaintenance extends LocalizedPage implements HasForms
{
    use InteractsWithCatalogCleanup;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|UnitEnum|null $navigationGroup = 'Система';

    protected static ?string $navigationLabel = 'Очистка данных';

    protected static ?string $title = 'Очистка данных';

    protected static ?int $navigationSort = 90;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $user = $user instanceof User ? $user->fresh() : null;

        return $user && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function mount(): void
    {
        $this->form->fill(['dictionaries' => false, 'recipes_history' => false]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->catalogCleanupAction('catalog'),
            $this->catalogCleanupAction('drafts'),
            $this->catalogCleanupAction('test_data'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Что очищается'))->schema([
                Text::make(__('Каталог — товары, комплектации, их фото, источники и значения характеристик. Черновики — все черновики и их фото. Общая очистка — каталог, черновики и временные диалоги.')),
                Text::make(__('Настройки, API-ключи, пользователи, журнал Telegram/AI и номера записей сохраняются. Справочники и обучение источников сохраняются, пока вы явно не выберете их ниже.')),
                Text::make(__('Если есть активные или ожидающие задания, очистка заблокирована. Завершите или отмените работу; кнопка подтверждения покажет причину. Изменение данных после предпросмотра потребует открыть его заново.')),
            ]),
            EmbeddedSchema::make('form'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Дополнительно для общей очистки'))
                ->description(__('Эти параметры действуют только для кнопки «Общая очистка для тестов». Состав будет показан перед удалением.'))
                ->schema([
                    Checkbox::make('dictionaries')->label(__('Очистить также справочники'))
                        ->helperText(__('Определения и группы характеристик, категории и бренды.'))->default(false)->live(),
                    Checkbox::make('recipes_history')->label(__('Очистить также обучение источников'))
                        ->helperText(__('Рецепты, версии, статистика и автоматические правила страниц. Блокировки источников и аудит сохраняются.'))->default(false)->live(),
                ]),
        ])->statePath('data');
    }

    protected function catalogCleanupOptions(string $preset): array
    {
        return [
            'dictionaries' => $preset === 'test_data' && (bool) ($this->data['dictionaries'] ?? false),
            'recipes_history' => $preset === 'test_data' && (bool) ($this->data['recipes_history'] ?? false),
        ];
    }
}
