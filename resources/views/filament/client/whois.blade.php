{{-- App\Filament\Client\Pages\WhoisLookup: a domain name box, then what the registry says of it (styled by filament.themes.client). --}}
<x-filament-panels::page>
    <form wire:submit="lookup" class="gcp-domain-search">
        <x-filament::input.wrapper prefix-icon="heroicon-o-globe-alt" :valid="! $errors->has('query')" class="gcp-domain-search-input">
            <x-filament::input type="search" wire:model="query" :placeholder="__('A domain name, e.g. example.com')" autocomplete="off" autofocus />
        </x-filament::input.wrapper>
        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">{{ __('Look up') }}</x-filament::button>
    </form>
    @error('query')
        <p class="gcp-cart-line-error">{{ $message }}</p>
    @enderror
    @if ($widget = $this->captchaWidget())
        @include('filament.client.captcha-box', ['widget' => $widget, 'key' => 'whois-captcha'])
    @endif

    @if ($failed)
        <x-filament::section wire:loading.class="opacity-50">
            <p class="gcp-cart-line-detail">{{ __('The registry of :domain could not be asked right now. Try again later.', ['domain' => $query]) }}</p>
        </x-filament::section>
    @elseif ($result)
        <x-filament::section :heading="$result['domain']" wire:loading.class="opacity-50">
            @if (! $result['registered'])
                <p>{{ __(':domain is not registered.', ['domain' => $result['domain']]) }}</p>
                @if ($url = $this->searchUrl($result['domain']))
                    <div class="gcp-domain-suggest">
                        <x-filament::button tag="a" :href="$url" size="sm" icon="heroicon-o-shopping-cart">{{ __('Register it') }}</x-filament::button>
                    </div>
                @endif
            @elseif ($result['text'] !== null)
                <p class="gcp-cart-line-detail">{{ __('The registry\'s answer, as it gives it:') }}</p>
                <pre class="gcp-whois-text">{{ $result['text'] }}</pre>
            @else
                <dl class="gcp-whois">
                    <dt>{{ __('Status') }}</dt>
                    <dd>{{ __('Registered') }}</dd>
                    @if ($result['registrar'])
                        <dt>{{ __('Registrar') }}</dt>
                        <dd>{{ $result['registrar'] }}</dd>
                    @endif
                    @foreach (['created' => __('Registered on'), 'expires' => __('Expires on'), 'updated' => __('Last changed')] as $key => $label)
                        @if ($result[$key])
                            <dt>{{ $label }}</dt>
                            <dd>{{ \Illuminate\Support\Carbon::parse($result[$key])->format(\App\Support\Region::dateFormat()) }}</dd>
                        @endif
                    @endforeach
                    @if ($result['nameservers'] !== [])
                        <dt>{{ __('Nameservers') }}</dt>
                        <dd>@foreach ($result['nameservers'] as $nameserver)<span>{{ $nameserver }}</span>@endforeach</dd>
                    @endif
                    @if ($result['statuses'] !== [])
                        <dt>{{ __('Registry statuses') }}</dt>
                        <dd>@foreach ($result['statuses'] as $status)<span>{{ $status }}</span>@endforeach</dd>
                    @endif
                    @if ($result['dnssec'] !== null)
                        <dt>DNSSEC</dt>
                        <dd>{{ $result['dnssec'] ? __('Signed') : __('Not signed') }}</dd>
                    @endif
                </dl>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
