<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-servers">
        <div class="gx-head">
            <span class="gx-ico">@svg('heroicon-o-server-stack', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Servers') }}</h2>
            <a href="{{ $url }}" class="gx-link">{{ __('All servers') }}</a>
        </div>
        <div class="gx-server-list">
            @foreach ($servers as $server)
                <div class="gx-server">
                    <div class="gx-server-row">
                        @if ($server['status'])
                            <span class="gx-status-dot gx-status-{{ $server['status']->color() }}" role="img" aria-label="{{ $server['status']->label() }}" title="{{ $server['status']->label() }}"></span>
                        @endif
                        <span class="gx-strong">{{ $server['name'] }}</span>
                        @if ($server['status'] === \App\Monitoring\MonitorStatus::Down)
                            <span class="gx-pill gx-pill-bad">{{ $server['status']->label() }}</span>
                        @endif
                        <span class="gx-muted gx-grow">{{ $server['panel'] }}</span>
                    </div>
                    <div class="gx-server-bars">
                        @if ($server['uptime'])
                            @include('filament.servers.uptime-bar', ['history' => $server['uptime'], 'caption' => __('Uptime, 24 hours'), 'fill' => true])
                        @endif
                        @include('filament.servers.accounts-bar', ['server' => $server['server'], 'squares' => \App\Filament\Widgets\ServerCapacity::SQUARES, 'caption' => __('Accounts'), 'fill' => true])
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-filament-widgets::widget>
