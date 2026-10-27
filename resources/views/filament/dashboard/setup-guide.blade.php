<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <style>
        .gx-setup-bar { height: .35rem; margin: .75rem 0; border-radius: 999px; background: var(--gray-200); overflow: hidden; }
        .gx-setup-bar span { display: block; height: 100%; background: var(--primary-500); border-radius: inherit; }
        .dark .gx-setup-bar { background: rgb(255 255 255 / .1); }
        .gx-setup-next { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; justify-content: space-between; }
        .gx-setup-actions { display: flex; gap: 1rem; align-items: center; font-size: .875rem; }
        .gx-setup-actions a { color: var(--primary-600); font-weight: 600; text-decoration: none; }
        .gx-setup-actions button { color: var(--gray-500); background: none; border: 0; padding: 0; cursor: pointer; }
        .dark .gx-setup-actions a { color: var(--primary-400); }
    </style>
    <section class="fi-section gx-card gx-setup">
        <div class="gx-head">
            <span class="gx-ico gx-tone-info">@svg('heroicon-o-rocket-launch', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Finish setting up GaiaCP') }}</h2>
            <span class="gx-muted">{{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}</span>
        </div>
        <div class="gx-setup-bar"><span style="width: {{ $total ? round($done / $total * 100) : 0 }}%"></span></div>
        <div class="gx-setup-next">
            @if ($next)
                <span><span class="gx-muted">{{ __('Next:') }}</span> <span class="gx-strong">{{ $next['title'] }}</span></span>
            @endif
            <span class="gx-setup-actions">
                <a href="{{ $url }}">{{ __('Continue') }}</a>
                <button type="button" wire:click="hide">{{ __('Hide') }}</button>
            </span>
        </div>
    </section>
</x-filament-widgets::widget>
