@php
    /**
     * A server's hosting accounts against its maximum: a thin line that grows with the share, green, then orange from 70%,
     * red once the dashboard warns that the server is nearly full (ServerCapacity::NEARLY_FULL).
     *
     * @var \App\Models\Server $server
     * @var ?string $caption
     * @var ?string $width the line's width (the servers list keeps it as wide as the uptime marks); the full width without
     */
    $share = $server->accountsShare();
@endphp
@if ($share === null)
    <span style="font-size: .75rem; font-variant-numeric: tabular-nums;">@if ($caption ?? null)<span style="opacity: .7;">{{ $caption }}</span> @endif{{ $server->accountsText() }}</span>
@else
    @php
        $color = $share >= \App\Filament\Widgets\ServerCapacity::NEARLY_FULL ? 'var(--danger-500)' : ($share >= .7 ? 'var(--warning-500)' : 'var(--success-500)');
        $percent = round(min(1, $share) * 100, 2);
    @endphp
    <div class="gx-meter gx-line" style="display: grid; gap: .3rem; min-width: 0;{{ ($width ?? null) ? ' width: '.$width.'; max-width: 100%;' : '' }}">
        <div style="display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; font-size: .75rem; line-height: 1rem;">
            @if ($caption ?? null)
                <span style="opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $caption }}</span>
            @endif
            <span style="font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $server->accountsText() }}</span>
        </div>
        <div role="img" aria-label="{{ __('Accounts: :count', ['count' => $server->accountsText()]) }}" style="height: 6px; border-radius: 999px; background: rgb(127 127 127 / .2); overflow: hidden;">
            <span class="gx-line-fill" style="display: block; height: 100%; width: {{ $percent }}%; border-radius: inherit; background: {{ $color }};"></span>
        </div>
    </div>
@endif
