{{-- On the login, sign up and forgot password pages while the public home page is on (ClientPanelProvider). --}}
<div class="gcp-public-back">
    <x-filament::link :href="\App\Filament\Client\Pages\PublicHome::getUrl()" icon="heroicon-m-arrow-left" size="sm" color="gray">
        {{ __('Home') }}
    </x-filament::link>
</div>
