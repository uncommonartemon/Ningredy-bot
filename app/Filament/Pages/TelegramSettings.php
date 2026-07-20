<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Services\Telegram\TelegramWebhookManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Throwable;
use UnitEnum;

class TelegramSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Telegram';

    protected static ?string $title = 'Telegram webhook';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.telegram-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(TelegramWebhookManager $manager): void
    {
        $this->form->fill([
            'proxy_url' => $manager->configuredProxyUrl(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('proxy_url')
                    ->label('Публичный proxy URL')
                    ->placeholder('https://example.ngrok-free.app')
                    ->helperText('Можно указать базовый URL или полный /api/telegram/webhook.')
                    ->url()
                    ->rules(['starts_with:https://'])
                    ->required()
                    ->live(onBlur: true)
                    ->suffixAction(
                        Action::make('setWebhook')
                            ->label('Set webhook')
                            ->icon('heroicon-m-link')
                            ->button()
                            ->action('setWebhook'),
                    ),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        AppSetting::put(AppSetting::TELEGRAM_PROXY_URL, rtrim(trim($data['proxy_url']), '/'));

        Notification::make()
            ->title('Proxy URL сохранён')
            ->success()
            ->send();
    }

    public function setWebhook(TelegramWebhookManager $manager): void
    {
        $data = $this->form->getState();
        $proxyUrl = rtrim(trim($data['proxy_url']), '/');
        AppSetting::put(AppSetting::TELEGRAM_PROXY_URL, $proxyUrl);

        try {
            $webhookUrl = $manager->register($proxyUrl);

            Notification::make()
                ->title('Telegram webhook установлен')
                ->body($webhookUrl)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Не удалось установить webhook')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
