@php /** @var \App\Models\ModuleLog $entry */ @endphp
@include('filament.logs.styles')
<div class="gaiacp-log">
    <div class="gaiacp-log-meta">
        {{ $entry->created_at->format(\App\Providers\AppServiceProvider::dateFormat().' H:i:s') }}
        · {{ $entry->duration_ms }} ms · {{ $entry->url }}
    </div>

    @if ($entry->error)
        <div class="gaiacp-log-error">{{ $entry->error }}</div>
    @endif

    <div>
        <div class="gaiacp-log-label">Request</div>
        <pre>{{ $entry->request }}</pre>
    </div>

    <div>
        <div class="gaiacp-log-label">Response</div>
        <pre>{{ $entry->response ?? 'No response.' }}</pre>
    </div>
</div>
