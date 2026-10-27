{{-- The cart in the client area's top bar (ClientPanelProvider): opens the cart, with how many items are in it. --}}
@php
    $count = \App\Actions\ClientOrder::cartCount();
@endphp
<x-filament::icon-button
    tag="a"
    :href="\App\Filament\Client\Pages\Cart::getUrl()"
    icon="heroicon-o-shopping-cart"
    color="gray"
    size="lg"
    :label="__('Cart')"
    :tooltip="__('Cart')"
    :badge="$count > 0 ? $count : null"
    class="gcp-cart-icon"
/>
