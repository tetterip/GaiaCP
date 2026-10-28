{{-- App\Filament\Client\Pages\PublicHome: the welcome card with the domain search, then what can be ordered by product
     group (styled by filament.themes.client). --}}
@php
    use App\Filament\Client\Pages\AnnouncementPage;
    use App\Filament\Client\Pages\Announcements;
    use App\Filament\Client\Pages\DomainPrices;
    use App\Filament\Client\Pages\SearchDomains;
    use App\Support\ProductIcons;
    use App\Support\PublicArea;

    $text = PublicArea::welcomeText();
    $sections = $this->sections();
    $news = $this->news();
@endphp
<x-filament-panels::page>
    <section class="gcp-card gcp-public-hero">
        <h1 class="gcp-public-title">{{ PublicArea::welcomeTitle() }}</h1>
        @if ($text)
            <div class="gcp-public-text">{{ $text }}</div>
        @endif

        @if (PublicArea::domainSearch())
            <form method="get" action="{{ SearchDomains::getUrl() }}" class="gcp-domain-search">
                <x-filament::input.wrapper prefix-icon="heroicon-o-globe-alt" class="gcp-domain-search-input">
                    <x-filament::input type="search" name="q" :placeholder="__('Find your domain, e.g. myshop.gr')" autocomplete="off" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">{{ __('Search') }}</x-filament::button>
            </form>
        @endif
    </section>

    @foreach ($sections as $group)
        <section class="gcp-public-group">
            <h2 class="gcp-public-group-title">{!! ProductIcons::svg($group['icon']) !!}<a href="{{ $group['url'] }}">{{ $group['name'] }}</a></h2>
            @if (filled($group['description']))
                <p class="gcp-home-note">{{ $group['description'] }}</p>
            @endif
            <div class="gcp-public-cards">
                @foreach ($group['products'] as $product)
                    <div class="gcp-card gcp-public-product">
                        <div class="gcp-public-product-name">{!! ProductIcons::svg($product['icon']) !!}<span>{{ $product['name'] }}</span>@include('filament.client.product-badges', ['sale' => $product['sale'], 'popular' => $product['popular'], 'badges' => $product['badges']])</div>
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
    @endforeach

    @if ($this->sellsDomains())
        <section class="gcp-public-group">
            <h2 class="gcp-public-group-title">{!! ProductIcons::svg('globe') !!}{{ __('Domain names') }}</h2>
            <div class="gcp-card gcp-public-domains">
                <p>{{ __('Register a new domain or transfer one you have elsewhere.') }}</p>
                <div class="gcp-public-actions">
                    @if (PublicArea::domainSearch())
                        <x-filament::button tag="a" :href="SearchDomains::getUrl()" icon="heroicon-o-magnifying-glass">{{ __('Search for a domain') }}</x-filament::button>
                    @endif
                    @if (PublicArea::domainPrices())
                        <x-filament::button tag="a" :href="DomainPrices::getUrl()" color="gray" icon="heroicon-o-tag">{{ __('Domain prices') }}</x-filament::button>
                    @endif
                    @unless (PublicArea::domainSearch() || PublicArea::domainPrices())
                        <x-filament::button tag="a" :href="$this->domainUrl()" icon="heroicon-o-shopping-cart">{{ __('Order') }}</x-filament::button>
                    @endunless
                </div>
            </div>
        </section>
    @endif

    @if ($news->isNotEmpty())
        <section class="gcp-public-group">
            <h2 class="gcp-public-group-title">{!! ProductIcons::svg('chat') !!}<a href="{{ Announcements::getUrl() }}">{{ __('News') }}</a></h2>
            <div class="gcp-public-cards">
                @foreach ($news as $announcement)
                    <div class="gcp-card gcp-public-product">
                        <a class="gcp-news-title" href="{{ AnnouncementPage::getUrl(['slug' => $announcement->slug]) }}">{{ $announcement->title() }}</a>
                        <p class="gcp-home-note">{{ $announcement->published_at->timezone(config('app.timezone'))->format(\App\Providers\AppServiceProvider::dateFormat()) }}</p>
                        <p class="gcp-news-excerpt">{{ $announcement->excerpt(160) }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-filament-panels::page>
