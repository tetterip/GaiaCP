{{-- One spotlight result of App\Filament\Client\Pages\SearchDomains (the domain typed, or a spotlight TLD of Settings → Domains): a card with its ending large. --}}
@php
    $status = \App\Enums\DomainAvailability::from($result['status']);
    $label = explode('.', $result['domain'])[0];
@endphp
<div @class(['gcp-domain-card', 'is-free' => $status->orderable()]) wire:key="spotlight-{{ $result['domain'] }}">
    <div class="gcp-domain-card-name"><span class="gcp-domain-card-label">{{ $label }}</span><span class="gcp-domain-card-tld">{{ mb_substr($result['domain'], mb_strlen($label)) }}</span></div>
    <x-filament::badge :color="$status->getColor()" class="gcp-domain-status">{{ $status->getLabel() }}</x-filament::badge>
    <div class="gcp-domain-card-end">
        @include('filament.client.search-domains-actions')
    </div>
</div>
