<?php

namespace App\Support;

use App\Actions\ClientOrder;
use App\Actions\ClientSignup;
use App\Filament\Client\Pages\AnnouncementPage;
use App\Filament\Client\Pages\Announcements;
use App\Filament\Client\Pages\ContactSupport;
use App\Filament\Client\Pages\DomainPrices;
use App\Filament\Client\Pages\KnowledgeBase;
use App\Filament\Client\Pages\KnowledgeBaseArticle;
use App\Filament\Client\Pages\ProductGroupPage;
use App\Filament\Client\Pages\PublicHome;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Client\Pages\ServerStatus;
use App\Models\Tld;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The public area (Settings → Public area): the client area's pages a visitor sees without logging in, in its look
 * with a side menu (navigation()). Each part has its own switch and is off in a new install; with all of them off the
 * site root stays the login, as before. The public order page (/cart) keeps its switch under Settings → Client area.
 */
final class PublicArea
{
    /** The language a visitor picked in the top bar (SetLocale), kept in their session. */
    public const LOCALE = 'public_area.locale';

    /** Whether visitors get the home page (PublicHome) at the site root instead of the login. */
    public static function home(): bool
    {
        return (bool) config('gaiacp.public_area.home');
    }

    /** Whether visitors can search for a domain (SearchDomains), with a TLD to register. */
    public static function domainSearch(): bool
    {
        return (bool) config('gaiacp.public_area.domain_search') && DomainSearch::tlds() !== [];
    }

    /** Whether the TLD price list (DomainPrices) is on and a TLD has a price to show. */
    public static function domainPrices(): bool
    {
        return (bool) config('gaiacp.public_area.domain_prices') && self::pricedTlds() !== [];
    }

    /** Whether visitors open the knowledge base (it also needs Settings → Support → Knowledge base and an article for them). */
    public static function knowledgeBase(): bool
    {
        return (bool) config('gaiacp.public_area.knowledge_base');
    }

    /** Whether visitors can write to support (ContactSupport), which opens a ticket without a client. */
    public static function contact(): bool
    {
        return (bool) config('gaiacp.public_area.contact');
    }

    /** The Settings → Company lines the contact page can show, in the order it shows them. */
    public const CONTACT_FIELDS = ['address', 'phone', 'email', 'website', 'vat_number'];

    /** Whether the contact page shows the company's details (contactLines(), hours, text), to visitors and clients. */
    public static function contactDetails(): bool
    {
        return (bool) config('gaiacp.public_area.contact_details')
            && (self::contactLines() !== [] || self::contactHours() !== null || self::contactText() !== null);
    }

    /**
     * The company's details picked for the contact page that are filled in under Settings → Company.
     *
     * @return list<array{key: string, label: string, value: string, url: ?string}>
     */
    public static function contactLines(): array
    {
        $picked = (array) config('gaiacp.public_area.contact_fields');
        $labels = ['address' => __('Address'), 'phone' => __('Phone'), 'email' => __('Email'), 'website' => __('Website'), 'vat_number' => __('VAT number')];
        $lines = [];

        foreach (self::CONTACT_FIELDS as $key) {
            $value = trim((string) config("gaiacp.company.{$key}"));
            if (! in_array($key, $picked, true) || $value === '') {
                continue;
            }

            $url = match ($key) {
                'phone' => 'tel:'.preg_replace('/[^\d+]/', '', $value),
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$value : null,
                'website' => preg_match('#^https?://#i', $value) ? $value : 'https://'.$value,
                default => null,
            };

            $lines[] = ['key' => $key, 'label' => $labels[$key], 'value' => $value, 'url' => $url];
        }

        return $lines;
    }

    /** The opening hours (plain lines) in the current language, else the default one's, or null. */
    public static function contactHours(): ?string
    {
        return self::text('contact_hours');
    }

    /** The contact page's text (Markdown) in the current language, else the default one's, or null. */
    public static function contactText(): ?HtmlString
    {
        $text = self::text('contact_text');

        return $text === null ? null : new HtmlString(Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]));
    }

    /** A maps search for the company's address (it opens the maps app on a phone), when the address is shown and the button is on. */
    public static function mapUrl(): ?string
    {
        $address = collect(self::contactLines())->firstWhere('key', 'address');

        if (! $address || ! config('gaiacp.public_area.contact_map')) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(preg_replace('/\s+/', ' ', $address['value']));
    }

    /** Whether visitors read the announcements (those not kept for clients) and their feed. */
    public static function announcements(): bool
    {
        return (bool) config('gaiacp.public_area.announcements');
    }

    /** @return list<Tld> the active TLDs with a registration or transfer price, in alphabetical order */
    public static function pricedTlds(): array
    {
        return Tld::query()->active()->orderBy('tld')->get()
            ->filter(fn (Tld $tld) => $tld->terms('register') !== [] || $tld->terms('transfer') !== [])
            ->values()->all();
    }

    /** The home page's title in the current language, else the default one's, else the company name. */
    public static function welcomeTitle(): string
    {
        return self::text('welcome_title') ?? (config('gaiacp.company.name') ?: 'GaiaCP');
    }

    /** The home page's text (Markdown) in the current language, else the default one's, or null. */
    public static function welcomeText(): ?HtmlString
    {
        $text = self::text('welcome_text');

        return $text === null ? null : new HtmlString(Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]));
    }

    private static function text(string $key): ?string
    {
        $texts = (array) config("gaiacp.public_area.{$key}");

        foreach ([app()->getLocale(), Locales::default()] as $locale) {
            if (filled($text = trim((string) ($texts[$locale] ?? '')))) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The side menu a visitor sees (ClientPanelProvider): the public pages that are on (the order page as a line for each
     * product group, when there are several, to its public page when it has one), then Log in and Sign up.
     * Signed-in clients keep the client area's own menu.
     */
    public static function navigation(NavigationBuilder $builder): NavigationBuilder
    {
        $request = request();
        // The product groups (not domain names: they have their search), while there are several to pick from
        $groups = ClientOrder::publicEnabled() ? OrderCatalogue::groups()->except(OrderCatalogue::DOMAINS) : collect();
        $groups = $groups->count() > 1 && $groups->contains(fn (array $group) => $group['group'] !== null) ? $groups : collect();
        // Without the public order page, the groups that have a public page of their own (ProductGroupPage)
        if (! ClientOrder::publicEnabled()) {
            $groups = OrderCatalogue::groups()->except(OrderCatalogue::DOMAINS)->filter(fn (array $group) => $group['group']?->page);
        }

        return $builder
            ->items(array_values(array_filter([
                self::home() ? NavigationItem::make('home')
                    ->label(__('Home'))
                    ->icon(Heroicon::OutlinedHome)
                    ->isActiveWhen(fn () => $request->routeIs(PublicHome::getRouteName()))
                    ->url(PublicHome::getUrl()) : null,
                ClientOrder::publicEnabled() && $groups->isEmpty() ? NavigationItem::make('order')
                    ->label(__('Order'))
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->isActiveWhen(fn () => $request->routeIs('filament.client.cart'))
                    ->url(route('filament.client.cart')) : null,
                self::domainSearch() ? NavigationItem::make('domainSearch')
                    ->label(__('Domain search'))
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->isActiveWhen(fn () => $request->routeIs(SearchDomains::getRouteName()))
                    ->url(SearchDomains::getUrl()) : null,
                self::domainPrices() ? NavigationItem::make('domainPrices')
                    ->label(__('Domain prices'))
                    ->icon(Heroicon::OutlinedTag)
                    ->isActiveWhen(fn () => $request->routeIs(DomainPrices::getRouteName()))
                    ->url(DomainPrices::getUrl()) : null,
                KnowledgeBase::canAccess() ? NavigationItem::make('knowledgeBase')
                    ->label(__('Knowledge base'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->isActiveWhen(fn () => $request->routeIs(KnowledgeBase::getRouteName(), KnowledgeBaseArticle::getRouteName()))
                    ->url(KnowledgeBase::getUrl()) : null,
                Announcements::canAccess() ? NavigationItem::make('announcements')
                    ->label(__('Announcements'))
                    ->icon(Heroicon::OutlinedMegaphone)
                    ->isActiveWhen(fn () => $request->routeIs(Announcements::getRouteName(), AnnouncementPage::getRouteName()))
                    ->url(Announcements::getUrl()) : null,
                ServerStatus::canAccess() ? NavigationItem::make('status')
                    ->label(__('Server status'))
                    ->icon(Heroicon::OutlinedSignal)
                    ->isActiveWhen(fn () => $request->routeIs(ServerStatus::getRouteName()))
                    ->url(ServerStatus::getUrl()) : null,
                ContactSupport::canAccess() ? NavigationItem::make('contact')
                    ->label(__('Contact us'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->isActiveWhen(fn () => $request->routeIs(ContactSupport::getRouteName()))
                    ->url(ContactSupport::getUrl()) : null,
            ])))
            ->groups([
                // With product groups, a line for each under Order (OrderCatalogue)
                ...($groups->isEmpty() ? [] : [NavigationGroup::make(__('Order'))
                    ->collapsible(false)
                    ->items($groups->map(fn (array $group) => NavigationItem::make('order-'.$group['key'])
                        ->label($group['name'])
                        ->icon(ProductIcons::ICONS[$group['icon'] ?? ''][0] ?? Heroicon::OutlinedShoppingCart)
                        ->isActiveWhen(fn () => $request->routeIs('filament.client.cart', ProductGroupPage::getRouteName()) && $request->route('group') === $group['key'])
                        ->url(ProductGroupPage::urlFor($group['group']) ?? route('filament.client.cart', ['group' => $group['key']])))->values()->all())]),
                NavigationGroup::make(__('Your account'))
                    ->collapsible(false)
                    ->items(array_values(array_filter([
                        NavigationItem::make('login')
                            ->label(__('Log in'))
                            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
                            ->url(filament()->getLoginUrl()),
                        ClientSignup::enabled() ? NavigationItem::make('signup')
                            ->label(__('Sign up'))
                            ->icon(Heroicon::OutlinedUserPlus)
                            ->url(filament()->getRegistrationUrl()) : null,
                    ]))),
            ]);
    }
}
