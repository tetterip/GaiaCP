<?php

namespace App\Support;

use App\Actions\ClientAreaAccess;
use App\Filament\Client\Pages\Help as ClientHelp;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Services\ServiceResource as ClientServiceResource;
use App\Filament\Client\Resources\Tickets\TicketResource as ClientTicketResource;
use App\Filament\Pages\Help;
use App\Filament\Pages\SetupGuide as SetupGuidePage;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\User;
use Filament\Support\Icons\Heroicon;

/**
 * The welcome tour: a few cards on a user's first visit to a panel (resources/views/filament/welcome-tour), each
 * lighting up the menu item or part of the top bar it is about (on a phone the side menu opens for it). Each card shows only when the user can open what it describes. It opens by itself once per
 * user and panel (`users.admin_tour_seen_at` / `client_tour_seen_at`, set when it opens), and again from the user menu
 * → Tour. The client area's can be turned off (Settings → Client area → Welcome tour); an admin looking as a client
 * never gets it by itself.
 */
class WelcomeTour
{
    /** Whether the tour is offered in the current panel to the user signed in to it. */
    public static function enabled(): bool
    {
        if (! filament()->auth()->user() instanceof User) {
            return false;
        }

        return self::client() ? (bool) config('gaiacp.client_area.welcome_tour') : true;
    }

    /** Whether the tour opens by itself on this page: the first time only, which is noted now. */
    public static function opensNow(): bool
    {
        $user = filament()->auth()->user();
        $column = self::client() ? 'client_tour_seen_at' : 'admin_tour_seen_at';

        if (! self::enabled() || $user->{$column} !== null || (self::client() && ClientAreaAccess::viewing())) {
            return false;
        }

        $user->forceFill([$column => now()])->save();

        return true;
    }

    /** @return list<array{icon: mixed, title: string, text: string, url: string|null, link: string|null, target: string|null}> */
    public static function steps(): array
    {
        return array_values(array_filter(self::client() ? self::clientSteps() : self::adminSteps()));
    }

    /** The last card's button: the setup guide while a super admin has steps left in it, else just Done. @return array{label: string, url: string|null} */
    public static function finish(): array
    {
        if (! self::client() && SetupGuidePage::canAccess() && ! SetupGuide::finished()) {
            return ['label' => __('Open the setup guide'), 'url' => SetupGuidePage::getUrl()];
        }

        return ['label' => __('Done'), 'url' => null];
    }

    private static function client(): bool
    {
        return filament()->getId() === 'client';
    }

    /** @return list<array<string, mixed>|null> */
    private static function adminSteps(): array
    {
        return [
            self::step(Heroicon::OutlinedSparkles, __('Welcome to GaiaCP'), __('A one-minute look at where things are. You can open this tour again from your menu at the top right → Tour.')),
            ClientResource::canAccess() ? self::step(Heroicon::OutlinedUsers, __('Clients and orders'), __('Each client\'s page holds their details, services, payment notices, payments and tickets. Orders placed in the client area wait under Orders until you complete them.'), ClientResource::getUrl(), self::nav(ClientResource::getUrl())) : null,
            ServiceResource::canAccess() ? self::step(Heroicon::OutlinedServerStack, __('Services'), __('Hosting accounts, domains and everything else you bill for, each with its own price, billing cycle and next due date. A hosting account\'s Account menu creates, suspends or ends it on its server.'), ServiceResource::getUrl(), self::nav(ServiceResource::getUrl(), group: true)) : null,
            InvoiceResource::canAccess() ? self::step(Heroicon::OutlinedBanknotes, __('Payment notices and payments'), __('GaiaCP sends a payment notice before each due date and reminders after it. Record a payment and the service moves on to its next due date, and is unsuspended if it was suspended for not paying.'), InvoiceResource::getUrl(), self::nav(InvoiceResource::getUrl())) : null,
            TicketResource::canAccess() ? self::step(Heroicon::OutlinedLifebuoy, __('Support tickets'), __('Questions from clients, from the client area or by email. Answer them here; the client gets your answer by email too.'), TicketResource::getUrl(), self::nav(TicketResource::getUrl())) : null,
            self::step(Heroicon::OutlinedMagnifyingGlass, __('Search, bell and shortcuts'), __('The search at the top finds clients, services, domains and payment notices. The bell lists what needs you: new orders, payments, problems on servers. Press ? on a keyboard for the shortcuts.'), target: '.fi-topbar-end'),
            Help::canAccess() ? self::step(Heroicon::OutlinedQuestionMarkCircle, __('Help'), __('System → Help is the admin guide, chapter by chapter, with a search.'), Help::getUrl(), self::nav(Help::getUrl())) : null,
        ];
    }

    /** @return list<array<string, mixed>|null> */
    private static function clientSteps(): array
    {
        $company = (string) config('gaiacp.company.name');

        return [
            self::step(Heroicon::OutlinedSparkles, filled($company) ? __('Welcome to :company', ['company' => $company]) : __('Welcome'), __('A quick look at what you can do here. You can see it again from your menu at the top right → Tour.')),
            ClientServiceResource::canAccess() ? self::step(Heroicon::OutlinedServerStack, __('Your services'), __('Your hosting, domains and other services, each with its next due date. Open one for its details and what you can change yourself.'), ClientServiceResource::getUrl(), self::nav(ClientServiceResource::getUrl(), group: true)) : null,
            PaymentNoticeResource::canAccess() ? self::step(Heroicon::OutlinedBanknotes, __('Payment notices'), __('Before each due date you get a payment notice by email. It is here too, with how to pay it; your payments are listed under Payments.'), PaymentNoticeResource::getUrl(), self::nav(PaymentNoticeResource::getUrl(), group: true)) : null,
            Order::canAccess() ? self::step(Heroicon::OutlinedShoppingCart, __('Order'), __('Order hosting, a domain or another service whenever you need one.'), Order::getUrl(), self::nav(Order::getUrl())) : null,
            ClientTicketResource::canAccess() ? self::step(Heroicon::OutlinedLifebuoy, __('Support'), __('Open a support ticket and we answer you here and by email.'), ClientTicketResource::getUrl(), self::nav(ClientTicketResource::getUrl())) : null,
            self::step(Heroicon::OutlinedUserCircle, __('Your profile'), __('Keep your details and email address up to date, and protect your login with two-factor authentication.'), filament()->getProfileUrl(), '.fi-user-menu-trigger'),
            ClientHelp::canAccess() ? self::step(Heroicon::OutlinedQuestionMarkCircle, __('Help'), __('The Help page explains each part of the client area.'), ClientHelp::getUrl(), self::nav(ClientHelp::getUrl())) : null,
        ];
    }

    /**
     * @param  string|null  $target  what the card points at: a CSS selector, or `nav:<url>` for that page's menu item
     *                               (`navgroup:<url>` for its whole group); null = the card in the middle
     * @return array{icon: mixed, title: string, text: string, url: string|null, link: string|null, target: string|null}
     */
    private static function step(mixed $icon, string $title, string $text, ?string $url = null, ?string $target = null): array
    {
        return ['icon' => $icon, 'title' => $title, 'text' => $text, 'url' => $url, 'link' => $url === null ? null : __('Open :page', ['page' => $title]), 'target' => $target];
    }

    private static function nav(string $url, bool $group = false): string
    {
        return ($group ? 'navgroup:' : 'nav:').$url;
    }
}
