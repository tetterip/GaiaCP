{{-- The cart's items (Cart, PublicOrder): name, what goes with it, first payment and Remove. --}}
@php
    $lines = $this->lines();
@endphp
<div class="gcp-cart-lines">
    @forelse ($lines as $line)
        <div class="gcp-cart-line" wire:key="cart-{{ $line['key'] }}">
            <div class="gcp-cart-line-text">
                <div class="gcp-cart-line-title">{{ $line['title'] }}</div>
                @foreach ($line['details'] as $detail)
                    <div class="gcp-cart-line-detail">{{ $detail }}</div>
                @endforeach
                @if ($line['error'])
                    <div class="gcp-cart-line-error">{{ $line['error'] }}</div>
                @endif
            </div>
            <div class="gcp-cart-line-end">
                @if ($line['amount'])
                    <span class="gcp-cart-line-amount">{{ $line['amount'] }}</span>
                @endif
                <x-filament::icon-button
                    icon="heroicon-o-trash"
                    color="gray"
                    size="sm"
                    :label="__('Remove')"
                    :tooltip="__('Remove')"
                    wire:click="removeLine('{{ $line['key'] }}')"
                />
            </div>
        </div>
    @empty
        <div class="gcp-cart-empty">{{ __('Your cart is empty.') }}</div>
    @endforelse
</div>
