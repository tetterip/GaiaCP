<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Client\Pages\Announcements;
use App\Filament\Client\Pages\ContactSupport;
use App\Filament\Client\Pages\DomainPrices;
use App\Filament\Client\Pages\KnowledgeBase;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Client\Pages\ServerStatus;
use App\Filament\Client\Pages\WhoisLookup;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Support\Locales;
use App\Support\PublicArea;
use App\Support\Whois;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The public area (App\Support\PublicArea): the client area's pages visitors see without logging in, each with its own
 * switch. The public order page stays under Settings → Client area → Orders.
 */
class PublicAreaSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeEuropeAfrica;

    protected static string|UnitEnum|null $navigationGroup = 'Client area';

    protected static ?int $navigationSort = 41;

    protected static ?string $title = 'Public area';

    protected static ?string $slug = 'public-area';

    private const TEXT_KEYS = ['public_area.welcome_title', 'public_area.welcome_text', 'public_area.contact_hours', 'public_area.contact_text'];

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('What visitors see before they log in: a home page with your welcome text, the domain search, the domain prices, the knowledge base, announcements, a contact page with a form and your details, and the servers\' status, with a side menu like the client area\'s.');
    }

    public static function keys(): array
    {
        return ['public_area.home', ...self::TEXT_KEYS, 'public_area.domain_search', 'public_area.domain_prices', 'public_area.whois', 'public_area.knowledge_base', 'public_area.announcements', 'public_area.contact', 'public_area.contact_details', 'public_area.contact_fields', 'public_area.contact_map', 'public_area.status'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Home page'))
                    ->description(__('Every part is off until you switch it on. With all of them off, your site root stays the login page.'))
                    ->schema([
                        Toggle::make('public_area.home')
                            ->label('Home page for visitors')
                            ->live()
                            ->helperText(fn () => __('Visitors opening :url see a home page instead of the login: your welcome text, the domain search, the products clients can order with their lowest price, and Log in / Sign up. Clients who are logged in get their own Home as before.', ['url' => url('/')])),
                        Group::make([self::texts()])
                            ->visible(fn (Get $get): bool => (bool) $get('public_area.home')),
                    ]),
                Section::make(__('Domains'))
                    ->schema([
                        Toggle::make('public_area.domain_search')
                            ->label('Domain search for visitors')
                            ->helperText(fn () => __('Visitors search for a domain at :url, with the captcha and limits of the client area\'s search. They add a free one to the cart while the public order page is on (Settings → Client area → Orders); otherwise they see its price.', ['url' => SearchDomains::getUrl(panel: 'client')])),
                        Toggle::make('public_area.domain_prices')
                            ->label('Domain prices page')
                            ->helperText(fn () => __('A page at :url with every domain ending you sell and its price to register, transfer and renew, from Products & domains → Domain pricing. Clients who are logged in see it too, in their currency.', ['url' => DomainPrices::getUrl(panel: 'client')])),
                        Toggle::make('public_area.whois')
                            ->label('WHOIS lookup')
                            ->helperText(fn () => __('Visitors and clients look up a domain name at :url: whether it is registered, its registrar, dates, statuses and nameservers, from the registry (never the owner\'s contact details). It uses the domain search\'s captcha, and one address gets :count lookups a minute.', ['url' => WhoisLookup::getUrl(panel: 'client'), 'count' => Whois::PER_MINUTE])),
                    ]),
                Section::make(__('Support'))
                    ->schema([
                        Toggle::make('public_area.knowledge_base')
                            ->label('Knowledge base for visitors')
                            ->helperText(fn () => __('Visitors read the published articles at :url, except the ones marked Only for clients, which ask them to log in. Needs Settings → Support → Knowledge base on.', ['url' => KnowledgeBase::getUrl(panel: 'client')])),
                        Toggle::make('public_area.announcements')
                            ->label('Announcements for visitors')
                            ->helperText(fn () => __('Visitors read the published announcements at :url, except the ones marked Only for clients, and the latest three on the home page. They are also an RSS feed at :feed. Clients who are logged in always see them (Support → Announcements).', ['url' => Announcements::getUrl(panel: 'client'), 'feed' => route('filament.client.announcements.feed')])),
                    ]),
                Section::make(__('Contact page'))
                    ->schema([
                        Toggle::make('public_area.contact')
                            ->label('Contact form for visitors')
                            ->helperText(fn () => __('Visitors write to you at :url with their name and email address. It opens a ticket without a client (link one on its page) in a department clients see, and its emails carry a secret link where they read and answer it. Settings → General → Captcha can ask for the captcha on it; three messages an hour from one address.', ['url' => ContactSupport::getUrl(panel: 'client')])),
                        Toggle::make('public_area.contact_details')
                            ->label('Your contact details')
                            ->live()
                            ->helperText(__('Your details beside the form, or alone on the page while the form is off. Clients who are logged in find the page in their Support menu, with Open a ticket.')),
                        Group::make([
                            CheckboxList::make('public_area.contact_fields')
                                ->label('Show')
                                ->options(fn () => collect(PublicArea::CONTACT_FIELDS)->mapWithKeys(fn (string $key) => [$key => match ($key) {
                                    'address' => __('Address'),
                                    'phone' => __('Phone'),
                                    'email' => __('Email'),
                                    'website' => __('Website'),
                                    'vat_number' => __('VAT number'),
                                }])->all())
                                ->columns(['default' => 2, 'sm' => 3])
                                ->helperText(__('As filled in under Settings → Company; an empty one is left out.')),
                            Toggle::make('public_area.contact_map')
                                ->label('Open in maps button')
                                ->helperText(__('A button under the address that searches for it on Google Maps (the maps app on a phone). Nothing loads from another site until it is pressed.')),
                            self::localized(fn (string $locale) => [
                                Textarea::make("public_area.contact_hours.{$locale}")
                                    ->label('Opening hours')
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->placeholder(__('Mon–Fri 09:00–17:00'))
                                    ->helperText(Locales::isDefault($locale) ? __('One line each. Empty: not shown.') : __('Empty: the text of the first language.')),
                                Textarea::make("public_area.contact_text.{$locale}")
                                    ->label('Text above the form')
                                    ->rows(3)
                                    ->maxLength(3000)
                                    ->helperText(__('E.g. how soon you answer, or a number for emergencies. Markdown: **bold**, [link](https://…), lists.')),
                            ], 'contact'),
                        ])->visible(fn (Get $get): bool => (bool) $get('public_area.contact_details')),
                    ]),
                Section::make(__('Server status'))
                    ->schema([
                        Toggle::make('public_area.status')
                            ->label('Server status page')
                            ->helperText(fn () => __('A page at :url, for visitors and clients, with the state and uptime of every active server a monitoring service checks. It shows the server\'s name or its Public name, never its hostname or IP address; a server can be left off it on its page (Servers).', ['url' => ServerStatus::getUrl(panel: 'client')])),
                    ]),
            ]);
    }

    /** The welcome title and text, one tab per language. */
    private static function texts(): Tabs|Group
    {
        return self::localized(fn (string $locale) => [
            TextInput::make("public_area.welcome_title.{$locale}")
                ->label('Title')
                ->maxLength(120)
                ->placeholder(fn () => config('gaiacp.company.name') ?: 'GaiaCP')
                ->helperText(Locales::isDefault($locale) ? __('Empty: your company name.') : __('Empty: the text of the first language.')),
            Textarea::make("public_area.welcome_text.{$locale}")
                ->label('Welcome text')
                ->rows(4)
                ->maxLength(3000)
                ->helperText(__('A few lines under the title, e.g. what you offer. Markdown: **bold**, [link](https://…), lists.')),
        ]);
    }

    /** Fields per language: one tab each, or the fields alone with one language. */
    private static function localized(\Closure $fields, string $name = 'texts'): Tabs|Group
    {
        if (! Locales::multiple()) {
            return Group::make($fields(Locales::default()));
        }

        return Tabs::make($name)->tabs(collect(Locales::available())
            ->map(fn (string $name, string $locale) => Tab::make($name)->schema($fields($locale)))
            ->values()->all());
    }

    protected function forStorage(array $values): array
    {
        // A hidden part (its switch off) sends nothing: what was saved for it stays for when it is switched on again
        foreach ([...self::TEXT_KEYS, 'public_area.contact_fields'] as $key) {
            $values[$key] ??= config("gaiacp.{$key}");
        }

        foreach (self::TEXT_KEYS as $key) {
            $values[$key] = array_filter(array_map(fn ($text) => trim((string) $text), (array) ($values[$key] ?? [])), 'filled');
        }

        $values['public_area.home'] = (bool) $values['public_area.home'];
        $values['public_area.domain_search'] = (bool) $values['public_area.domain_search'];
        $values['public_area.domain_prices'] = (bool) $values['public_area.domain_prices'];
        $values['public_area.knowledge_base'] = (bool) $values['public_area.knowledge_base'];
        $values['public_area.announcements'] = (bool) $values['public_area.announcements'];
        $values['public_area.contact'] = (bool) $values['public_area.contact'];
        $values['public_area.contact_details'] = (bool) $values['public_area.contact_details'];
        $values['public_area.contact_fields'] = array_values(array_intersect(PublicArea::CONTACT_FIELDS, (array) ($values['public_area.contact_fields'] ?? [])));
        $values['public_area.contact_map'] = (bool) $values['public_area.contact_map'];
        $values['public_area.status'] = (bool) $values['public_area.status'];

        return $values;
    }
}
