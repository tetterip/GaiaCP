{{-- App\Filament\Client\Pages\ProductGroupPage: a product group's public page, its title and text, its products as on the
     public home page, the compare table with Order links and its questions (styled by filament.themes.client). --}}
@php
    use App\Support\ProductIcons;
    use Illuminate\Support\Str;

    $text = $this->productGroup->pageText();
    $products = $this->products();
    $compare = $this->compare();
    $questions = $this->productGroup->questions();
@endphp
<x-filament-panels::page>
    <section class="gcp-card gcp-public-hero">
        <h1 class="gcp-public-title">{!! ProductIcons::svg($this->productGroup->icon) !!} {{ $this->productGroup->pageTitle() }}</h1>
        @if ($text)
            <div class="gcp-public-text">{{ $text }}</div>
        @elseif (filled($this->productGroup->description()))
            <p class="gcp-public-text">{{ $this->productGroup->description() }}</p>
        @endif
    </section>

    @if ($products !== [])
        <section class="gcp-public-group">
            <div class="gcp-public-cards">
                @foreach ($products as $product)
                    <div class="gcp-card gcp-public-product" @if ($product['color']) data-gcp-color="{{ $product['color'] }}" @endif>
                        <h2 class="gcp-public-product-name">{!! ProductIcons::svg($product['icon']) !!}@if ($product['page'])<a href="{{ $product['page'] }}">{{ $product['name'] }}</a>@else<span>{{ $product['name'] }}</span>@endif@include('filament.client.product-badges', ['sale' => $product['sale'], 'popular' => $product['popular'], 'badges' => $product['badges']])</h2>
                        <div class="gcp-public-product-price">
                            @foreach ($product['price'] as $line)
                                <div @class(['gcp-public-price-main' => $loop->first, 'gcp-home-note' => ! $loop->first])>{{ $line }}</div>
                            @endforeach
                        </div>
                        <x-filament::button tag="a" :href="$product['url']" icon="heroicon-o-shopping-cart">{{ __('Order') }}</x-filament::button>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($compare)
        <section class="gcp-public-group">
            @include('filament.client.compare-products', ['compare' => $compare, 'chosen' => null])
        </section>
    @endif

    @if ($questions !== [])
        <section class="gcp-public-group">
            <h2 class="gcp-public-group-title">{!! ProductIcons::svg('chat') !!}{{ __('Questions') }}</h2>
            <div class="gcp-card gcp-faq">
                @foreach ($questions as $item)
                    <details>
                        <summary>{{ $item['question'] }}</summary>
                        <div class="gcp-public-text">{!! Str::markdown($item['answer'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                    </details>
                @endforeach
            </div>
        </section>
    @endif
</x-filament-panels::page>
