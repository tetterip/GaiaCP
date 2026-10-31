<?php

namespace App\Providers\Filament;

use App\Filament\Avatars\InitialsAvatar;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\GlobalSearch\GlobalSearchProvider;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Http\Controllers\Admin\ServerPanelLoginController;
use App\Http\Controllers\Admin\TicketAttachmentController;
use App\Http\Middleware\AlertWhenCronStops;
use App\Http\Middleware\ApplyTheme;
use App\Http\Middleware\BlockBannedIps;
use App\Http\Middleware\PrepareInstaller;
use App\Http\Middleware\SetLocale;
use App\Livewire\PanelNotifications;
use App\Livewire\TwoFactorReminder;
use App\Plugins\PluginManager;
use App\Support\Demo;
use App\Support\WelcomeTour;
use App\Themes\Themes;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\DatabaseNotificationsPosition;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // "Forgot password?" link: an email with a reset link to admins who can log in (2FA still asked after)
            ->passwordReset(RequestPasswordReset::class)
            ->profile(EditProfile::class, isSimple: false)
            // Optional per user: each admin turns it on from their profile (user menu)
            ->multiFactorAuthentication(fn (): array => Demo::enabled() ? [] : [ // the demo's accounts stay as they are
                AppAuthentication::make()
                    ->brandName('GaiaCP')
                    ->recoverable(),
            ])
            ->brandName('GaiaCP')
            // GaiaCP's own brand here (public/images/gaiacp); the company logo is for the client area only.
            ->brandLogo(fn (): string => asset('images/gaiacp/logo.svg'))
            ->darkModeBrandLogo(fn (): string => asset('images/gaiacp/logo-dark.svg'))
            // Light until the admin picks Dark or System in the user menu (kept in the browser)
            ->defaultThemeMode(ThemeMode::Light)
            ->brandLogoHeight('2rem')
            ->favicon(fn (): string => asset('images/gaiacp/mark.svg'))
            // Colours, fonts and a stylesheet come from the admin's theme (App\Themes\Themes, ApplyTheme)
            ->font(fn () => Themes::current()?->themeFont(), provider: fn () => Themes::fontProvider(Themes::current()?->themeFont()))
            ->serifFont(fn () => Themes::current()?->themeSerifFont(), provider: fn () => Themes::fontProvider(Themes::current()?->themeSerifFont()))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => Themes::styles())
            ->globalSearch(GlobalSearchProvider::class)
            ->globalSearchKeyBindings(['mod+k']) // ⌘K on a Mac, Ctrl+K elsewhere (the field's hint says which)
            ->globalSearchFieldKeyBindingSuffix()
            // Client area and New order next to the search
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): string => filament()->auth()->check() ? view('filament.topbar-actions')->render() : '')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages(app(PluginManager::class)->pages())
            // The sidebar's groups, in order (each page and resource names its own)
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('Main'))->collapsible(false),
                NavigationGroup::make()->label(fn (): string => __('Billing'))->collapsible(false),
                NavigationGroup::make()->label(fn (): string => __('Services'))->collapsible(false),
                NavigationGroup::make()->label(fn (): string => __('Support'))->collapsible(false),
                NavigationGroup::make()->label(fn (): string => __('System'))->collapsible(false),
            ])
            // The bell after New order (App\Notifications\AdminAlerts), for admins who keep it on in their profile
            ->databaseNotifications(fn (): bool => (bool) (filament()->auth()->user()?->panel_notifications ?? true), livewireComponent: PanelNotifications::class, position: DatabaseNotificationsPosition::Topbar)
            ->databaseNotificationsPolling('60s')
            // The bell rings once when unread notifications appear: on the first page of the visit, and when more arrive
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => filament()->auth()->check() ? view('filament.bell-ring')->render() : '')
            // Swipe a notification away on a touch screen (the bell's list and pop-ups)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('filament.notification-swipe')->render())
            // The theme says whether the admin's menu is in the top bar or at the foot of the sidebar
            ->userMenu(position: fn () => Themes::userMenuPosition())
            ->defaultAvatarProvider(InitialsAvatar::class)
            // Servers → Log in to control panel (a new tab)
            ->authenticatedRoutes(function () {
                Route::get('servers/{server}/panel-login', ServerPanelLoginController::class)
                    ->whereNumber('server')
                    ->name('server-panel-login');
                Route::get('ticket-files/{attachment}', TicketAttachmentController::class)
                    ->whereNumber('attachment')
                    ->name('ticket-file');
            })
            ->userMenuItems([
                Action::make('settings')
                    ->label(fn (): string => __('Settings')) // the panel is built before the admin's language is set
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => SettingsCluster::getUrl())
                    ->visible(fn (): bool => SettingsCluster::canAccess()),
                Action::make('keyboardShortcuts')
                    ->label(fn (): string => __('Keyboard shortcuts'))
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->alpineClickHandler("\$dispatch('open-keyboard-shortcuts')"),
                Action::make('welcomeTour')
                    ->label(fn (): string => __('Tour'))
                    ->icon(Heroicon::OutlinedMap)
                    ->alpineClickHandler("\$dispatch('open-welcome-tour')")
                    ->visible(fn (): bool => WelcomeTour::enabled()),
            ])
            // Installable as an app, with push notifications (App\Http\Controllers\PwaController, App\Push\WebPush)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.pwa')->render())
            // Links inside texts look like links; ones to other sites open in a new tab
            // Search engines and link previews (App\Support\Seo)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.seo')->render())
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.links')->render())
            // Form buttons that float at the foot of a long form look like the Settings pages' Save bar
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.sticky-actions')->render())
            // No empty toolbar row over a table's search on a phone
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.tables')->render())
            // "g" / "n" sequences and the "?" list (App\Filament\KeyboardShortcuts)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => filament()->auth()->check() ? view('filament.keyboard-shortcuts')->render() : '')
            // "Powered by GaiaCP" on the login page, the copyright line on the others
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => TwoFactorReminder::shows() ? Blade::render('@livewire(\'two-factor-reminder\')') : '')
            // The welcome tour: by itself on the first visit, then from the user menu → Tour (App\Support\WelcomeTour)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => filament()->auth()->check() && WelcomeTour::enabled() ? view('filament.welcome-tour', ['opensNow' => WelcomeTour::opensNow()])->render() : '')
            ->renderHook(PanelsRenderHook::FOOTER, fn (): string => view('filament.footer')->render())
            // The public demo: what it is and when it is put back, and the demo login on the login page (App\Support\Demo)
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => Demo::enabled() ? view('filament.demo.bar')->render() : '')
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => Demo::enabled() ? view('filament.demo.login')->render() : '')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                PrepareInstaller::class, // to /install until GaiaCP is installed
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
            // After the session, so the admin's own language applies (Livewire requests too)
            ->middleware([SetLocale::class, ApplyTheme::class], isPersistent: true)
            // Login, sign up and forgot password answer a banned address with the ban page (LoginSecurity)
            ->middleware([BlockBannedIps::class])
            ->authMiddleware([
                Authenticate::class,
                AlertWhenCronStops::class,
            ]);
    }
}
