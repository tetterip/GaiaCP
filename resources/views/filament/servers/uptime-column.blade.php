@php
    /** @var \App\Models\Server $record */
    $record = $getRecord();
    $range = $getLivewire()->uptimeRange ?? '24h';
@endphp
<div class="fi-ta-text" style="width: 10rem; padding-block: .75rem; padding-inline: .75rem;">
    @if ($record->monitor_module)
        @include('filament.servers.uptime-bar', ['history' => \App\Monitoring\UptimeHistory::for($record, $range), 'rows' => 2])
    @endif
</div>
