<?php

namespace App\Filament\Pages;

use App\Services\Ai\AiModelCatalog;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiModelCatalogSynchronizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use UnitEnum;

class AiSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'AI';

    protected static ?string $title = 'Модель OpenAI';

    protected static ?int $navigationSort = 101;

    protected string $view = 'filament.pages.ai-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(AiSettings $settings): void
    {
        $this->form->fill([
            'model' => $settings->model(),
            'image_model' => $settings->imageModel(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshModels')
                ->label('Обновить модели')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Обновить модели и цены OpenAI?')
                ->modalDescription('Проверим доступность через API и стандартные токенные тарифы на официальных страницах моделей.')
                ->action('refreshModels'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $catalog = app(AiModelCatalog::class);
        $checkedAt = $catalog->pricingCheckedAt();

        return $schema
            ->components([
                Section::make('Модель OpenAI')
                    ->description('Один каталог используется для выбора модели и приблизительного расчёта стоимости в Telegram. Цены указаны в USD за 1 млн токенов стандартного API-тарифа.')
                    ->schema([
                        Select::make('model')
                            ->label('Модель и тариф')
                            ->options(fn (): array => app(AiModelCatalog::class)->options('openai'))
                            ->placeholder('Из .env')
                            ->helperText('Пусто — используются модели из .env. Тарифы проверены по официальным страницам'.($checkedAt ? " {$checkedAt}." : '.'))
                            ->searchable(),
                    ]),
                Section::make('Улучшение фото')
                    ->description('Модель для команды "апскейл фото". Цена изображения зависит от размера и качества, поэтому не входит в токенный тариф выше.')
                    ->schema([
                        Select::make('image_model')
                            ->label('Модель изображений')
                            ->options(AiSettings::IMAGE_MODELS)
                            ->placeholder('Из .env')
                            ->helperText('Пусто — используется модель, заданная в .env.')
                            ->searchable(),
                    ]),
            ])
            ->statePath('data');
    }

    public function refreshModels(OpenAiModelCatalogSynchronizer $synchronizer): void
    {
        try {
            $result = $synchronizer->sync();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Не удалось обновить модели')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->mount(app(AiSettings::class));
        $errors = count($result['errors']);

        $notification = Notification::make()
            ->title($errors === 0 ? 'Модели и цены обновлены' : 'Модели обновлены частично')
            ->body(
                "Доступно: {$result['available']}; недоступно: {$result['unavailable']}; ".
                "тарифов обновлено: {$result['prices_updated']}".
                ($errors > 0 ? "; ошибок тарифов: {$errors}. Старые цены сохранены." : '.')
            );

        if ($errors === 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();
    }

    public function save(AiSettings $settings): void
    {
        $data = $this->form->getState();

        $settings->saveModel($data['model'] ?? null);
        $settings->saveImageModel($data['image_model'] ?? null);

        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            // Настройки уже сохранены; активный воркер применит их при следующем запуске.
        }

        Notification::make()
            ->title('Настройки AI сохранены')
            ->success()
            ->send();
    }
}
