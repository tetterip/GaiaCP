{{-- The client area's top bar for a visitor (ClientPanelProvider): light / dark mode (clients have it in their menu),
     the language, the cart while the public order page has something in it, and Log in (Sign up is in the side menu). --}}
@php
    use App\Actions\ClientOrder;
    use App\Support\Locales;

    $count = ClientOrder::publicEnabled() ? ClientOrder::cartCount() : 0;
    $languages = Locales::available();
@endphp
<div class="gcp-public-topbar">
    @if (filament()->hasDarkMode() && ! filament()->hasDarkModeForced())
        <x-filament::dropdown placement="bottom-end" teleport class="gcp-public-theme">
            <x-slot name="trigger">
                {{-- The same outlined button as the language next to it --}}
                <x-filament::button color="gray" size="sm" outlined class="gcp-public-theme-btn" :aria-label="trans('filament-panels::layout.actions.theme_switcher.label')" :title="trans('filament-panels::layout.actions.theme_switcher.label')">
                    <x-filament::icon icon="heroicon-o-sun" :size="\Filament\Support\Enums\IconSize::Small" x-show="$store.theme !== 'dark'" />
                    <x-filament::icon icon="heroicon-o-moon" :size="\Filament\Support\Enums\IconSize::Small" x-show="$store.theme === 'dark'" x-cloak />
                </x-filament::button>
            </x-slot>
            <x-filament::dropdown.list>
                <x-filament-panels::theme-switcher />
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
    @if (count($languages) > 1)
        <x-filament::dropdown placement="bottom-end" teleport>
            <x-slot name="trigger">
                <x-filament::button color="gray" size="sm" outlined icon="heroicon-o-language" class="gcp-public-language">
                    {{ strtoupper(app()->getLocale()) }}
                </x-filament::button>
            </x-slot>
            <x-filament::dropdown.list>
                @foreach ($languages as $code => $name)
                    <x-filament::dropdown.list.item tag="a" :href="route('filament.client.language', ['locale' => $code])" :color="$code === app()->getLocale() ? 'primary' : 'gray'">
                        {{ $name }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
    @if ($count > 0)
        <x-filament::icon-button
            tag="a"
            :href="route('filament.client.cart')"
            icon="heroicon-o-shopping-cart"
            color="gray"
            size="lg"
            :label="__('Cart')"
            :tooltip="__('Cart')"
            :badge="$count"
            class="gcp-cart-icon"
        />
    @endif
    <x-filament::button tag="a" :href="filament()->getLoginUrl()" size="sm" icon="heroicon-o-arrow-right-end-on-rectangle">{{ __('Log in') }}</x-filament::button>
</div>
