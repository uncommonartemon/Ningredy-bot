<?php

namespace App\Filament\Pages;

use App\Services\Ai\AiModelCatalog;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiModelCatalogSynchronizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
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
            'gallery_recipe_training_model' => $settings->galleryRecipeTrainingModel()
                ?: (string) config('services.gallery_recipe_training.model', 'gpt-5.4'),
            'gallery_browser_mode' => $settings->galleryBrowserMode(),
            'fallback_sources_enabled' => $settings->fallbackSourcesEnabled(),
            'max_search_cost_usd' => $settings->maxSearchCostUsd(),
            'search_max_seconds' => $settings->searchMaxSeconds(),
            'search_reserve_seconds' => $settings->searchReserveSeconds(),
            'product_research_timeout_seconds' => $settings->productResearchTimeoutSeconds(),
            'image_discovery_timeout_seconds' => $settings->imageDiscoveryTimeoutSeconds(),
            'gallery_recipe_timeout_seconds' => $settings->galleryRecipeTimeoutSeconds(),
            'image_vision_timeout_seconds' => $settings->imageVisionTimeoutSeconds(),
            'browser_timeout_seconds' => $settings->browserTimeoutSeconds(),
            'browser_scout_timeout_seconds' => $settings->browserScoutTimeoutSeconds(),
            'gallery_training_max_rounds' => $settings->galleryTrainingMaxRounds(),
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
            Action::make('clearApiKey')
                ->label('Сбросить ключ на .env')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (AiSettings $settings): bool => $settings->hasStoredOpenAiApiKey())
                ->requiresConfirmation()
                ->modalHeading('Удалить сохранённый API-ключ?')
                ->modalDescription('Приложение вернётся к OPENAI_API_KEY из .env на сервере.')
                ->action('clearApiKey'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $catalog = app(AiModelCatalog::class);
        $checkedAt = $catalog->pricingCheckedAt();

        return $schema
            ->components([
                Section::make('Доступ к OpenAI')
                    ->description('Ключ хранится в базе в зашифрованном виде и переопределяет OPENAI_API_KEY из .env. Пустое поле при сохранении ничего не меняет — используйте "Сбросить ключ на .env" наверху, чтобы вернуться к серверному значению.')
                    ->schema([
                        TextInput::make('openai_api_key')
                            ->label('OpenAI API key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->placeholder('sk-...')
                            ->helperText(fn (AiSettings $settings): string => $settings->hasStoredOpenAiApiKey()
                                ? 'Сейчас используется ключ, сохранённый в базе.'
                                : 'Сейчас используется OPENAI_API_KEY из .env.'),
                    ]),
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
                Section::make('Источники и Playwright')
                    ->description('Фотографии одной карточки никогда не смешиваются. Резервные источники означают только переход к следующей целой странице после полного провала текущей.')
                    ->schema([
                        Toggle::make('fallback_sources_enabled')
                            ->label('Пробовать резервные источники')
                            ->helperText('Выключено — проверяется только первая выбранная карточка. Включено — при провале берётся следующая карточка, но галерея остаётся из одного источника.')
                            ->default(true)
                            ->inline(false),
                        ToggleButtons::make('gallery_browser_mode')
                            ->label('Режим браузерного поиска')
                            ->options(AiSettings::GALLERY_BROWSER_MODES)
                            ->icons([
                                AiSettings::GALLERY_BROWSER_AUTO => 'heroicon-o-bolt',
                                AiSettings::GALLERY_BROWSER_OFF => 'heroicon-o-no-symbol',
                                AiSettings::GALLERY_BROWSER_ALWAYS => 'heroicon-o-globe-alt',
                            ])
                            ->default(AiSettings::GALLERY_BROWSER_AUTO)
                            ->helperText('Авто — Playwright запускается только для скрытой или неполной галереи. Всегда — браузер проверяет каждую карточку.')
                            ->inline()
                            ->grouped()
                            ->required(),
                        Select::make('gallery_recipe_training_model')
                            ->label('Сильная модель тренера')
                            ->options(fn (): array => app(AiModelCatalog::class)->options('openai'))
                            ->default((string) config('services.gallery_recipe_training.model', 'gpt-5.4'))
                            ->required()
                            ->helperText('Рецепт — безопасный JSON с CSS-селекторами и ограниченными действиями Playwright.')
                            ->searchable(),
                        TextInput::make('gallery_training_max_rounds')
                            ->label('Максимум раундов AI ↔ Playwright')
                            ->numeric()->minValue(1)->maxValue(4)
                            ->default(AiSettings::DEFAULT_GALLERY_TRAINING_MAX_ROUNDS)
                            ->required()
                            ->helperText('Каждый новый раунд получает DOM после предыдущих кликов. 3 обычно хватает для двухслойной галереи.'),
                    ])
                    ->columns(2),
                Section::make('Лимиты поиска')
                    ->description('Общий дедлайн всегда короче таймаута воркера. Резерв не используется для новых поисков и остаётся на сохранение черновика и ответ Telegram.')
                    ->schema([
                        TextInput::make('max_search_cost_usd')
                            ->label('Бюджет одного поиска, $')
                            ->numeric()->minValue(0)->step(0.01)
                            ->default(AiSettings::DEFAULT_MAX_SEARCH_COST_USD)
                            ->required()
                            ->helperText('0 — без денежного ограничения.'),
                        TextInput::make('search_max_seconds')
                            ->label('Максимум всего цикла, сек')
                            ->numeric()->minValue(300)->maxValue(1200)
                            ->default(AiSettings::DEFAULT_SEARCH_MAX_SECONDS)
                            ->required(),
                        TextInput::make('search_reserve_seconds')
                            ->label('Резерв на завершение, сек')
                            ->numeric()->minValue(30)->maxValue(300)
                            ->default(AiSettings::DEFAULT_SEARCH_RESERVE_SECONDS)
                            ->required(),
                    ])
                    ->columns(3),
                Section::make('Таймауты операций')
                    ->description('Долгим операциям разрешено ждать, но их фактический таймаут автоматически уменьшается, если подходит общий дедлайн.')
                    ->schema([
                        TextInput::make('product_research_timeout_seconds')
                            ->label('Web Search товара, сек')
                            ->numeric()->minValue(60)->maxValue(600)
                            ->default(AiSettings::DEFAULT_PRODUCT_RESEARCH_TIMEOUT_SECONDS)
                            ->required(),
                        TextInput::make('image_discovery_timeout_seconds')
                            ->label('Резервный Web Search, сек')
                            ->numeric()->minValue(30)->maxValue(300)
                            ->default(AiSettings::DEFAULT_IMAGE_DISCOVERY_TIMEOUT_SECONDS)
                            ->required(),
                        TextInput::make('gallery_recipe_timeout_seconds')
                            ->label('Обучение рецепта, сек')
                            ->numeric()->minValue(30)->maxValue(600)
                            ->default(AiSettings::DEFAULT_GALLERY_RECIPE_TIMEOUT_SECONDS)
                            ->required(),
                        TextInput::make('image_vision_timeout_seconds')
                            ->label('Vision-проверка, сек')
                            ->numeric()->minValue(15)->maxValue(180)
                            ->default(AiSettings::DEFAULT_IMAGE_VISION_TIMEOUT_SECONDS)
                            ->required(),
                        TextInput::make('browser_timeout_seconds')
                            ->label('Playwright-рецепт, сек')
                            ->numeric()->minValue(15)->maxValue(300)
                            ->default(AiSettings::DEFAULT_BROWSER_TIMEOUT_SECONDS)
                            ->required(),
                        TextInput::make('browser_scout_timeout_seconds')
                            ->label('Playwright Scout, сек')
                            ->numeric()->minValue(15)->maxValue(300)
                            ->default(AiSettings::DEFAULT_BROWSER_SCOUT_TIMEOUT_SECONDS)
                            ->required(),
                    ])
                    ->columns(3),
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

    public function clearApiKey(AiSettings $settings): void
    {
        $settings->saveOpenAiApiKey(null);

        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            // Настройки уже сохранены; активный воркер применит их при следующем запуске.
        }

        Notification::make()
            ->title('API-ключ сброшен на .env')
            ->success()
            ->send();
    }

    public function save(AiSettings $settings): void
    {
        $data = $this->form->getState();

        if (filled($data['openai_api_key'] ?? null)) {
            $settings->saveOpenAiApiKey($data['openai_api_key']);
        }

        $settings->saveModel($data['model'] ?? null);
        $settings->saveImageModel($data['image_model'] ?? null);
        $settings->saveGalleryRecipeTrainingModel($data['gallery_recipe_training_model'] ?? null);
        $settings->saveGalleryBrowserMode($data['gallery_browser_mode'] ?? null);
        $settings->saveFallbackSourcesEnabled((bool) ($data['fallback_sources_enabled'] ?? false));
        $settings->saveMaxSearchCostUsd(
            isset($data['max_search_cost_usd']) && $data['max_search_cost_usd'] !== ''
                ? (float) $data['max_search_cost_usd']
                : null,
        );
        $settings->saveSearchMaxSeconds(isset($data['search_max_seconds']) ? (int) $data['search_max_seconds'] : null);
        $settings->saveSearchReserveSeconds(isset($data['search_reserve_seconds']) ? (int) $data['search_reserve_seconds'] : null);
        $settings->saveProductResearchTimeoutSeconds(isset($data['product_research_timeout_seconds']) ? (int) $data['product_research_timeout_seconds'] : null);
        $settings->saveImageDiscoveryTimeoutSeconds(isset($data['image_discovery_timeout_seconds']) ? (int) $data['image_discovery_timeout_seconds'] : null);
        $settings->saveGalleryRecipeTimeoutSeconds(isset($data['gallery_recipe_timeout_seconds']) ? (int) $data['gallery_recipe_timeout_seconds'] : null);
        $settings->saveImageVisionTimeoutSeconds(isset($data['image_vision_timeout_seconds']) ? (int) $data['image_vision_timeout_seconds'] : null);
        $settings->saveBrowserTimeoutSeconds(isset($data['browser_timeout_seconds']) ? (int) $data['browser_timeout_seconds'] : null);
        $settings->saveBrowserScoutTimeoutSeconds(isset($data['browser_scout_timeout_seconds']) ? (int) $data['browser_scout_timeout_seconds'] : null);
        $settings->saveGalleryTrainingMaxRounds(isset($data['gallery_training_max_rounds']) ? (int) $data['gallery_training_max_rounds'] : null);

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
