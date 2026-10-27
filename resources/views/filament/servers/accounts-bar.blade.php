@php
    /**
     * @var \App\Models\Server $server
     * @var int $squares
     * @var ?string $caption
     */
    $share = $server->accountsShare();
    // Amber from 80%, red at or over the maximum.
    $color = $share === null ? null : ($share >= 1 ? 'var(--danger-500)' : ($share >= .8 ? 'var(--warning-500)' : 'var(--theme-accent, var(--primary-600))'));
    $on = $share === null ? 0 : (int) min($squares, ceil(round($share * $squares, 6)));
    $cells = [];
    for ($i = 0; $i < $squares; $i++) {
        $cells[] = ['color' => $i < $on ? $color : null];
    }
@endphp
@if ($share === null)
    <span style="font-size: .75rem; font-variant-numeric: tabular-nums;">@if ($caption ?? null)<span style="opacity: .7;">{{ $caption }}</span> @endif{{ $server->accountsText() }}</span>
@else
    @include('filament.servers.meter', [
        'caption' => $caption ?? null,
        'value' => $server->accountsText(),
        'label' => __('Accounts: :count', ['count' => $server->accountsText()]),
        'cells' => $cells,
        'rows' => $rows ?? 1,
    ])
@endif
