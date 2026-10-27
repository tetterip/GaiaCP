{{-- App\Filament\Client\Pages\ServerStatus: whether every server works, then each server's state and uptime squares. --}}
<x-filament-panels::page>
    @php
        $servers = $this->servers();
        $problems = \App\Support\StatusPage::problems($servers);
        $down = $problems->contains(fn (array $server) => $server['status'] === \App\Monitoring\MonitorStatus::Down);
    @endphp

    @if ($servers->isEmpty())
        <x-filament::section>
            <p class="gcp-kb-meta">{{ __('There are no servers to show yet.') }}</p>
        </x-filament::section>
    @elseif ($problems->isEmpty())
        <div class="gcp-incident is-ok" role="status">
            <x-filament::icon icon="heroicon-o-check-circle" class="gcp-incident-icon" />
            <div><strong>{{ __('All systems operational') }}</strong></div>
        </div>
    @else
        <div @class(['gcp-incident', 'is-status', 'is-down' => $down]) role="status">
            <x-filament::icon :icon="$down ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-wrench-screwdriver'" class="gcp-incident-icon" />
            <div>
                <strong>{{ $down ? __('Some servers are not working right now') : __('Maintenance on some servers') }}</strong>
                <p>{{ $this->message() }}</p>
                <ul>
                    @foreach ($problems as $server)
                        <li>{{ $server['name'] }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @foreach ($servers as $server)
        <x-filament::section>
            <div class="gcp-status-head">
                <strong>{{ $server['name'] }}</strong>
                <x-filament::badge :color="$server['status']->color()">{{ $server['status']->label() }}</x-filament::badge>
                @if ($server['since'])
                    <span class="gcp-kb-meta">{{ __('since :date', ['date' => $server['since']->timezone(config('app.timezone'))->format(\App\Providers\AppServiceProvider::dateFormat().' H:i')]) }}</span>
                @endif
            </div>
            @include('filament.client.server-uptime', ['uptime' => $server['uptime']])
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
