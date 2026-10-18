<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Http\Middleware\SetAdminLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        Table::configureUsing(fn (Table $table): Table => $table
            ->paginationPageOptions([25, 50, 100, 'all'])
            ->defaultPaginationPageOption('all')
            ->persistRecordsPerPageInSession(false));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('Ningredy')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('Каталог')),
                NavigationGroup::make()->label(fn (): string => __('Поиск и AI')),
                NavigationGroup::make()->label(fn (): string => __('Настройки')),
                NavigationGroup::make()->label(fn (): string => __('Система')),
            ])
            ->userMenuItems([
                fn () => Action::make('localeRu')->label('Русский')->icon('heroicon-o-language')
                    ->url(route('admin.locale', ['locale' => 'ru']))->postToUrl(),
                fn () => Action::make('localeCs')->label('Čeština')->icon('heroicon-o-language')
                    ->url(route('admin.locale', ['locale' => 'cs']))->postToUrl(),
                fn () => Action::make('localeUk')->label('Українська')->icon('heroicon-o-language')
                    ->url(route('admin.locale', ['locale' => 'uk']))->postToUrl(),
                fn () => Action::make('localeEn')->label('English')->icon('heroicon-o-language')
                    ->url(route('admin.locale', ['locale' => 'en']))->postToUrl(),
            ])
            ->maxContentWidth(Width::Full)
            ->renderHook(PanelsRenderHook::STYLES_AFTER,
                fn () => view('filament.styles.compact-products-table'), scopes: [ListProducts::class])
            ->renderHook(PanelsRenderHook::STYLES_AFTER,
                fn () => view('filament.styles.compact-product-attributes'), scopes: [CreateProduct::class, EditProduct::class])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->middleware([SetAdminLocale::class], isPersistent: true);
    }
}
