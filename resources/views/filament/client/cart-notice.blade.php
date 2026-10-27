{{-- Above the client area's login and sign up forms (ClientPanelProvider): what a visitor put in the cart on the
     public order page (PublicOrder) waits for them to get in; without one, a link to that page while it is on. --}}
@php
    use App\Actions\ClientOrder;

    $cart = ClientOrder::cartCount() > 0 ? ClientOrder::cartSummary() : null;
@endphp
@if ($cart !== null)
    <x-filament::callout
        color="info"
        icon="heroicon-o-shopping-cart"
        :heading="$cart !== [] ? __('Your order: :items', ['items' => implode(', ', $cart)]) : __('Your order is waiting')"
        :description="$signup ? __('Create your account to place it. Already a client? Log in instead.') : __('Log in to place it.')"
    >
        <x-slot name="footer">
            <x-filament::link :href="route('filament.client.cart')" size="sm">
                {{ __('Change your order') }}
            </x-filament::link>
        </x-slot>
    </x-filament::callout>
@elseif (ClientOrder::publicEnabled())
    <div class="gcp-cart-link">
        <x-filament::link :href="route('filament.client.cart')" icon="heroicon-o-shopping-cart" size="sm">
            {{ __('See what you can order') }}
        </x-filament::link>
    </div>
@endif
