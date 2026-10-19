<?php

namespace App\Filament\Pages;

use App\Filament\Pages\LocalizedPage as Page;
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
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use UnitEnum;

class AiSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'AI';

    protected static ?string $title = 'AI и поиск фотографий';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(AiSettings $settings): void
    {
        $this->form->fill([
            'model' => $settings->model(),
            'card_assembly_model' => $settings->cardAssemblyModel(),
            'card_assembly_fallback_model' => $settings->cardAssemblyFallbackModel(),
            'image_model' => $settings->imageModel(),
            'gallery_recipe_training_model' => $settings->galleryRecipeTrainingModel()
                ?: (string) config('services.gallery_recipe_training.model', 'gpt-5.4'),
            'gallery_browser_mode' => $settings->galleryBrowserMode(),
            'fallback_sources_enabled' => $settings->fallbackSourcesEnabled(),
            'gallery_prefer_playwright_first' => $settings->galleryPreferPlaywrightFirst(),
            'browser_headless' => $settings->browserHeadless(),
            'gallery_agent_write_tools_enabled' => $settings->galleryAgentWriteToolsEnabled(),
            'product_search_country' => $settings->productSearchCountry(),
            'source_identity_agent_enabled' => $settings->sourceIdentityAgentEnabled(),
            'specification_reconciliation_enabled' => $settings->specificationReconciliationEnabled(),
            'max_search_cost_usd' => $settings->maxSearchCostUsd(),
            'search_max_seconds' => $settings->searchMaxSeconds(),
            'search_reserve_seconds' => $settings->searchReserveSeconds(),
            'product_research_timeout_seconds' => $settings->productResearchTimeoutSeconds(),
            'product_research_idle_timeout_seconds' => $settings->productResearchIdleTimeoutSeconds(),
            'image_discovery_timeout_seconds' => $settings->imageDiscoveryTimeoutSeconds(),
            'gallery_recipe_timeout_seconds' => $settings->galleryRecipeTimeoutSeconds(),
            'image_vision_timeout_seconds' => $settings->imageVisionTimeoutSeconds(),
            'browser_timeout_seconds' => $settings->browserTimeoutSeconds(),
            'browser_scout_timeout_seconds' => $settings->browserScoutTimeoutSeconds(),
            'gallery_training_max_rounds' => $settings->galleryTrainingMaxRounds(),
            'gallery_min_success_count' => $settings->galleryMinSuccessCount(),
            'image_minimum_width' => $settings->imageMinimumWidth(),
            'image_minimum_height' => $settings->imageMinimumHeight(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshModels')
                ->label(__('Обновить модели'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading(__('Обновить модели и цены OpenAI?'))
                ->modalDescription(__('Проверим доступность через API и стандартные токенные тарифы на официальных страницах моделей.'))
                ->action('refreshModels'),
            Action::make('clearApiKey')
                ->label(__('Сбросить ключ на .env'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (AiSettings $settings): bool => $settings->hasStoredOpenAiApiKey())
                ->requiresConfirmation()
                ->modalHeading(__('Удалить сохранённый API-ключ?'))
                ->modalDescription(__('Приложение вернётся к OPENAI_API_KEY из .env на сервере.'))
                ->action('clearApiKey'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('ai-settings-form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('saveSettings')->label(__('Сохранить настройки AI'))->icon('heroicon-m-check')->submit('save'),
                ])]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $catalog = app(AiModelCatalog::class);
        $checkedAt = $catalog->pricingCheckedAt();

        return $schema->dense()
            ->components([
                Tabs::make('ai_settings')
                    ->tabs([
                        Tab::make(__('Основное'))
                            ->icon(Heroicon::OutlinedKey)
                            ->schema([
                                Section::make(__('Доступ к OpenAI'))
                                    ->description(__('Ключ хранится в базе в зашифрованном виде и переопределяет OPENAI_API_KEY из .env. Пустое поле при сохранении ничего не меняет — используйте "Сбросить ключ на .env" наверху, чтобы вернуться к серверному значению.'))
                                    ->schema([
                                        TextInput::make('openai_api_key')
                                            ->label('OpenAI API key')
                                            ->password()
                                            ->revealable()
                                            ->autocomplete('new-password')
                                            ->dehydrated(fn (?string $state): bool => filled($state))
                                            ->placeholder('sk-...')
                                            ->helperText(fn (AiSettings $settings): string => $settings->hasStoredOpenAiApiKey()
                                                ? __('Сейчас используется ключ, сохранённый в базе.')
                                                : __('Сейчас используется OPENAI_API_KEY из .env.')),
                                    ]),
                                Section::make(__('Модель OpenAI'))
                                    ->description(__('Один каталог используется для выбора модели и приблизительного расчёта стоимости в Telegram. Цены указаны в USD за 1 млн токенов стандартного API-тарифа.'))
                                    ->schema([
                                        Select::make('model')
                                            ->label(__('Модель и тариф'))
                                            ->options(fn (): array => app(AiModelCatalog::class)->options('openai'))
                                            ->placeholder(__('Из .env'))
                                            ->helperText(__('Пусто — используются модели из .env. Тарифы проверены по официальным страницам').($checkedAt ? " {$checkedAt}." : '.'))
                                            ->searchable(),
                                        Select::make('card_assembly_model')
                                            ->label(__('Модель сборки карточки'))
                                            ->options(fn (): array => app(AiModelCatalog::class)->options('openai'))
                                            ->placeholder(__('Как основная модель'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Сборка карточки цитирует каждую характеристику дословно со страницы источника. Самые дешёвые модели пересказывают вместо цитаты, проверка отклоняет карточку, и поиск заканчивается без неё. Основная модель может быть дешёвой — эта должна справляться.'))
                                            ->searchable(),
                                        Select::make('card_assembly_fallback_model')
                                            ->label(__('Запасная модель сборки карточки'))
                                            ->options(fn (): array => app(AiModelCatalog::class)->options('openai'))
                                            ->placeholder(__('Нет'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Доделывает карточку, если модель сборки не смогла её собрать. Так сборка может идти на дешёвой модели, а более сильная платится только за редкие трудные карточки — вместо «поиск не завершён».'))
                                            ->searchable(),
                                    ])->columns(['default' => 1, 'lg' => 3]),
                            ]),
                        Tab::make(__('Поиск фотографий'))
                            ->icon(Heroicon::OutlinedPhoto)
                            ->schema([
                                Section::make(__('Рецепты и качество'))
                                    ->description(__('Фотографии одной карточки никогда не смешиваются. Известные карточки-кандидаты (найденные первичным Web Search) перебираются по очереди всегда, пока есть бюджет/время — это не настраивается отдельно.'))
                                    ->schema([
                                        Select::make('gallery_recipe_training_model')
                                            ->label(__('Сильная модель тренера'))
                                            ->options(fn (): array => app(AiModelCatalog::class)->options('openai'))
                                            ->default((string) config('services.gallery_recipe_training.model', 'gpt-5.4'))
                                            ->required()
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Рецепт — безопасный JSON с CSS-селекторами и ограниченными действиями Playwright.'))
                                            ->searchable(),
                                        Toggle::make('gallery_prefer_playwright_first')
                                            ->label(__('Обучать Playwright даже когда статики "достаточно"'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Оценка предфильтра по количеству фото (например "8") может быть завышена миниатюрами/повторами. Включено (по умолчанию) — при найденной галерее рецепт всё равно обучается и результат проверяется Vision, а не только оценкой. Медленнее и дороже за поиск, зато рецепт сохраняется для повторного использования.'))
                                            ->default(true)
                                            ->inline(false),
                                        Toggle::make('browser_headless')
                                            ->label(__('Скрытый браузер (без окна)'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Включено — браузер работает без окна. Быстрее и не мешает, но магазины это видят: у скрытого браузера нет окна, нет плагинов и другой путь отрисовки, и защита читает это со страницы. Выключите, если домен встречает капчей - видимое окно проходит заметно чаще. Окно при этом уводится за пределы экрана и поверх работы не появляется.'))
                                            ->default(true)
                                            ->inline(false),
                                        Toggle::make('gallery_agent_write_tools_enabled')
                                            ->label(__('Разрешить AI-тренеру самому решать, когда сдаваться, и оставлять заметки о домене'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Выключено по умолчанию. Агент получает два инструмента: прекратить обучение на URL с указанием причины (учитывается в статистике отказов рецепта так же, как обычный сбой) и добавить короткую заметку в постоянную подсказку домена. Каждый вызов фиксируется в журнале AI-операций. Выключите мгновенно, если агент начнёт принимать плохие решения.'))
                                            ->default(false)
                                            ->inline(false),
                                        TextInput::make('product_search_country')
                                            ->label(__('Страна веб-поиска (пусто — весь мир)'))
                                            ->placeholder(__('пусто'))
                                            ->maxLength(2)
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Было жёстко зашито "US", из-за чего в кандидаты попадали одни и те же американские магазины — те самые, что потом блокируют нас по репутации IP, — а европейские, которые отдали бы фото, не всплывали вовсе. Пусто = поиск без страновой подсказки. Место магазина само по себе никогда не было поводом его отклонить: единственное языковое правило касается текста на самих фотографиях, и его проверяет Vision.'))
                                            ->rule('regex:/^[A-Za-z]{2}$/')
                                            ->nullable(),
                                        Toggle::make('specification_reconciliation_enabled')
                                            ->label(__('Сборка карточки перед подтверждением'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Обязательный этап готовой карточки. Выключение приостанавливает новые интернет-поиски, но не разрешает публикацию непроверенных данных. Каталог остаётся доступен.'))
                                            ->default(true)
                                            ->inline(false),
                                        Toggle::make('source_identity_agent_enabled')
                                            ->label(__('Подтверждать карточку через AI, когда точный текстовый шаблон не совпал'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Включено по умолчанию. Быстрая текстовая проверка (модель/SKU буквально в URL или заголовке) остаётся первой и бесплатной — этот AI-вызов срабатывает только когда она не подтвердила и не опровергла карточку явно, например если страница перечисляет те же слова в другом порядке. Реальный случай: страница B&H с точным SKU MC7A4LL/A была отклонена только из-за порядка слов в URL. Выключите мгновенно, если агент начнёт подтверждать не те карточки.'))
                                            ->default(true)
                                            ->inline(false),
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('image_minimum_width')
                                                    ->label(__('Минимальная ширина фото'))
                                                    ->numeric()
                                                    ->minValue(AiSettings::MIN_IMAGE_WIDTH)
                                                    ->maxValue(AiSettings::MAX_IMAGE_DIMENSION)
                                                    ->default(AiSettings::DEFAULT_IMAGE_MINIMUM_WIDTH)
                                                    ->suffix('px')
                                                    ->required()
                                                    ->helperText(__('Фото проходит только при ширине не меньше этого значения.')),
                                                TextInput::make('image_minimum_height')
                                                    ->label(__('Минимальная высота фото'))
                                                    ->numeric()
                                                    ->minValue(AiSettings::MIN_IMAGE_HEIGHT)
                                                    ->maxValue(AiSettings::MAX_IMAGE_DIMENSION)
                                                    ->default(AiSettings::DEFAULT_IMAGE_MINIMUM_HEIGHT)
                                                    ->suffix('px')
                                                    ->required()
                                                    ->helperText(__('0 — не ограничивать высоту.')),
                                            ]),
                                    ])->columns(['default' => 1, 'lg' => 2]),
                                Section::make(__('Улучшение фото'))
                                    ->description(__('Модель для команды "апскейл фото". Цена изображения зависит от размера и качества, поэтому не входит в токенный тариф выше.'))
                                    ->schema([
                                        Select::make('image_model')
                                            ->label(__('Модель изображений'))
                                            ->options(AiSettings::IMAGE_MODELS)
                                            ->placeholder(__('Из .env'))
                                            ->helperText(__('Пусто — используется модель, заданная в .env.'))
                                            ->searchable(),
                                    ]),
                            ]),
                        Tab::make(__('Бюджеты и лимиты'))
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([
                                Section::make(__('Лимиты поиска'))
                                    ->description(__('Общий дедлайн всегда короче таймаута воркера. Резерв не используется для новых поисков и остаётся на сохранение черновика и ответ Telegram.'))
                                    ->schema([
                                        TextInput::make('max_search_cost_usd')
                                            ->label(__('Бюджет одного поиска, $'))
                                            ->numeric()->minValue(0)->step(0.01)
                                            ->default(AiSettings::DEFAULT_MAX_SEARCH_COST_USD)
                                            ->required()
                                            ->helperText(__('0 — без денежного ограничения.')),
                                        TextInput::make('search_max_seconds')
                                            ->label(__('Максимум всего цикла, сек'))
                                            ->numeric()->minValue(300)->maxValue(1800)
                                            ->default(AiSettings::DEFAULT_SEARCH_MAX_SECONDS)
                                            ->required(),
                                        TextInput::make('search_reserve_seconds')
                                            ->label(__('Резерв на завершение, сек'))
                                            ->numeric()->minValue(30)->maxValue(300)
                                            ->default(AiSettings::DEFAULT_SEARCH_RESERVE_SECONDS)
                                            ->required(),
                                    ])
                                    ->columns(3),
                            ]),
                        Tab::make(__('Дополнительно'))
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->schema([
                                Section::make(__('Редкие и опасные параметры'))
                                    ->description(__('Меняйте эти значения только если понимаете последствия — они влияют на стоимость, скорость и надёжность каждого поиска.'))
                                    ->icon(Heroicon::OutlinedExclamationTriangle)
                                    ->iconColor('warning')
                                    ->collapsed()
                                    ->schema([
                                        Toggle::make('fallback_sources_enabled')
                                            ->label(__('Резервный AI веб-поиск по новым магазинам'))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Включается только когда ВСЕ уже известные карточки-источники перебраны и ни одна не дала галерею. Запускает новые платные раунды AI веб-поиска по интернету (не привязан к конкретной странице) с Vision-проверкой найденного — дороже и менее точен, чем перебор уже известных карточек, поэтому вынесен в отдельный тумблер для контроля стоимости.'))
                                            ->default(true)
                                            ->inline(false),
                                        ToggleButtons::make('gallery_browser_mode')
                                            ->label(__('Режим браузерного поиска'))
                                            ->options(AiSettings::GALLERY_BROWSER_MODES)
                                            ->icons([
                                                AiSettings::GALLERY_BROWSER_AUTO => 'heroicon-o-bolt',
                                                AiSettings::GALLERY_BROWSER_OFF => 'heroicon-o-no-symbol',
                                                AiSettings::GALLERY_BROWSER_ALWAYS => 'heroicon-o-globe-alt',
                                            ])
                                            ->default(AiSettings::GALLERY_BROWSER_AUTO)
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Выключен — Playwright не запускается никогда. Авто и Всегда сейчас ведут себя одинаково: код проверяет только "не выключен", отдельного поведения для "всегда проверять каждую карточку" ещё не реализовано — это известный технический долг, а не баг этой настройки.'))
                                            ->inline()
                                            ->grouped()
                                            ->required(),
                                        TextInput::make('gallery_training_max_rounds')
                                            ->label(__('Раундов AI ↔ Playwright (запасной потолок)'))
                                            ->numeric()->minValue(1)->maxValue(12)
                                            ->default(AiSettings::DEFAULT_GALLERY_TRAINING_MAX_ROUNDS)
                                            ->required()
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('Не обычный лимит — раунды продолжаются, пока хватает денежного/временного бюджета поиска. Это число используется только когда бюджет посчитать нельзя (тесты, модель без цены, бюджет выключен).')),
                                        TextInput::make('gallery_min_success_count')
                                            ->label(__('Минимум фото для успеха рецепта'))
                                            ->numeric()->minValue(1)->maxValue(AiSettings::GALLERY_MAX_IMAGE_COUNT)
                                            ->default(AiSettings::DEFAULT_GALLERY_MIN_SUCCESS_COUNT)
                                            ->required()
                                            ->helperText(__('Рецепт засчитан успешным, если извлёк хотя бы столько разных фото (максимум всегда ').AiSettings::GALLERY_MAX_IMAGE_COUNT.').'),
                                        Grid::make(3)
                                            ->schema([
                                                TextInput::make('product_research_timeout_seconds')
                                                    ->label(__('Web Search товара, сек'))
                                                    ->numeric()->minValue(60)->maxValue(900)
                                                    ->default(AiSettings::DEFAULT_PRODUCT_RESEARCH_TIMEOUT_SECONDS)
                                                    ->required(),
                                                TextInput::make('product_research_idle_timeout_seconds')
                                                    ->label(__('Web Search без активности, сек'))
                                                    ->numeric()->minValue(30)->maxValue(180)
                                                    ->default(AiSettings::DEFAULT_PRODUCT_RESEARCH_IDLE_TIMEOUT_SECONDS)
                                                    ->required(),
                                                TextInput::make('image_discovery_timeout_seconds')
                                                    ->label(__('Резервный Web Search, сек'))
                                                    ->numeric()->minValue(30)->maxValue(300)
                                                    ->default(AiSettings::DEFAULT_IMAGE_DISCOVERY_TIMEOUT_SECONDS)
                                                    ->required(),
                                                TextInput::make('gallery_recipe_timeout_seconds')
                                                    ->label(__('Обучение рецепта, сек'))
                                                    ->numeric()->minValue(30)->maxValue(600)
                                                    ->default(AiSettings::DEFAULT_GALLERY_RECIPE_TIMEOUT_SECONDS)
                                                    ->required(),
                                                TextInput::make('image_vision_timeout_seconds')
                                                    ->label(__('Vision-проверка, сек'))
                                                    ->numeric()->minValue(15)->maxValue(180)
                                                    ->default(AiSettings::DEFAULT_IMAGE_VISION_TIMEOUT_SECONDS)
                                                    ->required(),
                                                TextInput::make('browser_timeout_seconds')
                                                    ->label(__('Playwright-рецепт, сек'))
                                                    ->numeric()->minValue(15)->maxValue(300)
                                                    ->default(AiSettings::DEFAULT_BROWSER_TIMEOUT_SECONDS)
                                                    ->required(),
                                                TextInput::make('browser_scout_timeout_seconds')
                                                    ->label(__('Playwright Scout, сек'))
                                                    ->numeric()->minValue(15)->maxValue(300)
                                                    ->default(AiSettings::DEFAULT_BROWSER_SCOUT_TIMEOUT_SECONDS)
                                                    ->required(),
                                            ])->columnSpanFull(),
                                    ])->columns(['default' => 1, 'lg' => 2]),
                            ]),
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
                ->title(__('Не удалось обновить модели'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->mount(app(AiSettings::class));
        $errors = count($result['errors']);

        $notification = Notification::make()
            ->title($errors === 0 ? __('Модели и цены обновлены') : __('Модели обновлены частично'))
            ->body(
                __('Доступно: :available; недоступно: :unavailable; тарифов обновлено: :prices', [
                    'available' => $result['available'], 'unavailable' => $result['unavailable'], 'prices' => $result['prices_updated'],
                ]).($errors > 0 ? __('; ошибок тарифов: :errors. Старые цены сохранены.', ['errors' => $errors]) : '.')
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
            ->title(__('API-ключ сброшен на .env'))
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
        $settings->saveCardAssemblyModel($data['card_assembly_model'] ?? null);
        $settings->saveCardAssemblyFallbackModel($data['card_assembly_fallback_model'] ?? null);
        $settings->saveGalleryBrowserMode($data['gallery_browser_mode'] ?? null);
        $settings->saveFallbackSourcesEnabled((bool) ($data['fallback_sources_enabled'] ?? false));
        $settings->saveGalleryPreferPlaywrightFirst((bool) ($data['gallery_prefer_playwright_first'] ?? false));
        $settings->saveBrowserHeadless((bool) ($data['browser_headless'] ?? true));
        $settings->saveGalleryAgentWriteToolsEnabled((bool) ($data['gallery_agent_write_tools_enabled'] ?? false));
        $settings->saveProductSearchCountry($data['product_search_country'] ?? null);
        $settings->saveSourceIdentityAgentEnabled((bool) ($data['source_identity_agent_enabled'] ?? false));
        $settings->saveSpecificationReconciliationEnabled((bool) ($data['specification_reconciliation_enabled'] ?? true));
        $settings->saveMaxSearchCostUsd(
            isset($data['max_search_cost_usd']) && $data['max_search_cost_usd'] !== ''
                ? (float) $data['max_search_cost_usd']
                : null,
        );
        $settings->saveSearchMaxSeconds(isset($data['search_max_seconds']) ? (int) $data['search_max_seconds'] : null);
        $settings->saveSearchReserveSeconds(isset($data['search_reserve_seconds']) ? (int) $data['search_reserve_seconds'] : null);
        $settings->saveProductResearchTimeoutSeconds(isset($data['product_research_timeout_seconds']) ? (int) $data['product_research_timeout_seconds'] : null);
        $settings->saveProductResearchIdleTimeoutSeconds(isset($data['product_research_idle_timeout_seconds']) ? (int) $data['product_research_idle_timeout_seconds'] : null);
        $settings->saveImageDiscoveryTimeoutSeconds(isset($data['image_discovery_timeout_seconds']) ? (int) $data['image_discovery_timeout_seconds'] : null);
        $settings->saveGalleryRecipeTimeoutSeconds(isset($data['gallery_recipe_timeout_seconds']) ? (int) $data['gallery_recipe_timeout_seconds'] : null);
        $settings->saveImageVisionTimeoutSeconds(isset($data['image_vision_timeout_seconds']) ? (int) $data['image_vision_timeout_seconds'] : null);
        $settings->saveBrowserTimeoutSeconds(isset($data['browser_timeout_seconds']) ? (int) $data['browser_timeout_seconds'] : null);
        $settings->saveBrowserScoutTimeoutSeconds(isset($data['browser_scout_timeout_seconds']) ? (int) $data['browser_scout_timeout_seconds'] : null);
        $settings->saveGalleryTrainingMaxRounds(isset($data['gallery_training_max_rounds']) ? (int) $data['gallery_training_max_rounds'] : null);
        $settings->saveGalleryMinSuccessCount(isset($data['gallery_min_success_count']) ? (int) $data['gallery_min_success_count'] : null);
        $settings->saveImageMinimumWidth(isset($data['image_minimum_width']) ? (int) $data['image_minimum_width'] : null);
        $settings->saveImageMinimumHeight(isset($data['image_minimum_height']) ? (int) $data['image_minimum_height'] : null);

        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            // Настройки уже сохранены; активный воркер применит их при следующем запуске.
        }

        Notification::make()
            ->title(__('Настройки AI сохранены'))
            ->success()
            ->send();
    }
}
