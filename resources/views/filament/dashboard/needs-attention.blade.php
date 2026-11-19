<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-attention gx-compact" x-data="{ all: false }">
        <div class="gx-head">
            <span class="gx-ico gx-tone-warn">@svg('heroicon-o-exclamation-triangle', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Needs attention') }}</h2>
            @if (count($items))
                <span class="gx-pill gx-pill-warn">{{ count($items) }}</span>
            @endif
        </div>
        <div class="gx-att-list">
        @forelse ($items as $item)
            @php($action = $item['action'] ?? null)
            @if ($action)
                {{-- The row still opens its list; the button does the one thing that needs no choice --}}
                <div class="gx-att gx-row gx-att-act" @if ($loop->index >= \App\Filament\Widgets\NeedsAttention::SHOWN) x-show="all" x-cloak @endif>
                    <span class="gx-ico gx-ico-sm gx-tone-{{ $item['tone'] }}">@svg($item['icon'], 'gx-ico-svg')</span>
                    <a @if ($item['url']) href="{{ $item['url'] }}" @endif class="gx-att-text">
                        <span class="gx-strong">{{ $item['title'] }}</span>
                        @if ($item['sub'])
                            <span class="gx-muted">{{ $item['sub'] }}</span>
                        @endif
                    </a>
                    @if (isset($action['url']))
                        <a href="{{ $action['url'] }}" class="gx-act">{{ $action['label'] }}</a>
                    @else
                        <button type="button" class="gx-act" wire:click="mountAction('{{ $action['call'] }}')" wire:loading.attr="disabled">{{ $action['label'] }}</button>
                    @endif
                </div>
            @else
            <a @if ($item['url']) href="{{ $item['url'] }}" @endif class="gx-att" @if ($loop->index >= \App\Filament\Widgets\NeedsAttention::SHOWN) x-show="all" x-cloak @endif>
                <span class="gx-ico gx-ico-sm gx-tone-{{ $item['tone'] }}">@svg($item['icon'], 'gx-ico-svg')</span>
                <span class="gx-att-text">
                    <span class="gx-strong">{{ $item['title'] }}</span>
                    @if ($item['sub'])
                        <span class="gx-muted">{{ $item['sub'] }}</span>
                    @endif
                </span>
                @svg('heroicon-m-chevron-right', 'gx-att-arrow')
            </a>
            @endif
        @empty
            <div class="gx-att gx-att-empty">
                <span class="gx-ico gx-tone-ok">@svg('heroicon-o-check', 'gx-ico-svg')</span>
                <span class="gx-muted">{{ __('Nothing needs your attention') }}</span>
            </div>
        @endforelse
        </div>
        @if (count($items) > \App\Filament\Widgets\NeedsAttention::SHOWN)
            <button type="button" class="gx-more" x-on:click="all = ! all" x-text="all ? @js(__('Show less')) : @js(__('Show :count more', ['count' => count($items) - \App\Filament\Widgets\NeedsAttention::SHOWN]))"></button>
        @endif
        <x-filament-actions::modals />
    </section>
</x-filament-widgets::widget>
