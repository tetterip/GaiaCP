{{-- The top bar's cart (App\Livewire\CartDrawer): the icon with the count opens the items in a panel from the side. --}}
<div class="gcp-cart-drawer">
    <x-filament::modal :id="\App\Livewire\CartDrawer::MODAL" slide-over width="md" :heading="__('Your cart')">
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-shopping-cart"
                color="gray"
                size="lg"
                :label="__('Cart')"
                :tooltip="__('Cart')"
                :badge="$this->count() > 0 ? $this->count() : null"
                class="gcp-cart-icon"
            />
        </x-slot>

        @include('filament.client.cart-lines')

        @if ($this->count() > 0)
            <x-slot name="footer">
                @if ($total = $this->total())
                    <div class="gcp-cart-drawer-total">{{ $total }}</div>
                @endif
                <x-filament::button tag="a" :href="$this->checkoutUrl()" icon="heroicon-o-arrow-right" icon-position="after" class="gcp-cart-drawer-checkout">
                    {{ __('Checkout') }}
                </x-filament::button>
            </x-slot>
        @endif
    </x-filament::modal>
</div>
