<?php

namespace App\Support;

use App\Actions\ClientOrder;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The products a client can order (ClientOrder::products()), read once per request (a scoped binding): the client
 * area's menu, home page and Order form ask for them many times while one page is built. Saving or deleting a product,
 * attaching a tag and taking or giving back stock (PlaceOrder, CancelOrder) forget it.
 */
final class OrderableProducts
{
    /** @var Collection<int, Product>|null */
    private ?Collection $products = null;

    /** @param  callable(): Collection<int, Product>  $load */
    public static function remember(callable $load): Collection
    {
        $self = app(self::class);

        return $self->products ??= $load();
    }

    public static function flush(): void
    {
        app(self::class)->products = null;
    }
}
