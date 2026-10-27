@php
    /** @var array{'30d': array<string, mixed>, '24h': array<string, mixed>} $uptime ServiceResource::uptime() */
@endphp
{{-- The admin dashboard's uptime bars (filament.servers.uptime-bar); the coloured squares light up one after the other. --}}
<div class="gcp-server-uptime">
    @include('filament.servers.uptime-bar', ['history' => $uptime['30d'], 'caption' => __('Last 30 days'), 'fill' => true])
    @include('filament.servers.uptime-bar', ['history' => $uptime['24h'], 'caption' => __('Last 24 hours'), 'fill' => true])
</div>
