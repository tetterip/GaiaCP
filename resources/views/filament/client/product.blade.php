{{-- App\Filament\Client\Pages\ProductPage: a product's public page, its name and price with Order, then what it includes
     (its column of the compare table) and its text (styled by filament.themes.client). --}}
@php
    use App\Support\ProductIcons;

    $product = $this->product;
    $text = $product->pageText();
    $description = $product->localDescription();
    $compare = $this->compare();
    $groupLink = $this->groupLink();
@endphp
<x-filament-panels::page>
    @if ($groupLink)
        <nav class="gcp-home-note gcp-crumbs" aria-label="{{ __('Breadcrumb') }}"><a href="{{ $groupLink }}">{{ $product->group->pageTitle() }}</a> / {{ $product->localName() }}</nav>
    @endif

    <section class="gcp-card gcp-public-hero" @if ($product->orderColor()) data-gcp-color="{{ $product->orderColor() }}" @endif>
        <h1 class="gcp-public-title">{!! ProductIcons::svg($product->icon ?? $product->group->icon) !!} {{ $product->pageTitle() }}</h1>
        @include('filament.client.product-badges', ['sale' => $product->onSale(), 'popular' => $product->popular, 'badges' => $product->badges()])
        @if (filled($description))
            <p class="gcp-public-text" style="white-space: pre-line">{{ strip_tags($description) }}</p>
        @endif
        <div class="gcp-public-product-price">
            @foreach ($this->price() as $line)
                <div @class(['gcp-public-price-main' => $loop->first, 'gcp-home-note' => ! $loop->first])>{{ $line }}</div>
            @endforeach
        </div>
        <div class="gcp-public-actions">
            <x-filament::button tag="a" :href="$this->orderLink()" icon="heroicon-o-shopping-cart">{{ __('Order') }}</x-filament::button>
        </div>
    </section>

    @if ($text)
        <section class="gcp-card gcp-public-hero">
            <div class="gcp-public-text">{{ $text }}</div>
        </section>
    @endif

    @if ($compare)
        <section class="gcp-public-group">
            @include('filament.client.compare-products', ['compare' => $compare, 'chosen' => null, 'title' => __('What is included')])
        </section>
    @endif
</x-filament-panels::page>
