<x-filament-panels::page>
    {{-- No custom theme is compiled, so the report carries its own CSS on Filament's color variables. --}}
    <style>
        .gcp-report-controls { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; }
        .gcp-report-controls label { display: grid; gap: .3rem; font-size: .8125rem; font-weight: 500; color: var(--gray-700); }
        .gcp-report-card { border-radius: .75rem; background: #fff; border: 1px solid var(--gray-200); overflow-x: auto; }
        .gcp-report { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .gcp-report th { padding: .7rem .75rem; text-align: start; font-weight: 600; color: var(--gray-950); background: var(--gray-50); border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
        .gcp-report td { padding: .55rem .75rem; border-bottom: 1px solid var(--gray-100); color: var(--gray-700); white-space: nowrap; }
        .gcp-report tbody tr { transition: background-color .15s; }
        .gcp-report tbody tr:hover { background: var(--gray-50); }
        .gcp-report tr.gcp-muted td { color: var(--gray-400); }
        .gcp-report tfoot td { font-weight: 600; color: var(--gray-950); border-bottom: 0; border-top: 1px solid var(--gray-200); }
        .gcp-report .gcp-end { text-align: end; font-variant-numeric: tabular-nums; }
        .gcp-bar { display: grid; gap: .3rem; justify-items: end; }
        .gcp-bar-track { display: block; width: 6rem; height: .3rem; border-radius: 999px; background: var(--gray-100); overflow: hidden; }
        .gcp-bar-fill { display: block; height: 100%; border-radius: 999px; background: var(--primary-600); transform-origin: left; animation: gcp-grow .5s ease-out both; }
                @keyframes gcp-grow { from { transform: scaleX(0); } }
        @media (prefers-reduced-motion: reduce) { .gcp-bar-fill { animation: none; } }
        .gcp-report-note { font-size: .8125rem; color: var(--gray-500); }
        .gcp-report-empty { padding: 2rem 1rem; text-align: center; color: var(--gray-500); }
        .dark .gcp-report-controls label { color: var(--gray-300); }
        .dark .gcp-report-card { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .dark .gcp-report th { color: #fff; background: rgb(255 255 255 / .03); border-color: rgb(255 255 255 / .1); }
        .dark .gcp-report td { color: var(--gray-300); border-color: rgb(255 255 255 / .05); }
        .dark .gcp-report tbody tr:hover { background: rgb(255 255 255 / .03); }
        .dark .gcp-report tr.gcp-muted td { color: var(--gray-500); }
        .dark .gcp-report tfoot td { color: #fff; border-color: rgb(255 255 255 / .1); }
        .dark .gcp-bar-track { background: rgb(255 255 255 / .08); }
        .dark .gcp-bar-fill { background: var(--primary-400); }
        .dark .gcp-report-note { color: var(--gray-400); }
    </style>

    @php($report = $this->report())

    @if ($controls = $this->controls())
        <div class="gcp-report-controls">@include($controls)</div>
    @endif

    <div class="gcp-report-card" wire:loading.class="opacity-60">
        <table class="gcp-report">
            <thead>
                <tr>
                    @foreach ($report['columns'] as $column)
                        <th @class(['gcp-end' => $column['end'] ?? false])>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($report['rows'] as $row)
                    <tr @class(['gcp-muted' => $row['muted'] ?? false])>
                        @foreach ($row['cells'] as $i => $cell)
                            <td @class(['gcp-end' => $report['columns'][$i]['end'] ?? false])>
                                @if (is_array($cell))
                                    <span class="gcp-bar">
                                        <span>{{ $cell['text'] }}</span>
                                        <span class="gcp-bar-track"><span class="gcp-bar-fill" style="width: {{ round($cell['bar'] * 100, 1) }}%"></span></span>
                                    </span>
                                @else
                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($report['columns']) }}" class="gcp-report-empty">{{ $report['empty'] ?? __('Nothing to show for this period.') }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($report['totals']) && $report['rows'] !== [])
                <tfoot>
                    <tr>
                        @foreach ($report['totals'] as $i => $total)
                            <td @class(['gcp-end' => $report['columns'][$i]['end'] ?? false])>{{ $total }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($note = $this->note())
        <p class="gcp-report-note">{{ $note }}</p>
    @endif

    @if ($after = $this->after())
        @include($after)
    @endif
</x-filament-panels::page>
