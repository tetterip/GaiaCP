@php
    /**
     * A server's CPU over the last 24 hours (App\Monitoring\LoadHistory) as a small column chart, so it never looks like
     * the uptime or accounts rows: a column per hour as tall as that hour's average share, in its colour (green, amber,
     * red), over a faint full-height track; the latest reading beside the caption.
     *
     * @var array{now: ?float, load: ?float, cores?: ?int, hours: list<array{label: string, average: ?float, highest: ?float}>} $history
     * @var ?string $caption
     */
    $now = \App\Monitoring\LoadHistory::percent($history['now']);
    $load = $history['load'] === null ? null : \Illuminate\Support\Number::format($history['load'], 2);
    // The share is the load average over the cores, which also counts processes waiting for the disk, so it reads higher
    // than a panel's CPU usage graph; without the cores (cPanel, DirectAdmin, HestiaCP) only the load can be shown.
    $about = match (true) {
        $load === null => null,
        $now === null => __('Load average :load. Enter the processor cores on the server to see it as a share.', ['load' => $load]),
        default => __('Load average :load on :cores cores. It also counts processes waiting for the disk, so it reads higher than a CPU usage graph.', ['load' => $load, 'cores' => $history['cores'] ?? '?']),
    };
    $columns = count($history['hours']);
    // Whole pixels, as the uptime and accounts rows (filament.servers.meter), so the three line up; the pixels left over
    // are spread over the gaps, so the chart reaches the right edge
    $whole = 'repeat('.$columns.', round(down, calc((100% - '.(($columns - 1) * 2).'px) / '.$columns.'), 1px))';
@endphp
<div class="gx-cpu" style="display: grid; gap: .3rem; min-width: 0;" @if ($about) title="{{ $about }}" @endif>
    <div style="display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; font-size: .75rem; line-height: 1rem;">
        @if ($caption ?? null)
            <span style="opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $caption }}</span>
        @endif
        <span style="font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $now ?? ($load === null ? '—' : __('Load :load', ['load' => $load])) }}</span>
    </div>
    <div class="gx-cpu-chart" role="img" aria-label="{{ __('CPU: :percent', ['percent' => $now ?? '—']) }}"
        style="display: grid; grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr)); grid-template-columns: {{ $whole }}; justify-content: space-between; gap: 2px; height: 2.25rem; align-items: end;">
        @foreach ($history['hours'] as $hour)
            @php
                $title = $hour['average'] === null
                    ? __(':time: not read', ['time' => $hour['label']])
                    : __(':time: :average on average, highest :highest', [
                        'time' => $hour['label'],
                        'average' => \App\Monitoring\LoadHistory::percent($hour['average']),
                        'highest' => \App\Monitoring\LoadHistory::percent($hour['highest']),
                    ]);
            @endphp
            <span title="{{ $title }}" style="position: relative; height: 100%; border-radius: 2px; background: rgb(127 127 127 / .12);">
                @if ($hour['average'] !== null)
                    <span class="gx-cpu-fill" style="--gx-i: {{ $loop->index }}; position: absolute; inset: auto 0 0 0; height: {{ max(8, min(100, $hour['average'])) }}%; border-radius: 2px; background: {{ \App\Monitoring\LoadHistory::color($hour['average']) }};"></span>
                @endif
            </span>
        @endforeach
    </div>
</div>
