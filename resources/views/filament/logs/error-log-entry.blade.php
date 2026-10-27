@include('filament.logs.styles')
<div class="gaiacp-log">
    <div class="gaiacp-log-meta">{{ $entry['environment'] }}</div>
    <pre>{{ $entry['message'] }}@if (filled($entry['details']))

{{ $entry['details'] }}@endif</pre>
</div>
