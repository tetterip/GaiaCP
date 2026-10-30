<?php

namespace App\Support;

use App\Actions\ClientOrder;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;

/**
 * What clients can order, by product group (Settings → Product groups), for the order pages (OrderForm), the public
 * home page and the public side menu: each group with its products (ClientOrder::products()), the products without a
 * group under "Other" (OTHER), and domain names (DOMAINS) while a TLD is sold. A hidden group is listed only when
 * asked for by its key, as its link does.
 */
final class OrderCatalogue
{
    /** The key of the products without a group. */
    public const OTHER = 'other';

    /** The key of domain names, ordered on their own (ClientOrder::DOMAIN_ONLY). */
    public const DOMAINS = 'domains';

    /** @return Collection<string, array{key: string, name: string, description: ?string, icon: ?string, group: ?ProductGroup, products: Collection<int, Product>}> key => group, in order */
    public static function groups(?string $alsoHidden = null): Collection
    {
        // Within a group the cheapest comes first, as packages usually go (Basic, Business, Pro)
        $products = ClientOrder::products()->load('group')->sortBy(fn (Product $product) => self::monthly($product))->values();
        $groups = ProductGroup::query()->ordered()->get()->toBase()
            ->filter(fn (ProductGroup $group) => ! $group->hidden || $group->slug === $alsoHidden)
            ->mapWithKeys(fn (ProductGroup $group) => [$group->slug => self::entry($group->slug, $group->name(), $group->description(), $group->icon, $group,
                $products->where('product_group_id', $group->id)->values())])
            ->filter(fn (array $entry) => $entry['products']->isNotEmpty());

        $ungrouped = $products->filter(fn (Product $product) => $product->group === null)->values();
        if ($ungrouped->isNotEmpty()) {
            $groups[self::OTHER] = self::entry(self::OTHER, $groups->isEmpty() ? __('Products') : __('Other'), null, 'squares', null, $ungrouped);
        }

        if (ClientOrder::sellsDomains()) {
            $groups[self::DOMAINS] = self::entry(self::DOMAINS, __('Domain names'), __('Register a new domain or transfer one you have elsewhere.'), 'globe', null, collect());
        }

        return $groups;
    }

    /** The group a product is listed in (its key), for a link that names only the product. */
    public static function keyFor(mixed $product): ?string
    {
        if ((string) $product === ClientOrder::DOMAIN_ONLY) {
            return self::DOMAINS;
        }

        $product = ClientOrder::products()->firstWhere('id', (int) $product);

        return $product === null ? null : ($product->group?->slug ?? self::OTHER);
    }

    /** Its lowest price a month in the base currency, to put a group's products in order. */
    private static function monthly(Product $product): float
    {
        return collect($product->cycles())->map(fn ($cycle) => ($product->orderPriceFor($cycle) ?? 0) / max(1, $cycle->months()))->min() ?? 0;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{key: string, name: string, description: ?string, icon: ?string, group: ?ProductGroup, products: Collection<int, Product>}
     */
    private static function entry(string $key, string $name, ?string $description, ?string $icon, ?ProductGroup $group, Collection $products): array
    {
        return compact('key', 'name', 'description', 'icon', 'group', 'products');
    }
}
