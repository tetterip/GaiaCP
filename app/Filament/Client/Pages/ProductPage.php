<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OrderForm;
use App\Filament\Client\Concerns\PublicProducts;
use App\Models\Product;
use App\Support\Seo;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * A product's public page (/<group>/<slug>, the product's Public page section), for visitors and search engines: its
 * name, prices, Order button, what it includes (its group's compare table, a column of its own) and its text, with
 * schema.org Product data in the head (Seo). Without a text in any language it is kept out of search engines
 * (noindex() and the sitemap), as a page that only repeats the order card. Registered after the group's page as a
 * fallback route (ClientPanelProvider); only products clients can order have one.
 */
class ProductPage extends Page
{
    use OrderForm;
    use PublicProducts;

    protected string $view = 'filament.client.product';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public Product $product;

    public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        return route(static::getRouteName(), $parameters, $isAbsolute);
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        return 'filament.client.product';
    }

    /** Its page's address, while it has one: switched on, with a link and in a group. */
    public static function urlFor(?Product $product): ?string
    {
        return $product?->page && filled($product->slug) && $product->group !== null
            ? static::getUrl(['group' => $product->group->slug, 'slug' => $product->slug])
            : null;
    }

    public function mount(string $group, string $slug): void
    {
        $this->product = Product::query()->clientOrderable()->with('group', 'tags')->where('page', true)->where('slug', $slug)
            ->whereHas('group', fn ($query) => $query->where('slug', $group))
            ->firstOrFail();

        abort_if($this->product->cycles() === [], 404);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->product->pageTitle();
    }

    public function getHeading(): string|Htmlable|null
    {
        return '';
    }

    public function metaDescription(): ?string
    {
        return $this->product->pageDescription();
    }

    /** Without a text of its own it stays out of search results. */
    public function noindex(): bool
    {
        return ! $this->product->hasPageText();
    }

    /** @return list<string|HtmlString> its lowest price, as on its card */
    public function price(): array
    {
        return $this->fromPrice($this->product);
    }

    public function orderLink(): string
    {
        return $this->orderUrl($this->product);
    }

    /** Its group's page, while it has one. */
    public function groupLink(): ?string
    {
        return ProductGroupPage::urlFor($this->product->group);
    }

    /** Its column of the compare table (its features, its price per cycle), without the Order row's link. */
    public function compare(): ?array
    {
        $compare = $this->compareTable(['group' => $this->product->group, 'products' => collect([$this->product])]);

        if ($compare !== null) {
            $compare['products'][0]['url'] = $this->orderLink();
        }

        return $compare;
    }

    /** @return list<array<string, mixed>> schema.org Product with its lowest price, and where it sits */
    public function structuredData(): array
    {
        $data = [array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $this->product->localName(),
            'description' => Seo::plain($this->product->pageDescription()),
            'url' => static::urlFor($this->product),
            'category' => $this->product->group->name(),
            'offers' => $this->offer($this->product),
        ])];

        if ($groupLink = $this->groupLink()) {
            $data[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $this->product->group->pageTitle(), 'item' => $groupLink],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $this->product->localName(), 'item' => static::urlFor($this->product)],
                ],
            ];
        }

        return $data;
    }
}
