<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Concerns\OrderForm;
use App\Filament\Client\Concerns\PublicProducts;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\OrderCatalogue;
use App\Support\Seo;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * A product group's public page (/<slug>, Settings → Product groups → Public page), for visitors and search engines:
 * its title, text, products with their prices and Order buttons, the compare table and its questions, with
 * structured data (ItemList of the products, FAQPage) in the head (Seo). Registered as the client area's last route
 * (ClientPanelProvider, a fallback), so a group's link never hides another page; any other address is a 404.
 */
class ProductGroupPage extends Page
{
    use OrderForm;
    use PublicProducts;

    protected string $view = 'filament.client.product-group';

    protected Width|string|null $maxContentWidth = Width::SixExtraLarge;

    public ProductGroup $productGroup;

    public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        return route(static::getRouteName(), $parameters, $isAbsolute);
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        return 'filament.client.product-group';
    }

    /** Its page's address, while it has one. */
    public static function urlFor(?ProductGroup $group): ?string
    {
        return $group?->page ? static::getUrl(['group' => $group->slug]) : null;
    }

    public function mount(string $group): void
    {
        $this->productGroup = ProductGroup::query()->where('page', true)->where('slug', $group)->firstOrFail();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->productGroup->pageTitle();
    }

    public function getHeading(): string|Htmlable|null
    {
        return '';
    }

    /** For search results and link previews (Seo), instead of the site's description. */
    public function metaDescription(): ?string
    {
        return $this->productGroup->pageDescription();
    }

    /**
     * The group's entry in the catalogue, with its products (none while nothing in it can be ordered).
     *
     * @return array{key: string, name: string, description: ?string, icon: ?string, group: ?ProductGroup, products: Collection<int, Product>}
     */
    public function entry(): array
    {
        return OrderCatalogue::groups(alsoHidden: $this->productGroup->slug)->get($this->productGroup->slug)
            ?? ['key' => $this->productGroup->slug, 'name' => $this->productGroup->name(), 'description' => $this->productGroup->description(), 'icon' => $this->productGroup->icon, 'group' => $this->productGroup, 'products' => collect()];
    }

    /** @return list<array{name: string, icon: ?string, sale: bool, popular: bool, badges: list<array{name: string, color: string}>, color: ?string, price: list<string|HtmlString>, url: string}> */
    public function products(): array
    {
        if (! config('gaiacp.client_area.orders')) {
            return [];
        }

        return $this->entry()['products']->map(fn (Product $product) => [
            'name' => $product->localName(),
            'icon' => $product->icon ?? $this->productGroup->icon,
            'sale' => $product->onSale(),
            'popular' => $product->popular,
            'badges' => $product->badges(),
            'color' => $product->orderColor(),
            'price' => $this->fromPrice($product),
            'url' => $this->orderUrl($product),
            'page' => ProductPage::urlFor($product),
        ])->values()->all();
    }

    /** The compare table with an Order link per product, while the group has features. */
    public function compare(): ?array
    {
        if (! config('gaiacp.client_area.orders') || ($compare = $this->compareTable($this->entry())) === null) {
            return null;
        }

        $products = $this->entry()['products']->keyBy(fn (Product $product) => (string) $product->id);
        $compare['products'] = array_map(fn (array $column) => $column + ['url' => $this->orderUrl($products[$column['id']])], $compare['products']);

        return $compare;
    }

    /** schema.org data for search engines: the products with their lowest price, and the questions. @return list<array<string, mixed>> */
    public function structuredData(): array
    {
        $data = [];
        $products = config('gaiacp.client_area.orders') ? $this->entry()['products'] : collect();

        if ($products->isNotEmpty()) {
            $data[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => $this->productGroup->pageTitle(),
                'itemListElement' => $products->values()->map(fn (Product $product, int $index) => array_filter([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => array_filter([
                        '@type' => 'Product',
                        'name' => $product->localName(),
                        'url' => ProductPage::urlFor($product),
                        'description' => Seo::plain($product->localDescription()),
                        'offers' => $this->offer($product),
                    ]),
                ]))->all(),
            ];
        }

        if (($questions = $this->productGroup->questions()) !== []) {
            $data[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn (array $item) => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
                ], $questions),
            ];
        }

        return $data;
    }
}
