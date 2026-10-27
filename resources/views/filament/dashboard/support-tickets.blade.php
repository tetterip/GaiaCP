<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-attention gx-support">
        <div class="gx-head">
            <span class="gx-ico gx-tone-info">@svg('heroicon-o-lifebuoy', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Support') }}</h2>
            <a href="{{ $url }}" class="gx-muted">{{ __('All tickets') }}</a>
        </div>
        <div class="gx-support-counts">
            @foreach ($counts as $item)
                <a href="{{ $url }}?activeTab={{ $item['tab'] }}" class="gx-support-count">
                    <span class="gx-mid">{{ $item['count'] }}</span>
                    <span class="gx-muted">{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
        @forelse ($tickets as $ticket)
            <a href="{{ $ticket['url'] }}" class="gx-att">
                <span class="gx-ico gx-tone-{{ $ticket['tone'] }}">@svg('heroicon-o-chat-bubble-left-ellipsis', 'gx-ico-svg')</span>
                <span class="gx-att-text">
                    <span class="gx-strong">{{ $ticket['title'] }}</span>
                    <span class="gx-muted">{{ $ticket['sub'] }}</span>
                </span>
                @svg('heroicon-m-chevron-right', 'gx-att-arrow')
            </a>
        @empty
            <div class="gx-att gx-att-empty">
                <span class="gx-ico gx-tone-ok">@svg('heroicon-o-check', 'gx-ico-svg')</span>
                <span class="gx-muted">{{ __('No ticket is waiting for an answer') }}</span>
            </div>
        @endforelse
    </section>
</x-filament-widgets::widget>
