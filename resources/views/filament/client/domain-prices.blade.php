{{-- App\Filament\Client\Pages\DomainPrices: every TLD with its prices, filtered as you type (styled by filament.themes.client). --}}
@php
    use App\Filament\Client\Pages\SearchDomains;

    $rows = $this->rows();
@endphp
<x-filament-panels::page>
    <div x-data="{ filter: '' }" class="gcp-domain-prices">
        <div class="gcp-domain-search">
            <x-filament::input.wrapper prefix-icon="heroicon-o-funnel" class="gcp-domain-search-input">
                <x-filament::input type="search" x-model="filter" :placeholder="__('Filter, e.g. .gr')" autocomplete="off" />
            </x-filament::input.wrapper>
            @if (SearchDomains::canAccess())
                <x-filament::button tag="a" :href="SearchDomains::getUrl()" color="gray" icon="heroicon-o-magnifying-glass">{{ __('Search for a domain') }}</x-filament::button>
            @endif
        </div>

        <x-filament::section>
            <div class="gcp-price-table-wrap">
                <table class="gcp-home-list gcp-price-table">
                    <thead>
                        <tr>
                            <th>{{ __('Domain') }}</th>
                            <th class="gcp-end">{{ __('Register') }}</th>
                            <th class="gcp-end">{{ __('Transfer') }}</th>
                            <th class="gcp-end">{{ __('Renew') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr x-show="filter === '' || @js($row['tld']).includes(filter.trim().toLowerCase().replace(/^\.?/, '.'))">
                                <td class="gcp-price-tld">{{ $row['tld'] }}</td>
                                <td class="gcp-end">{{ $row['register'] ?? '—' }}</td>
                                <td class="gcp-end">{{ $row['transfer'] ?? '—' }}</td>
                                <td class="gcp-end">{{ $row['renew'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
        <p class="gcp-home-note">
            {{ __('Prices are per year unless a longer term is shown.') }}
            @if (config('gaiacp.tax.enabled') && ! \App\Support\Tax::inclusive())
                {{ __('Tax is added where it applies.') }}
            @endif
        </p>
    </div>
</x-filament-panels::page>
