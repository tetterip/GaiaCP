{{-- The spotlight TLDs' prices (App\Support\DomainSearch::spotlightPrices()) under a domain search box: SearchDomains and the public home page. --}}
@if ($prices !== [])
    <ul class="gcp-spotlight-prices" aria-label="{{ __('Popular domain endings') }}">
        @foreach ($prices as $spot)
            <li class="gcp-spotlight-price">
                <strong>{{ $spot['tld'] }}</strong>
                <span>@if ($spot['was'])<s class="gcp-was">{{ $spot['was'] }}</s> @endif{{ $spot['price'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
