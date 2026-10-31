<?php

namespace App\Support;

use App\Actions\ClientOrder;
use App\Actions\ClientSignup;
use App\Filament\Client\Pages\AnnouncementPage;
use App\Filament\Client\Pages\Announcements;
use App\Filament\Client\Pages\ContactSupport;
use App\Filament\Client\Pages\DomainPrices;
use App\Filament\Client\Pages\Downloads;
use App\Filament\Client\Pages\KnowledgeBase;
use App\Filament\Client\Pages\KnowledgeBaseArticle;
use App\Filament\Client\Pages\ProductGroupPage;
use App\Filament\Client\Pages\PublicHome;
use App\Filament\Client\Pages\SearchDomains;
use App\Filament\Client\Pages\ServerStatus;
use App\Filament\Client\Pages\WhoisLookup;
use App\Models\Tld;
use Closure;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
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

    /** Whether the WHOIS lookup (WhoisLookup) is on, for visitors and clients. */
    public static function whois(): bool
    {
        return (bool) config('gaiacp.public_area.whois');
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

    /** The visitors' side menu, as Settings → Client area → Menus arranges it (ClientMenu). */
    public static function navigation(NavigationBuilder $builder): NavigationBuilder
    {
        return ClientMenu::build(ClientMenu::PUBLIC, $builder);
    }

    /**
     * The visitors' menu as it comes: each entry's key => [name, icon, heading (a key of MENU_HEADINGS or null), its
     * items now (none while its part is off)]. ClientMenu arranges them as the admin saved.
     *
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: Closure(): list<NavigationItem>}>
     */
    public static function menuEntries(): array
    {
        $request = request();
        $item = fn (string $key, string $label, string $icon, string $url, array $routes) => NavigationItem::make($key)
            ->label($label)
            ->icon($icon)
            ->isActiveWhen(fn () => $request->routeIs(...$routes))
            ->url($url);

        return [
            'home' => [__('Home'), 'heroicon-o-home', null, fn () => self::home() ? [$item('home', __('Home'), 'heroicon-o-home', PublicHome::getUrl(), [PublicHome::getRouteName()])] : []],
            'order' => [__('Order'), 'heroicon-o-shopping-cart', null, fn () => ClientOrder::publicEnabled() && self::menuGroups()->isEmpty()
                ? [$item('order', __('Order'), 'heroicon-o-shopping-cart', route('filament.client.cart'), ['filament.client.cart'])] : []],
            'domainSearch' => [__('Domain search'), 'heroicon-o-magnifying-glass', null, fn () => self::domainSearch() ? [$item('domainSearch', __('Domain search'), 'heroicon-o-magnifying-glass', SearchDomains::getUrl(), [SearchDomains::getRouteName()])] : []],
            'domainPrices' => [__('Domain prices'), 'heroicon-o-tag', null, fn () => self::domainPrices() ? [$item('domainPrices', __('Domain prices'), 'heroicon-o-tag', DomainPrices::getUrl(), [DomainPrices::getRouteName()])] : []],
            'whois' => [__('WHOIS'), 'heroicon-o-identification', null, fn () => WhoisLookup::canAccess() ? [$item('whois', __('WHOIS'), 'heroicon-o-identification', WhoisLookup::getUrl(), [WhoisLookup::getRouteName()])] : []],
            'knowledgeBase' => [__('Knowledge base'), 'heroicon-o-book-open', null, fn () => KnowledgeBase::canAccess() ? [$item('knowledgeBase', __('Knowledge base'), 'heroicon-o-book-open', KnowledgeBase::getUrl(), [KnowledgeBase::getRouteName(), KnowledgeBaseArticle::getRouteName()])] : []],
            'downloads' => [__('Downloads'), 'heroicon-o-arrow-down-tray', null, fn () => Downloads::canAccess() ? [$item('downloads', __('Downloads'), 'heroicon-o-arrow-down-tray', Downloads::getUrl(), [Downloads::getRouteName()])] : []],
            'announcements' => [__('Announcements'), 'heroicon-o-megaphone', null, fn () => Announcements::canAccess() ? [$item('announcements', __('Announcements'), 'heroicon-o-megaphone', Announcements::getUrl(), [Announcements::getRouteName(), AnnouncementPage::getRouteName()])] : []],
            'status' => [__('Server status'), 'heroicon-o-signal', null, fn () => ServerStatus::canAccess() ? [$item('status', __('Server status'), 'heroicon-o-signal', ServerStatus::getUrl(), [ServerStatus::getRouteName()])] : []],
            'contact' => [__('Contact us'), 'heroicon-o-chat-bubble-left-right', null, fn () => ContactSupport::canAccess() ? [$item('contact', __('Contact us'), 'heroicon-o-chat-bubble-left-right', ContactSupport::getUrl(), [ContactSupport::getRouteName()])] : []],
            // With product groups, a line for each under Order (OrderCatalogue)
            'productGroups' => [__('Product groups'), 'heroicon-o-squares-2x2', 'order', fn () => self::menuGroups()->map(fn (array $group) => NavigationItem::make('order-'.$group['key'])
                ->label($group['name'])
                ->icon(ProductIcons::ICONS[$group['icon'] ?? ''][0] ?? Heroicon::OutlinedShoppingCart)
                ->isActiveWhen(fn () => $request->routeIs('filament.client.cart', ProductGroupPage::getRouteName()) && $request->route('group') === $group['key'])
                ->url(ProductGroupPage::urlFor($group['group']) ?? route('filament.client.cart', ['group' => $group['key']])))->values()->all()],
            'login' => [__('Log in'), 'heroicon-o-arrow-right-end-on-rectangle', 'account', fn () => [NavigationItem::make('login')
                ->label(__('Log in'))
                ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
                ->url(filament()->getLoginUrl())]],
            'signup' => [__('Sign up'), 'heroicon-o-user-plus', 'account', fn () => ClientSignup::enabled() ? [NavigationItem::make('signup')
                ->label(__('Sign up'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->url(filament()->getRegistrationUrl())] : []],
        ];
    }

    /** @var array<string, string> the visitors' menu headings as they come: key => English name */
    public const MENU_HEADINGS = ['order' => 'Order', 'account' => 'Your account'];

    /** The product groups listed under Order: while there are several to pick from (not domain names: they have their search). */
    private static function menuGroups(): Collection
    {
        $groups = ClientOrder::publicEnabled() ? OrderCatalogue::groups()->except(OrderCatalogue::DOMAINS) : collect();
        $groups = $groups->count() > 1 && $groups->contains(fn (array $group) => $group['group'] !== null) ? $groups : collect();
        // Without the public order page, the groups that have a public page of their own (ProductGroupPage)
        if (! ClientOrder::publicEnabled()) {
            $groups = OrderCatalogue::groups()->except(OrderCatalogue::DOMAINS)->filter(fn (array $group) => $group['group']?->page);
        }

        return $groups;
    }
}
