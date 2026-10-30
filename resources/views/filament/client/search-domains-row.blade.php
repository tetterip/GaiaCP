{{-- One domain of App\Filament\Client\Pages\SearchDomains' results or suggestions: free or taken, its price and buttons. --}}
@php($status = \App\Enums\DomainAvailability::from($result['status']))
<div class="gcp-cart-line" wire:key="domain-{{ $result['domain'] }}">
    <div class="gcp-cart-line-text">
        <div class="gcp-cart-line-title">{{ $result['domain'] }}</div>
        <x-filament::badge :color="$status->getColor()" class="gcp-domain-status">{{ $status->getLabel() }}</x-filament::badge>
    </div>
    <div class="gcp-cart-line-end">
        @if ($status->orderable())
            @if ($result['price'])
                <span class="gcp-cart-line-amount">@if ($result['was'] ?? null)<s class="gcp-was">{{ $result['was'] }}</s> @endif{{ __(':price for :term', ['price' => $result['price'], 'term' => $result['term']]) }}</span>
            @endif
            @if (! $this->canOrder())
                {{-- A visitor while the public order page is off: the price only --}}
            @elseif (in_array($result['domain'], $inCart, true))
                <x-filament::button tag="a" :href="$this->cartUrl()" color="gray" size="sm" icon="heroicon-o-check">{{ __('In your cart') }}</x-filament::button>
            @else
                <x-filament::button size="sm" icon="heroicon-o-shopping-cart" wire:click="add('{{ $result['domain'] }}')">{{ __('Add to cart') }}</x-filament::button>
                @if ($result['with_ssl'])
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-lock-closed" wire:click="add('{{ $result['domain'] }}', true)">{{ __('With SSL: :price', ['price' => $result['with_ssl']]) }}</x-filament::button>
                @endif
            @endif
        @elseif ($result['transfer'] && $this->canOrder())
            <x-filament::button tag="a" :href="$this->transferUrl($result['domain'])" color="gray" size="sm" icon="heroicon-o-arrows-right-left">{{ __('Mine: transfer it') }}</x-filament::button>
        @endif
    </div>
</div>
