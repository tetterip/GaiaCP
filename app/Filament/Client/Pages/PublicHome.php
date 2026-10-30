<?php

namespace App\Filament\Client\Pages;

use App\Actions\ClientOrder;
use App\Actions\ClientSignup;
use App\Filament\Client\Concerns\OrderForm;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\Product;
use App\Support\OrderCatalogue;
use App\Support\PublicArea;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The site root (its route is in ClientPanelProvider, as Filament's home): the public area's home page while Settings →
 * Public area → Home page is on, with the welcome text, the domain search box, what can be ordered by product group with its
 * lowest price, and the way to log in or sign up. Otherwise visitors go to the login, as before; signed-in clients
 * always go to their own Home (Dashboard).
 */
class PublicHome extends Page
{
    use OrderForm;

    protected string $view = 'filament.client.public-home';

    protected Width|string|null $maxContentWidth = Width::SixExtraLarge;

    public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        return route('filament.client.home', $parameters, $isAbsolute);
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        return 'filament.client.home';
    }

    public function mount(): void
    {
        if (filament()->auth()->check()) {
            $this->redirect(Dashboard::getUrl());
        } elseif (! PublicArea::home()) {
            $this->redirect(filament()->getLoginUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return PublicArea::welcomeTitle();
    }

    public function getHeading(): string|Htmlable|null
    {
        return '';
    }

    /**
     * What clients can order, by product group (OrderCatalogue), each product with its icon, lowest price and where to
     * order it.
     *
     * @return list<array{name: string, description: ?string, icon: ?string, url: string, products: list<array{name: string, icon: ?string, sale: bool, popular: bool, badges: list<array{name: string, color: string}>, price: list<string|HtmlString>, url: string}>}>
     */
    public function sections(): array
    {
        if (! config('gaiacp.client_area.orders')) {
            return [];
        }

        return OrderCatalogue::groups()
            ->except(OrderCatalogue::DOMAINS)
            ->map(fn (array $group) => [
                'name' => $group['name'],
                'description' => $group['description'],
                'icon' => $group['icon'],
                'url' => ProductGroupPage::urlFor($group['group']) ?? $this->orderUrl($group['group'] ? $group['key'] : null),
                'products' => $group['products']->map(fn (Product $product) => [
                    'name' => $product->localName(),
                    'icon' => $product->icon ?? $group['icon'],
                    'sale' => $product->onSale(),
                    'popular' => $product->popular,
                    'badges' => $product->badges(),
                    'color' => $product->orderColor(),
                    'price' => $this->fromPrice($product),
                    'url' => $this->orderUrl($group['group'] ? $group['key'] : null, $product),
                ])->values()->all(),
            ])
            ->values()->all();
    }

    /** @return Collection<int, Announcement> the three newest announcements visitors may read, while they may */
    public function news(): Collection
    {
        return PublicArea::announcements() ? Announcement::query()->shown()->limit(3)->get() : new Collection;
    }

    /** Where "Order" goes: the public order page on the group (and product), else the login (or sign up). */
    private function orderUrl(?string $group = null, ?Product $product = null): string
    {
        if (ClientOrder::publicEnabled()) {
            return route('filament.client.cart', array_filter(['group' => $group, 'product' => $product?->id]));
        }

        return ClientSignup::enabled() ? filament()->getRegistrationUrl() : filament()->getLoginUrl();
    }

    /** Whether domains are sold, so Home offers them beside the products. */
    public function sellsDomains(): bool
    {
        return config('gaiacp.client_area.orders') && ClientOrder::sellsDomains();
    }

    public function domainUrl(): string
    {
        return PublicArea::domainSearch() ? SearchDomains::getUrl() : (PublicArea::domainPrices() ? DomainPrices::getUrl() : $this->orderUrl());
    }

    protected function orderClient(): ?Client
    {
        return null;
    }
}
