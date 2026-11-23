{{-- The cart's items (Cart, PublicOrder, CartDrawer; ShowsCartLines): name, what goes with it, the billing cycle and domain
     term to change in place, first payment and Remove. --}}
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
                @foreach ($line['choices'] as $choice)
                    <label class="gcp-cart-line-choice">
                        <span>{{ $choice['label'] }}</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select
                                wire:change="changeLine('{{ $line['key'] }}', '{{ $choice['field'] }}', $event.target.value)"
                                wire:loading.attr="disabled"
                            >
                                @foreach ($choice['options'] as $value => $label)
                                    <option value="{{ $value }}" @selected((string) $value === $choice['value'])>{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @endforeach
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
