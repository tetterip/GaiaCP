{{-- A product's badges: Out of stock (Product::inStock()), Sale while its sale runs (Product::onSale()), Popular, then its visible tags (Settings → Product tags), each in its own colour --}}
@if ($soldOut ?? false)<span class="gcp-popular gcp-sold-out">{{ __('Out of stock') }}</span>@endif
@if ($sale ?? false)<span class="gcp-popular gcp-sale">{{ __('Sale') }}</span>@endif
@if ($popular)<span class="gcp-popular">{{ __('Popular') }}</span>@endif
@foreach ($badges as $badge)<span class="gcp-popular gcp-tag" style="--gcp-tag: {{ $badge['color'] }}">{{ $badge['name'] }}</span>@endforeach
