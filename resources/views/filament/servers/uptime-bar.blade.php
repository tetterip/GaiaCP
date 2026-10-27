@php
    /**
     * @var array{dots: list<array{label: string, status: ?\App\Monitoring\MonitorStatus, uptime: ?float}>, uptime: ?float} $history
     * @var ?string $caption
     */
    $percent = \App\Monitoring\UptimeHistory::percent($history['uptime']);
    // A square per hour (24h) or day (30d), coloured by its worst status.
    $cells = array_map(fn (array $dot) => [
        'color' => match ($dot['status']?->color()) {
            'success' => 'var(--success-500)',
            'danger' => 'var(--danger-500)',
            'warning' => 'var(--warning-500)',
            'gray' => 'var(--gray-400)',
            default => null,
        },
        'title' => $dot['status'] === null
            ? __(':time: not checked', ['time' => $dot['label']])
            : __(':time: :status, :percent up', ['time' => $dot['label'], 'status' => $dot['status']->label(), 'percent' => \App\Monitoring\UptimeHistory::percent($dot['uptime']) ?? '—']),
    ], $history['dots']);
@endphp
<div class="gx-uptime">
    @include('filament.servers.meter', [
        'caption' => $caption ?? null,
        'value' => $percent ?? '—',
        'label' => __('Uptime: :percent', ['percent' => $percent ?? '—']),
        'cells' => $cells,
        'rows' => $rows ?? 1,
        'fill' => $fill ?? false,
    ])
</div>
