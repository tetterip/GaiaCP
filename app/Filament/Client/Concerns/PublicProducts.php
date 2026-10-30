<?php

namespace App\Filament\Client\Concerns;

use App\Actions\ClientOrder;
use App\Actions\ClientSignup;
use App\Filament\Client\Pages\Order;
use App\Models\Client;
use App\Models\Product;
use App\Support\OrderCatalogue;

/**
 * For the public pages that sell products (ProductGroupPage, ProductPage), with OrderForm: where Order goes and a
 * product's offer for search engines.
 */
trait PublicProducts
{
    /** Where Order goes: a client's Order page, the public order page, else sign up (or the login). */
    protected function orderUrl(Product $product): string
    {
        $parameters = ['group' => OrderCatalogue::keyFor($product->id) ?? $product->group?->slug, 'product' => $product->id];

        if (filament()->auth()->check()) {
            return Order::getUrl($parameters);
        }

        if (ClientOrder::publicEnabled()) {
            return route('filament.client.cart', $parameters);
        }

        return ClientSignup::enabled() ? filament()->getRegistrationUrl() : filament()->getLoginUrl();
    }

    /** @return array<string, mixed>|null schema.org Offer: its lowest price for a new order, in the visitor's currency */
    protected function offer(Product $product): ?array
    {
        $cycle = $this->cheapestCycle($product);
        $cents = $cycle === null ? null : $product->orderPriceFor($cycle, $this->currency());

        return $cents === null ? null : array_filter([
            '@type' => 'Offer',
            'price' => number_format($cents / 100, 2, '.', ''),
            'priceCurrency' => $this->currency(),
            'availability' => 'https://schema.org/InStock',
            'url' => $this->orderUrl($product),
            'priceValidUntil' => $product->salePriceFor($cycle, $this->currency()) !== null ? $product->sale_ends_on?->toDateString() : null,
        ]);
    }

    protected function orderClient(): ?Client
    {
        return filament()->auth()->user()?->client;
    }
}
