{{-- Servers → Compare with the server (Servers\Pages\CompareServer). Plain tables: the panel has no compiled theme,
     so only Filament's own components and inline styles. --}}
@php
    $cell = 'padding:.5rem .75rem;text-align:start;vertical-align:top';
    $head = $cell.';font-weight:600;border-bottom:1px solid var(--gray-200);white-space:nowrap';
    $muted = 'color:var(--gray-500);font-size:.8rem';
@endphp
<x-filament-panels::page>
    @if ($error)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" :heading="__('The server could not be read')">
            {{ $error }}
        </x-filament::section>
    @elseif ($comparison)
        @php($c = $comparison)
        <x-filament::section>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;align-items:center">
                <x-filament::badge :color="$c['onServerOnly']['unbilled'] ? 'danger' : 'gray'">{{ __('Only on the server') }}: {{ count($c['onServerOnly']['unbilled']) }}</x-filament::badge>
                <x-filament::badge :color="$c['missing'] ? 'danger' : 'gray'">{{ __('Missing on the server') }}: {{ count($c['missing']) }}</x-filament::badge>
                <x-filament::badge :color="$c['different'] ? 'warning' : 'gray'">{{ __('Different') }}: {{ count($c['different']) }}</x-filament::badge>
                <x-filament::badge color="success">{{ __('Matching') }}: {{ $c['matching'] }}</x-filament::badge>
                <span style="{{ $muted }}">{{ __('Read at :time. Nothing is changed until you press a fix, which asks first.', ['time' => $c['read_at']]) }}</span>
            </div>
        </x-filament::section>

        @foreach ([
            'unbilled' => [__('Only on the server'), __('Running accounts on the server that GaiaCP does not bill.')],
            'suspended' => [__('Suspended on the server, not billed'), __('Suspended accounts that GaiaCP does not bill: often left behind after moving a site.')],
            'elsewhere' => [__('Billed on another server'), __('Accounts that GaiaCP bills on another server: left behind after a move.')],
            'ignored' => [__('Not billed on purpose'), __('Marked by an admin; not reported again.')],
        ] as $group => [$heading, $description])
            @php($rows = $c['onServerOnly'][$group])
            @continue($group !== 'unbilled' && ! $rows)
            <x-filament::section :heading="$heading.' ('.count($rows).')'" :description="$description" collapsible :collapsed="$group !== 'unbilled'">
                @if ($rows)
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                            <thead><tr>
                                <th style="{{ $head }}">{{ __('Username') }}</th>
                                <th style="{{ $head }}">{{ __('Domain') }}</th>
                                <th style="{{ $head }}">{{ __('Package') }}</th>
                                <th style="{{ $head }}">{{ __('In GaiaCP') }}</th>
                                <th style="{{ $head }}"></th>
                            </tr></thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td style="{{ $cell }}">{{ $row['username'] }}@if ($row['suspended'] && $group !== 'suspended')<br><x-filament::badge color="warning" size="sm">{{ __('Suspended') }}</x-filament::badge>@endif</td>
                                        <td style="{{ $cell }}">{{ $row['domain'] ?? '–' }}</td>
                                        <td style="{{ $cell }}">{{ $row['package'] ?? '–' }}</td>
                                        <td style="{{ $cell }}">
                                            @if ($row['elsewhere'])
                                                <a href="{{ $row['elsewhere']['url'] }}">{{ $row['elsewhere']['label'] }}</a>
                                                <x-filament::badge :color="$row['elsewhere']['status_color']" size="sm">{{ $row['elsewhere']['status'] }}</x-filament::badge>
                                                <div style="{{ $muted }}">{{ __('On :server', ['server' => $row['elsewhere']['server']]) }} · {{ $row['elsewhere']['client'] }}</div>
                                            @elseif ($row['former'])
                                                <a href="{{ $row['former']['url'] }}">{{ $row['former']['label'] }}</a>
                                                <x-filament::badge :color="$row['former']['status_color']" size="sm">{{ $row['former']['status'] }}</x-filament::badge>
                                                <div style="{{ $muted }}">{{ $row['former']['client'] }}</div>
                                            @else
                                                <span style="{{ $muted }}">{{ $group === 'ignored' && filled($row['note']) ? $row['note'] : __('No service') }}</span>
                                            @endif
                                        </td>
                                        <td style="{{ $cell }};white-space:nowrap;text-align:end">
                                            @if ($group === 'ignored')
                                                {{ ($this->unignoreAction)(['username' => $row['username']]) }}
                                            @else
                                                @if ($group !== 'elsewhere')
                                                    <x-filament::link :href="$row['add_url']" icon="heroicon-o-plus" size="sm">{{ __('Add to GaiaCP') }}</x-filament::link>
                                                    &nbsp;
                                                @endif
                                                {{ ($this->ignoreAction)(['username' => $row['username']]) }}
                                                @if (! $row['suspended'])
                                                    &nbsp;
                                                    {{ ($this->suspendAccountAction)(['username' => $row['username']]) }}
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <span style="{{ $muted }}">{{ __('None') }}</span>
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::section :heading="__('Missing on the server')" :description="__('Active or suspended services in GaiaCP whose account the server does not have.')" collapsible>
            @if ($c['missing'])
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                        <thead><tr>
                            <th style="{{ $head }}">{{ __('Service') }}</th>
                            <th style="{{ $head }}">{{ __('Username') }}</th>
                            <th style="{{ $head }}">{{ __('Client') }}</th>
                            <th style="{{ $head }}">{{ __('Status') }}</th>
                            <th style="{{ $head }}"></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($c['missing'] as $service)
                                <tr>
                                    <td style="{{ $cell }}"><a href="{{ $service['url'] }}">{{ $service['label'] }}</a><div style="{{ $muted }}">{{ $service['domain'] }}</div></td>
                                    <td style="{{ $cell }}">{{ $service['username'] ?? '–' }}</td>
                                    <td style="{{ $cell }}">@if ($service['client_url'])<a href="{{ $service['client_url'] }}">{{ $service['client'] }}</a>@endif</td>
                                    <td style="{{ $cell }}"><x-filament::badge :color="$service['status_color']" size="sm">{{ $service['status'] }}</x-filament::badge></td>
                                    <td style="{{ $cell }};text-align:end">
                                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.25rem">
                                            @foreach ([\App\Actions\FixServerDifference::CREATE_ON_SERVER, \App\Actions\FixServerDifference::MARK_TERMINATED] as $fix)
                                                {{ ($this->fixAction)(['service' => $service['id'], 'fix' => $fix]) }}
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <span style="{{ $muted }}">{{ __('None') }}</span>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Different')" :description="__('On both sides, but suspended on one side only, or with another package or domain.')" collapsible>
            @if ($c['different'])
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                        <thead><tr>
                            <th style="{{ $head }}">{{ __('Service') }}</th>
                            <th style="{{ $head }}">{{ __('What differs') }}</th>
                            <th style="{{ $head }}">{{ __('On the server') }}</th>
                            <th style="{{ $head }}">{{ __('In GaiaCP') }}</th>
                            <th style="{{ $head }}"></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($c['different'] as $row)
                                <tr>
                                    <td style="{{ $cell }}"><a href="{{ $row['service']['url'] }}">{{ $row['service']['label'] }}</a><div style="{{ $muted }}">{{ $row['service']['client'] }}</div></td>
                                    <td style="{{ $cell }}">{!! collect($row['differences'])->map(fn ($d) => e($d))->join('<br>') !!}</td>
                                    <td style="{{ $cell }}">
                                        {{ $row['account']['username'] }} · {{ $row['account']['domain'] ?? '–' }}
                                        <div style="{{ $muted }}">{{ $row['account']['package'] ?? '–' }} · {{ $row['account']['suspended'] ? __('Suspended') : __('Active') }}</div>
                                    </td>
                                    <td style="{{ $cell }}">
                                        {{ $row['service']['username'] ?? '–' }} · {{ $row['service']['domain'] ?? '–' }}
                                        <div style="{{ $muted }}">{{ $row['service']['package'] ?? '–' }} · {{ $row['service']['status'] }}</div>
                                    </td>
                                    <td style="{{ $cell }};text-align:end">
                                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.25rem">
                                            @foreach ($row['fixes'] as $fix)
                                                {{ ($this->fixAction)(['service' => $row['service']['id'], 'fix' => $fix]) }}
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <span style="{{ $muted }}">{{ __('None') }}</span>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
