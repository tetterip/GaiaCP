{{-- App\Filament\Client\Pages\SearchDomains: a search box, then each domain found, free or taken, with its price (styled by filament.themes.client). --}}
<x-filament-panels::page>
    <form wire:submit="search" class="gcp-domain-search">
        <x-filament::input.wrapper prefix-icon="heroicon-o-globe-alt" :valid="! $errors->has('query')" class="gcp-domain-search-input">
            <x-filament::input type="search" wire:model="query" :placeholder="__('Find your domain, e.g. myshop.gr')" autocomplete="off" autofocus />
        </x-filament::input.wrapper>
        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">{{ __('Search') }}</x-filament::button>
    </form>
    @if (\App\Filament\Client\Pages\DomainPrices::canAccess())
        <div class="gcp-cart-link">
            <x-filament::link :href="\App\Filament\Client\Pages\DomainPrices::getUrl()" icon="heroicon-o-tag" size="sm">{{ __('See the prices of every domain ending') }}</x-filament::link>
        </div>
    @endif
    @error('query')
        <p class="gcp-cart-line-error">{{ $message }}</p>
    @enderror
    @if ($widget = $this->captchaWidget())
        {{-- The captcha before the first search (Settings → General → Captcha); its token goes to $captcha. --}}
        <div
            wire:key="domain-search-captcha"
            wire:ignore
            x-data="{
                id: null,
                init() {
                    const widget = @js($widget);
                    if (! document.querySelector(`script[src='${widget.script}']`)) {
                        const script = document.createElement('script');
                        script.src = widget.script;
                        script.async = true;
                        script.defer = true;
                        document.head.appendChild(script);
                    }
                    const render = () => {
                        const api = window[widget.global];
                        if (! api || typeof api.render !== 'function') {
                            return setTimeout(render, 100);
                        }
                        this.id = api.render(this.$refs.box, {
                            ...widget.options,
                            callback: (token) => $wire.set('captcha', token, false),
                            'expired-callback': () => $wire.set('captcha', null, false),
                            'error-callback': () => $wire.set('captcha', null, false),
                        });
                    };
                    render();
                },
                reset() {
                    const api = window[@js($widget['global'])];
                    if (api && this.id !== null) api.reset(this.id);
                },
            }"
            x-on:captcha-reset.window="reset()"
        >
            <div x-ref="box"></div>
        </div>
        @error('captcha')
            <p class="gcp-cart-line-error">{{ $message }}</p>
        @enderror
    @endif

    @if ($results !== [])
        @php($inCart = $this->inCart())
        <x-filament::section wire:loading.class="opacity-50">
            @if ($unsupported)
                <p class="gcp-cart-line-detail">{{ __('We do not register :tld domains. Here is the name with the ones we do.', ['tld' => $unsupported]) }}</p>
            @endif
            <div class="gcp-cart-lines">
                @foreach ($results as $result)
                    @include('filament.client.search-domains-row', ['result' => $result, 'inCart' => $inCart])
                @endforeach
            </div>
            @if ($this->canSuggest() && $suggestions === [])
                <div class="gcp-domain-suggest">
                    <x-filament::button color="gray" size="sm" icon="heroicon-o-sparkles" wire:click="suggest" wire:loading.attr="disabled" wire:target="suggest">{{ __('Suggest other names') }}</x-filament::button>
                </div>
            @endif
            @if ($suggestions !== [])
                <p class="gcp-cart-line-detail gcp-domain-note">{{ __('Other free names, suggested by AI:') }}</p>
                <div class="gcp-cart-lines">
                    @foreach ($suggestions as $result)
                        @include('filament.client.search-domains-row', ['result' => $result, 'inCart' => $inCart])
                    @endforeach
                </div>
            @endif
            @if (collect([...$results, ...$suggestions])->contains('status', 'unknown'))
                <p class="gcp-cart-line-detail gcp-domain-note">{{ __('“Probably available”: the registry could not be asked, and the name has no nameservers. We check it again when we register it.') }}</p>
            @endif
        </x-filament::section>

        @if ($inCart !== [])
            <div>
                <x-filament::button tag="a" :href="$this->cartUrl()" icon="heroicon-o-shopping-cart">{{ __('Go to cart') }}</x-filament::button>
            </div>
        @endif
    @endif
</x-filament-panels::page>
