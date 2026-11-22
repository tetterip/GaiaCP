{{-- One domain of App\Filament\Client\Pages\SearchDomains' results or suggestions: free or taken, its price and buttons. --}}
@php($status = \App\Enums\DomainAvailability::from($result['status']))
<div class="gcp-cart-line" wire:key="domain-{{ $result['domain'] }}">
    <div class="gcp-cart-line-text">
        <div class="gcp-cart-line-title">{{ $result['domain'] }}</div>
        <x-filament::badge :color="$status->getColor()" class="gcp-domain-status">{{ $status->getLabel() }}</x-filament::badge>
    </div>
    <div class="gcp-cart-line-end">
        @include('filament.client.search-domains-actions')
    </div>
</div>
