<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-attention gx-compact gx-activity">
        <div class="gx-head">
            <span class="gx-ico">@svg('heroicon-o-bolt', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Recent activity') }}</h2>
            <a {{ \Filament\Support\generate_href_html($url) }} class="gx-link">{{ __('Activity log') }}</a>
        </div>
        <div class="gx-chips" role="group" aria-label="{{ __('Show') }}">
            <button type="button" wire:click="show('')" @class(['gx-chip', 'is-on' => $this->kind === '']) aria-pressed="{{ $this->kind === '' ? 'true' : 'false' }}">{{ __('All') }}</button>
            @foreach ($kinds as $key => $label)
                <button type="button" wire:click="show('{{ $key }}')" @class(['gx-chip', 'is-on' => $this->kind === $key]) aria-pressed="{{ $this->kind === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="gx-att-list" wire:loading.class="gx-loading" wire:target="show">
            @forelse ($rows as $row)
                <a {{ \Filament\Support\generate_href_html($row['url']) }} class="gx-att">
                    <span class="gx-ico gx-ico-sm gx-tone-{{ $row['tone'] }}">@svg($row['icon'], 'gx-ico-svg')</span>
                    <span class="gx-att-text">
                        <span class="gx-strong">{{ $row['title'] }}</span>
                        <span class="gx-muted">{{ $row['sub'] }}</span>
                    </span>
                </a>
            @empty
                <div class="gx-att gx-att-empty">
                    <span class="gx-muted">{{ __('Nothing here yet') }}</span>
                </div>
            @endforelse
        </div>
    </section>
</x-filament-widgets::widget>
