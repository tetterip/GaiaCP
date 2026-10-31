<?php

namespace App\Providers\Filament;

use App\Actions\ClientAreaAccess;
use App\Actions\ClientSignup;
use App\Filament\Avatars\InitialsAvatar;
use App\Filament\Client\Pages\AnnouncementPage;
use App\Filament\Client\Pages\Announcements;
use App\Filament\Client\Pages\Auth\EditProfile;
use App\Filament\Client\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Client\Pages\Auth\Login;
use App\Filament\Client\Pages\Auth\Register;
use App\Filament\Client\Pages\Auth\RequestPasswordReset;
use App\Filament\Client\Pages\Cart;
use App\Filament\Client\Pages\ContactSupport;
use App\Filament\Client\Pages\Credit;
use App\Filament\Client\Pages\Dashboard;
use App\Filament\Client\Pages\DomainPrices;
use App\Filament\Client\Pages\Downloads;
use App\Filament\Client\Pages\Help;
use App\Filament\Client\Pages\KnowledgeBase;
use App\Filament\Client\Pages\KnowledgeBaseArticle;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Pages\ProductGroupPage;
use App\Filament\Client\Pages\ProductPage;
use App\Filament\Client\Pages\PublicHome;
use App\Filament\Client\Pages\PublicOrder;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Client\Pages\ServerStatus;
use App\Filament\Client\Pages\SignIns;
use App\Filament\Client\Pages\VisitorTicket;
use App\Filament\Client\Pages\WhoisLookup;
use App\Filament\Client\Resources\Domains\DomainResource;
use App\Filament\Client\Resources\Emails\EmailResource;
use App\Filament\Client\Resources\Invoices\InvoiceResource;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Payments\PaymentResource;
use App\Filament\Client\Resources\Quotes\QuoteResource;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Http\Controllers\Client\AnnouncementFeedController;
use App\Http\Controllers\Client\DownloadController;
use App\Http\Controllers\Client\LanguageController;
use App\Http\Controllers\Client\PanelLoginController;
use App\Http\Controllers\Client\StopViewingController;
use App\Http\Controllers\Client\TicketAttachmentController;
use App\Http\Controllers\Client\VisitorTicketFileController;
use App\Http\Middleware\ApplyTheme;
use App\Http\Middleware\BlockBannedIps;
use App\Http\Middleware\ClientAreaMaintenance;
use App\Http\Middleware\PrepareInstaller;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackClientSession;
use App\Livewire\PanelNotifications;
use App\Livewire\TwoFactorReminder;
use App\Plugins\PluginManager;
use App\Support\Branding;
use App\Support\ClientAreaMaintenance as Maintenance;
use App\Support\ClientMenu;
use App\Support\CookieConsent;
use App\Support\Demo;
use App\Support\PublicArea;
use App\Support\ServerIncidents;
use App\Support\WelcomeTour;
use App\Themes\Themes;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\DatabaseNotificationsPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
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

/**
 * The client area, at the site root: clients invited from their client page (App\Actions\ClientAreaAccess) log in
 * here with their own guard ("client"), never into /admin. Only client users of an existing client get in
 * (User::canAccessPanel()) and see only their own records (ScopedToClient), services limited to
 * Service::clientVisible().
 */
class ClientPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('client')
            ->path('')
            ->authGuard('client')
            ->authPasswordBroker('clients')
            ->login(Login::class)
            // Sign up opens only while Settings → Client area lets new clients sign up (Register, Login)
            ->registration(Register::class)
            ->registrationRouteSlug('signup')
            // Only clients who signed up confirm their address (User::hasVerifiedEmail())
            ->emailVerification(EmailVerificationPrompt::class)
            ->passwordReset(RequestPasswordReset::class)
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication(fn (): array => Demo::enabled() ? [] : [ // the demo's accounts stay as they are
                AppAuthentication::make() // named after the panel's brand: the company
                    ->recoverable(),
            ])
            ->brandName(fn () => config('gaiacp.company.name') ?: 'GaiaCP')
            // The company's logo and tab icon (Settings → Company); without a logo the name above is shown
            ->brandLogo(fn () => Branding::url('logo'))
            ->darkModeBrandLogo(fn () => Branding::url('logo-dark'))
            ->brandLogoHeight(fn () => filament()->auth()->check() ? '2rem' : '3rem')
            ->favicon(fn () => Branding::url('favicon'))
            // Colours, fonts and a stylesheet come from Settings → Client area's theme (App\Themes\Themes, ApplyTheme)
            ->font(fn () => Themes::current()?->themeFont(), provider: fn () => Themes::fontProvider(Themes::current()?->themeFont()))
            ->serifFont(fn () => Themes::current()?->themeSerifFont(), provider: fn () => Themes::fontProvider(Themes::current()?->themeSerifFont()))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => Themes::styles())
            // The client area's own views (Home, payment notice, banners…) on the theme's colours, or Filament's without one
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => view('filament.themes.client')->render())
            // Like the admin's sidebar: Home and Order, then Services, Billing and Support
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('Services'))->collapsible(false),
                NavigationGroup::make()->label(fn (): string => __('Billing'))->collapsible(false),
                NavigationGroup::make()->label(fn (): string => __('Support'))->collapsible(false),
            ])
            // A visitor gets the public area's menu (Settings → Public area); a signed-in client the pages' own, as
            // Settings → Client area → Menus arranges them (App\Support\ClientMenu)
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder|bool => filament()->auth()->check()
                ? (ClientMenu::saved(ClientMenu::CLIENT) === null ? true : ClientMenu::build(ClientMenu::CLIENT, $builder))
                : PublicArea::navigation($builder))
            // The cart, with how many items are in it, while clients can order (Cart)
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): string => filament()->auth()->check() && Cart::canAccess() ? view('filament.client.cart-icon')->render() : '')
            // A visitor's language, cart, Log in and Sign up
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): string => filament()->auth()->check() ? '' : view('filament.client.public-topbar')->render())
            // The bell (App\Notifications\ClientAlerts): Settings → Client area → Notifications, and each login's profile.
            // Hidden while an admin looks through the client's login, so nothing is marked read for them.
            ->databaseNotifications(fn (): bool => config('gaiacp.client_area.notifications') && (bool) (filament()->auth()->user()?->panel_notifications ?? true) && ! ClientAreaAccess::viewing(), livewireComponent: PanelNotifications::class, position: DatabaseNotificationsPosition::Topbar)
            ->databaseNotificationsPolling('60s')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => filament()->auth()->check() ? view('filament.bell-ring')->render() : '')
            // Swipe a notification away on a touch screen (the bell's list and pop-ups)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('filament.notification-swipe')->render())
            // The theme says where the client's menu sits: a card at the foot of the sidebar (Gaia, Flat) or the top bar
            ->userMenu(position: fn () => Themes::userMenuPosition())
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->pages([
                Dashboard::class,
                DomainPrices::class,
                ContactSupport::class,
                VisitorTicket::class,
                Order::class,
                SearchDomains::class,
                WhoisLookup::class,
                Downloads::class,
                Cart::class,
                Credit::class,
                Help::class,
                KnowledgeBase::class,
                KnowledgeBaseArticle::class,
                Announcements::class,
                AnnouncementPage::class,
                ServerStatus::class,
                SignIns::class,
            ])
            ->userMenuItems([
                Action::make('signIns')
                    ->label(fn (): string => __('Devices and sign-ins')) // the panel is built before the language is set
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->url(fn (): string => SignIns::getUrl())
                    ->visible(fn (): bool => SignIns::canAccess()),
                Action::make('emails')
                    ->label(fn (): string => __('Email history'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->url(fn (): string => EmailResource::getUrl())
                    ->visible(fn (): bool => EmailResource::canAccess()),
                Action::make('welcomeTour')
                    ->label(fn (): string => __('Tour'))
                    ->icon(Heroicon::OutlinedMap)
                    ->alpineClickHandler("\$dispatch('open-welcome-tour')")
                    ->visible(fn (): bool => WelcomeTour::enabled()),
            ])
            ->resources([
                ServiceResource::class,
                DomainResource::class,
                PaymentNoticeResource::class,
                InvoiceResource::class,
                PaymentResource::class,
                QuoteResource::class,
                TicketResource::class,
                EmailResource::class,
            ])
            ->routes(function () {
                // The public order page, open to visitors while Settings → Client area says so (PublicOrder)
                // The site root: the public home page for visitors while it is on, else the login or the client's Home
                // (in place of Filament's redirect, which it registers only when no route holds the root)
                Route::get('/', PublicHome::class)->name('home');
                Route::get('cart/{group?}', PublicOrder::class)->name('cart');
                // The announcements' RSS feed, while visitors may read them (Settings → Public area)
                Route::get('announcements/feed', AnnouncementFeedController::class)->name('announcements.feed');
                // A visitor's language (the top bar), kept for their session
                Route::get('language/{locale}', LanguageController::class)->name('language');
                // A file of a ticket without a client, by the ticket's secret link (VisitorTicket)
                Route::get('support/ticket/{token}/files/{attachment}', VisitorTicketFileController::class)
                    ->whereNumber('attachment')
                    ->name('support-ticket-file');
                // A file of Support → Downloads, for whoever may have it (visitors too, for those for everyone)
                Route::get('downloads/{download}/file', DownloadController::class)
                    ->whereNumber('download')
                    ->name('download-file');
                app(PluginManager::class)->clientRoutes();
                // A product group's public page (/<slug>), matched last so it never hides another page
                Route::get('{group}', ProductGroupPage::class)
                    ->where('group', '[a-z0-9]+(?:-[a-z0-9]+)*')
                    ->name('product-group')
                    ->fallback();
                // A product's public page (/<group>/<slug>), also last
                Route::get('{group}/{slug}', ProductPage::class)
                    ->where(['group' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
                    ->name('product')
                    ->fallback();
            })
            ->authenticatedRoutes(function () {
                Route::get('services/{service}/panel-login', PanelLoginController::class)
                    ->whereNumber('service')
                    ->name('services.panel-login');
                Route::get('ticket-files/{attachment}', TicketAttachmentController::class)
                    ->whereNumber('attachment')
                    ->name('ticket-file');
                // "Back to admin" on the bar of an admin looking as the client
                Route::get('back-to-admin', StopViewingController::class)->name('stop-viewing');
            })
            // Links inside texts look like links; ones to other sites open in a new tab
            // Search engines and link previews (App\Support\Seo)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.seo')->render())
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.links')->render())
            // Form buttons that float at the foot of a long form look like the Settings pages' Save bar
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.sticky-actions')->render())
            // An admin looking at the client area as the client (Log in as client), with the way back
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => ClientAreaAccess::viewing()
                ? view('filament.client.viewing-as-banner')->render() : '')
            // Admins who still get in during maintenance mode (Settings → Client area) are told clients can't
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => Maintenance::isOn() && Maintenance::lets()
                ? view('filament.client.maintenance-banner')->render() : '')
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => TwoFactorReminder::shows() ? Blade::render('@livewire(\'two-factor-reminder\')') : '')
            // A server of the client's hosting is down or in maintenance (Settings → Client area → Server problems)
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => self::serverIncident())
            // An order chosen on the public order page waits for the login or sign up; else a link to that page
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => view('filament.client.cart-notice', ['signup' => false])->render())
            // Back to the public area's home page from the login and sign up, while it is on
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_START, fn (): string => PublicArea::home() && ! filament()->auth()->check() ? view('filament.client.public-back')->render() : '')
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE, fn (): string => view('filament.client.cart-notice', ['signup' => true])->render())
            // Sign-in plugins' buttons (e.g. Continue with Google); not while finishing a sign up that came from one
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => self::signInButtons())
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER, fn (): string => ClientSignup::pending() === null ? self::signInButtons() : '')
            // The welcome tour: by itself on the first visit, then from the user menu → Tour (App\Support\WelcomeTour)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => filament()->auth()->check() && WelcomeTour::enabled() ? view('filament.welcome-tour', ['opensNow' => WelcomeTour::opensNow()])->render() : '')
            ->renderHook(PanelsRenderHook::FOOTER, fn (): string => view('filament.footer')->render())
            // The public demo: what it is and when it is put back, and the demo login on the login page (App\Support\Demo)
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => Demo::enabled() ? view('filament.demo.bar')->render() : '')
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => Demo::enabled() ? view('filament.demo.login')->render() : '')
            // Cookie banner (Settings → Cookies); its scripts wait for "Accept all"
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => filament()->getId() === 'client' && CookieConsent::enabled() && ! ClientAreaAccess::viewing() ? view('filament.client.cookie-banner')->render() : '')
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
            // Maintenance mode (Settings → Client area): every page, Livewire's requests included, shows the message
            ->middleware([SetLocale::class, ApplyTheme::class, ClientAreaMaintenance::class], isPersistent: true)
            // Signed-in devices and sign-in history (ClientSessions); a browser logged out elsewhere goes to the login
            ->middleware([TrackClientSession::class], isPersistent: true)
            // Login, sign up and forgot password answer a banned address with the ban page (LoginSecurity)
            ->middleware([BlockBannedIps::class])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private static function signInButtons(): string
    {
        $buttons = app(PluginManager::class)->clientLoginButtons();

        return $buttons === [] ? '' : view('filament.client.sign-in-buttons', ['buttons' => $buttons])->render();
    }

    private static function serverIncident(): string
    {
        $services = ServerIncidents::forClient(filament()->auth()->user()?->client);

        return $services->isEmpty() ? '' : view('filament.client.server-incident', ['services' => $services])->render();
    }
}
