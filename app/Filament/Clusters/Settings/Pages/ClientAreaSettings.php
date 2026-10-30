<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Ai\Assistant;
use App\Enums\AdminArea;
use App\Filament\Actions\AiTextActions;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Support\ClientAreaMaintenance;
use App\Support\Locales;
use App\Support\ServerIncidents;
use App\Support\TermsOfService;
use App\Themes\Themes;
use BackedEnum;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * What clients can do themselves in the client area (each switch hides that action there), and its maintenance mode
 * (App\Support\ClientAreaMaintenance), which closes it to clients.
 */
class ClientAreaSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Client area';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Client area';

    protected static ?string $slug = 'client-area';

    private const TERMS_KEYS = ['client_area.terms', 'client_area.terms_url', 'client_area.terms_text', 'client_area.terms_version'];

    private const MAINTENANCE_KEYS = ['client_area.maintenance', 'client_area.maintenance_message', 'client_area.maintenance_redirect'];

    private const INCIDENT_KEYS = ['client_area.incident_notice', 'client_area.incident_message'];

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('What clients can do themselves in the client area, the terms of service they accept, links to it in emails, server problem notices and maintenance mode.');
    }

    public static function keys(): array
    {
        return ['client_area.theme', 'client_area.brand_color', 'client_area.domain_nameservers', 'client_area.domain_epp', 'client_area.domain_auto_renew', 'client_area.domain_contacts', 'client_area.domain_child_nameservers', 'client_area.domain_lock', 'client_area.hosting_password', 'client_area.hosting_login', 'client_area.hosting_usage', 'client_area.hosting_uptime', 'client_area.orders', 'client_area.monthly_prices', 'client_area.public_orders', 'client_area.email_links', 'client_area.notifications', 'client_area.email_history', 'client_area.welcome_tour', 'seo.index', 'seo.description', 'client_area.signup', ...self::TERMS_KEYS, ...self::MAINTENANCE_KEYS, ...self::INCIDENT_KEYS];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Appearance'))
                    ->schema([
                        Select::make('client_area.theme')
                            ->label('Client area theme')
                            // The stored theme stays listed while its plugin is off, so the page still saves
                            ->options(fn () => Themes::options() + array_filter([
                                config('gaiacp.client_area.theme') => __(':theme (off)', ['theme' => Str::headline((string) config('gaiacp.client_area.theme'))]),
                            ], fn ($label, $key) => filled($key) && Themes::find((string) $key) === null, ARRAY_FILTER_USE_BOTH))
                            ->selectablePlaceholder(false)
                            ->helperText(__('How the client area, the public order page and sign up look to clients.')),
                        ColorPicker::make('client_area.brand_color')
                            ->label('Brand colour')
                            ->hex()
                            ->nullable()
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->helperText(__('Buttons, links and highlights in the client area, e.g. your logo\'s colour. Leave it empty for the theme\'s own colour. The admin area keeps its own.')),
                    ]),
                Section::make(__('Maintenance mode'))
                    ->description(__('Closes the client area while you work on it, like WHMCS\'s maintenance mode. Clients can\'t open any page, the login included, and see your message instead. The admin area is not affected, and you still see the client area while you are signed in here (with "Log in as client").'))
                    ->schema([
                        Toggle::make('client_area.maintenance')
                            ->label('Maintenance mode')
                            ->helperText(__('Online payments that are already under way are still recorded.')),
                        Textarea::make('client_area.maintenance_message')
                            ->label('Message')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder(fn () => ClientAreaMaintenance::defaultMessage())
                            ->helperText(__('Shown to clients while maintenance mode is on. Left empty, clients see the text shown in the box, in their own language.')),
                        TextInput::make('client_area.maintenance_redirect')
                            ->label('Redirect to')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://')
                            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && ClientAreaMaintenance::pointsHere((string) $value, request())) {
                                    $fail(__('Enter an address on another site: this one would open the maintenance page again.'));
                                }
                            }])
                            ->helperText(__('Optional: send clients to this address (e.g. a status page) instead of showing the message.')),
                    ]),
                Section::make(__('Server problems'))
                    ->description(__('When the monitoring service (Uptime Kuma, UptimeRobot) says a server is down or in maintenance, its clients see a notice at the top of the client area naming their services on it, so they know you are on it. It goes away by itself when the server is up again. Only for servers linked to a monitor (Settings → Servers).'))
                    ->schema([
                        Toggle::make('client_area.incident_notice')
                            ->label('Tell clients about server problems'),
                        AiTextActions::on(Textarea::make('client_area.incident_message')
                            ->label('Message')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder(fn () => ServerIncidents::defaultMessage())
                            ->helperText(__('Shown under the notice\'s title. Left empty, clients see the text shown in the box, in their own language.')), write: [[
                                'name' => 'aiWrite',
                                'label' => __('Write from the servers\' status'),
                                'feature' => 'outage',
                                'ask' => fn (Assistant $ai) => $ai->outageNotice(ServerIncidents::now(), Locales::default()),
                            ]]),
                    ]),
                Section::make(__('Sign up'))
                    ->schema([
                        Toggle::make('client_area.signup')
                            ->label('New clients can sign up')
                            ->helperText(__('A "Sign up" link on the client area\'s login page. New clients fill in their details and a password, then confirm their email address from the email they get before they can log in. You get the New client email (Settings → Automation). An address that is already a client here can\'t sign up again: ask them to use "Forgot password?" or invite them from their client page.')),
                    ]),
                Section::make(__('Terms of Service'))
                    ->description(__('Clients tick "I accept the Terms of Service" before they place an order or sign up. The order (or the new client) keeps when they accepted and which version.'))
                    ->schema([
                        Toggle::make('client_area.terms')
                            ->label('Clients must accept the terms of service')
                            // Checked on Save, not as a required field: the link and the text are typed without a round trip.
                            ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ($value && blank($get('client_area.terms_url')) && blank($get('client_area.terms_text'))) {
                                    $fail(__('Enter a link to the terms or their text.'));
                                }
                            }]),
                        TextInput::make('client_area.terms_url')
                            ->label('Link to the terms')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://')
                            ->helperText(__('A page on your website with the terms. Opens in a new tab.')),
                        Textarea::make('client_area.terms_text')
                            ->label('Text of the terms')
                            ->rows(8)
                            ->maxLength(100000)
                            ->helperText(__('Or write the terms here (Markdown: # for headings, ** for bold). Clients read them in a window without leaving the page. With a link too, the window ends with it.')),
                        TextInput::make('client_area.terms_version')
                            ->label('Version')
                            ->maxLength(50)
                            ->helperText(__('Recorded with every acceptance. When you change the link or the text, it becomes the date and time by itself, unless you write a version of your own here (e.g. 2.0).')),
                    ]),
                Section::make(__('Domains'))
                    ->description(__('Only on the client\'s active domains at a registrar here, not on domains registered elsewhere. Every change is in the activity log.'))
                    ->schema([
                        Toggle::make('client_area.domain_nameservers')
                            ->label('Change nameservers')
                            ->helperText(__('Sent to the registrar at once.')),
                        Toggle::make('client_area.domain_epp')
                            ->label('Get the EPP code')
                            ->helperText(__('The transfer-out code, read from the registrar and shown to the client.')),
                        Toggle::make('client_area.domain_auto_renew')
                            ->label('Turn auto renew on or off')
                            ->helperText(__('Off, the domain gets no more payment notices and ends at its due date, as when you turn it off on the domain.')),
                        Toggle::make('client_area.domain_contacts')
                            ->label('Change contact details')
                            ->helperText(__('The domain\'s owner, administrative, technical and billing contacts at the registrar, read and saved there at once (easy.gr, Enom).')),
                        Toggle::make('client_area.domain_child_nameservers')
                            ->label('Private nameservers')
                            ->helperText(__('Register, change or delete nameservers named after the domain (ns1.their-domain.gr) with their IP address (easy.gr, Enom).')),
                        Toggle::make('client_area.domain_lock')
                            ->label('Registrar lock')
                            ->helperText(__('Lock or unlock the domain against transfers to another provider (Enom; easy.gr has no lock to change).')),
                    ]),
                Section::make(__('Hosting'))
                    ->description(__('Only on the client\'s active hosting accounts on a server. Every change and login link is in the activity log.'))
                    ->schema([
                        Toggle::make('client_area.hosting_login')
                            ->label('Log in to cPanel / Plesk')
                            ->helperText(__('A button that opens the control panel already signed in, without the panel password. On Plesk, only for subscriptions a Plesk customer owns. HestiaCP and DirectAdmin have no such link: there it opens the login page.')),
                        Toggle::make('client_area.hosting_usage')
                            ->label('Show usage')
                            ->helperText(__('Disk space and this month\'s bandwidth against the package\'s limits, read from the server. Read only.')),
                        Toggle::make('client_area.hosting_uptime')
                            ->label('Show the server\'s uptime')
                            ->helperText(__('The uptime of the server the account is on, for the last 24 hours and 30 days, from its monitor (Settings → Servers). Only for servers linked to a monitor; the server\'s name is not shown.')),
                        Toggle::make('client_area.hosting_password')
                            ->label('Change password')
                            ->helperText(__('Sets a new password on the server: the cPanel account, or the FTP / SSH user of the Plesk subscription.')),
                    ]),
                Section::make(__('Notifications'))
                    ->schema([
                        Toggle::make('client_area.notifications')
                            ->label('Notifications bell')
                            ->helperText(__('A bell in the client area\'s top bar tells clients about new payment notices and reminders, payments received, services set up, suspended or ended, domains about to renew, answers to their tickets and to their requests. Each client can turn it off in their profile.')),
                        Toggle::make('client_area.email_history')
                            ->label('Email history')
                            ->helperText(__('In the client\'s menu: the emails sent to them, for as long as Settings → Logging keeps the email log. Emails with a password or a sign-in link are listed without their text.')),
                        Toggle::make('client_area.welcome_tour')
                            ->label('Welcome tour')
                            ->helperText(__('A short tour of the client area the first time a client logs in: their services, payment notices, ordering, support and profile. They can open it again from their menu → Tour.')),
                    ]),
                Section::make(__('Search engines'))
                    ->description(__('Pages a client sees once signed in, and /admin, are never shown in search results.'))
                    ->schema([
                        Toggle::make('seo.index')
                            ->label('Let search engines show the login, sign up and order pages')
                            ->helperText(fn (): string => __('Also the public area\'s pages, each in every language. Search engines find them in :url.', ['url' => route('sitemap')])),
                        Textarea::make('seo.description')
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(300)
                            ->helperText(__('One or two sentences about your business, shown in search results and in link previews (Facebook, Viber, WhatsApp…) with your company name and logo.')),
                    ]),
                Section::make(__('Emails'))
                    ->schema([
                        Toggle::make('client_area.email_links')
                            ->label('Links in emails')
                            ->helperText(__('Client emails end with a "View in the client area" button that opens the payment notice, service or domain they are about. Only for clients who can log in.')),
                    ]),
                Section::make(__('Orders'))
                    ->schema([
                        Toggle::make('client_area.orders')
                            ->label('Clients can order')
                            ->helperText(__('An Order page with the products shown in the client area that are not hidden or retired, and domains a registrar here sells. Orders go through the same steps as your New order: the payment notice is emailed at once and Auto setup decides when each item is set up; domains are registered or transferred only when you complete the order.')),
                        Toggle::make('client_area.monthly_prices')
                            ->label('Show prices per month')
                            ->helperText(__('Products on the order pages show their price per month, with what is billed each cycle under it (e.g. €16.67 / month, billed €400.00 every 2 years). Off, they show the whole price of each billing cycle. What clients pay does not change.')),
                        Toggle::make('client_area.public_orders')
                            ->label('Public order page')
                            ->helperText(fn () => __('Visitors choose a product and its domain at :url without logging in, then sign up (or log in) and place the order on the Order page. Link to it from your website; :url?product=<id> pre-selects a product. Needs "Clients can order" on; with sign up off, only clients who can already log in can finish an order.', ['url' => route('filament.client.cart')])),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values = $this->withTermsVersion($values);
        $values['client_area.brand_color'] = filled($values['client_area.brand_color'] ?? null) ? strtolower((string) $values['client_area.brand_color']) : null;

        foreach ($values as $key => $value) {
            if (! in_array($key, ['client_area.theme', 'client_area.brand_color', 'client_area.maintenance_message', 'client_area.maintenance_redirect', 'client_area.incident_message', 'client_area.terms_url', 'client_area.terms_text', 'client_area.terms_version', 'seo.description'], true)) {
                $values[$key] = (bool) $value;
            }
        }

        return $values;
    }

    /**
     * Terms whose link or text changed get a new version (the date and time) unless the admin changed the version too;
     * terms with none get one.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withTermsVersion(array $values): array
    {
        $terms = fn (array $source) => [trim((string) ($source['client_area.terms_url'] ?? '')), trim((string) ($source['client_area.terms_text'] ?? ''))];
        $current = [
            'client_area.terms_url' => config('gaiacp.client_area.terms_url'),
            'client_area.terms_text' => config('gaiacp.client_area.terms_text'),
        ];
        $version = trim((string) ($values['client_area.terms_version'] ?? ''));
        $hasTerms = $terms($values) !== ['', ''];

        if ($hasTerms && ($version === '' || ($terms($values) !== $terms($current) && $version === trim((string) config('gaiacp.client_area.terms_version'))))) {
            $values['client_area.terms_version'] = TermsOfService::newVersion();
        }

        return $values;
    }
}
