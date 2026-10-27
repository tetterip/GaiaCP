{{-- System health → Biggest tables (App\Support\Health\TableSizes), with a bar against the biggest and which ones backups leave empty. --}}
@php
    /**
     * @var \Illuminate\Support\Collection<int, array{table: string, rows: int, bytes: int|null}> $tables
     * @var list<string> $emptyInBackups
     */
    $max = max(1, (int) $tables->max('bytes'));
@endphp
@if ($tables->isEmpty())
    <p style="opacity: .8">{{ __('The database does not say how big its tables are.') }}</p>
@else
    <div style="overflow-x: auto">
        <table style="width: 100%; border-collapse: collapse; font-size: .875rem">
            <thead>
                <tr style="text-align: start; border-bottom: 1px solid rgb(127 127 127 / .25)">
                    <th style="padding: .4rem .5rem .4rem 0; text-align: start; font-weight: 600">{{ __('Table') }}</th>
                    <th style="padding: .4rem .5rem; text-align: end; font-weight: 600">{{ __('Rows') }}</th>
                    <th style="padding: .4rem 0 .4rem .5rem; text-align: end; font-weight: 600">{{ __('Size') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tables as $row)
                    <tr style="border-bottom: 1px solid rgb(127 127 127 / .12)">
                        <td style="padding: .45rem .5rem .45rem 0">
                            <code>{{ $row['table'] }}</code>
                            @if (in_array($row['table'], $emptyInBackups, true))
                                <span style="opacity: .65; font-size: .75rem; white-space: nowrap">· {{ __('left out of backups') }}</span>
                            @endif
                        </td>
                        <td style="padding: .45rem .5rem; text-align: end; font-variant-numeric: tabular-nums">{{ \Illuminate\Support\Number::format($row['rows']) }}</td>
                        <td style="padding: .45rem 0 .45rem .5rem; text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums">
                            @if ($row['bytes'] !== null)
                                <span style="display: inline-block; vertical-align: middle; width: 4rem; height: .3rem; margin-inline-end: .5rem; border-radius: 999px; background: rgb(127 127 127 / .2); overflow: hidden">
                                    <span style="display: block; height: 100%; width: {{ max(2, round($row['bytes'] / $max * 100)) }}%; background: var(--primary-500)"></span>
                                </span>{{ \Illuminate\Support\Number::fileSize($row['bytes'], 1) }}
                            @else
                                –
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
