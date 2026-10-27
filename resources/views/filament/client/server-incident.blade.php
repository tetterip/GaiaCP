@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Service> $services */
    $down = $services->contains(fn ($s) => $s->server->monitor_status === \App\Monitoring\MonitorStatus::Down);
@endphp

<div @class(['gcp-incident', 'is-down' => $down]) role="status">
    <x-filament::icon :icon="$down ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-wrench-screwdriver'" class="gcp-incident-icon" />
    <div>
        <strong>{{ $down ? __('Some of your services are not working right now') : __('Maintenance on some of your services') }}</strong>
        <p>{{ \App\Support\ServerIncidents::message() }}</p>
        <ul>
            @foreach ($services as $service)
                <li>{{ $service->domain ?: $service->label }}</li>
            @endforeach
        </ul>
    </div>
</div>
