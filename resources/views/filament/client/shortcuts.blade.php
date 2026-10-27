{{-- The hosting page's Quick shortcuts (HostingSelfService::shortcuts()): each opens the panel page already signed in,
     through the panel login with ?to=; styled by filament.themes.client. --}}
@php
    $record = $getRecord();
@endphp
<div class="gcp-shortcuts">
    @foreach (\App\Filament\Client\Actions\HostingSelfService::shortcuts($record) as $key => $shortcut)
        <a class="gcp-shortcut" href="{{ route('filament.client.services.panel-login', ['service' => $record->id, 'to' => $key]) }}" target="_blank" rel="noopener">
            <x-filament::icon :icon="$shortcut['icon']" class="gcp-shortcut-icon" />
            <span>{{ $shortcut['label'] }}</span>
        </a>
    @endforeach
</div>
