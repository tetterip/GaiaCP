<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-attention">
        <div class="gx-head">
            <span class="gx-ico gx-tone-warn">@svg('heroicon-o-exclamation-triangle', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Needs attention') }}</h2>
            @if (count($items))
                <span class="gx-pill gx-pill-warn">{{ count($items) }}</span>
            @endif
        </div>
        @forelse ($items as $item)
            <a @if ($item['url']) href="{{ $item['url'] }}" @endif class="gx-att">
                <span class="gx-ico gx-tone-{{ $item['tone'] }}">@svg($item['icon'], 'gx-ico-svg')</span>
                <span class="gx-att-text">
                    <span class="gx-strong">{{ $item['title'] }}</span>
                    @if ($item['sub'])
                        <span class="gx-muted">{{ $item['sub'] }}</span>
                    @endif
                </span>
                @svg('heroicon-m-chevron-right', 'gx-att-arrow')
            </a>
        @empty
            <div class="gx-att gx-att-empty">
                <span class="gx-ico gx-tone-ok">@svg('heroicon-o-check', 'gx-ico-svg')</span>
                <span class="gx-muted">{{ __('Nothing needs your attention') }}</span>
            </div>
        @endforelse
    </section>
</x-filament-widgets::widget>
