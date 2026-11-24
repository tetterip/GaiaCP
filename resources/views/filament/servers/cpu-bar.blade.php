@php
    /**
     * A server's CPU over the last 24 hours (App\Monitoring\LoadHistory): a square per hour in the colour of its average
     * share, and the latest reading beside the caption.
     *
     * @var array{now: ?float, load: ?float, hours: list<array{label: string, average: ?float, highest: ?float}>} $history
     * @var ?string $caption
     */
    $now = \App\Monitoring\LoadHistory::percent($history['now']);
    $cells = array_map(fn (array $hour) => [
        'color' => \App\Monitoring\LoadHistory::color($hour['average']),
        'title' => $hour['average'] === null
            ? __(':time: not read', ['time' => $hour['label']])
            : __(':time: :average on average, highest :highest', [
                'time' => $hour['label'],
                'average' => \App\Monitoring\LoadHistory::percent($hour['average']),
                'highest' => \App\Monitoring\LoadHistory::percent($hour['highest']),
            ]),
    ], $history['hours']);
@endphp
<div class="gx-cpu" @if ($history['load'] !== null) title="{{ __('Load average: :load', ['load' => \Illuminate\Support\Number::format($history['load'], 2)]) }}" @endif>
    @include('filament.servers.meter', [
        'caption' => $caption ?? null,
        'value' => $now ?? '—',
        'label' => __('CPU: :percent', ['percent' => $now ?? '—']),
        'cells' => $cells,
        'rows' => $rows ?? 1,
        'fill' => $fill ?? false,
    ])
</div>
