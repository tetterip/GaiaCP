<?php

namespace App\Livewire;

use App\Actions\ClientOrder;
use App\Filament\Client\Concerns\ShowsCartLines;
use App\Filament\Client\Pages\Cart;
use App\Models\Client;
use App\Support\Currencies;
use App\Support\Tax;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The cart in the client area's top bar: the cart icon with how many items are in it opens a panel from the side with
 * the items (cycle and term changed in place, Remove), the first payment and Checkout (the Cart page, for the notes,
 * coupon and terms). It opens on its own when something is added (`open-cart`), so the client keeps choosing where
 * they are.
 */
class CartDrawer extends Component
{
    use ShowsCartLines;

    public const MODAL = 'gcp-cart-drawer';

    protected function cartClient(): ?Client
    {
        return filament()->auth()->user()?->client;
    }

    public function count(): int
    {
        return ClientOrder::cartCount();
    }

    /** "First payment: €X" for the whole cart, with tax where it applies (before a coupon, entered at checkout). */
    public function total(): ?string
    {
        $client = $this->cartClient();

        try {
            $items = app(ClientOrder::class)->cartItems(ClientOrder::cart(), $client);
        } catch (ValidationException) {
            return null;
        }

        $amounts = collect($items)->map(fn (array $item) => $item['first_payment_cents'] ?? $item['price_cents']);

        return __('First payment: :amount', ['amount' => Tax::describe(Tax::totals($client, $amounts), Currencies::forClient($client))]);
    }

    public function checkoutUrl(): string
    {
        return Cart::getUrl();
    }

    /** Something was added or changed on the page: shown here at the next render. */
    #[On('cart-updated')]
    public function refreshCart(): void {}

    #[On('open-cart')]
    public function open(): void
    {
        $this->dispatch('open-modal', id: self::MODAL);
    }

    public function render()
    {
        return view('livewire.cart-drawer');
    }
}
