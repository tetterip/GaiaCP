<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-attention gx-compact gx-renewals">
        <div class="gx-head">
            <span class="gx-ico">@svg('heroicon-o-arrow-path', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Renewals') }}</h2>
            <a {{ \Filament\Support\generate_href_html($url) }} class="gx-link">{{ __('All services') }}</a>
        </div>
        @php($shown = count(array_merge([], ...array_values($groups))))
        @foreach ($labels as $key => $label)
            @continue(empty($groups[$key]))
            <div @class(['gx-group', 'gx-bad-text' => $key === 'overdue'])>{{ $label }}</div>
            @foreach ($groups[$key] as $row)
                <div class="gx-att gx-row" wire:key="renewal-{{ $row['id'] }}">
                    <a {{ \Filament\Support\generate_href_html($row['url']) }} class="gx-att-text">
                        <span class="gx-strong">{{ $row['title'] }}</span>
                        <span class="gx-muted">{{ $row['sub'] }}</span>
                    </a>
                    <span class="gx-row-end">
                        <span class="gx-strong gx-tnum">{{ $row['amount'] }}</span>
                        <span @class(['gx-muted', 'gx-tnum', 'gx-bad-text' => $key === 'overdue'])>{{ $row['date'] }}</span>
                    </span>
                    @if ($row['payable'])
                        {{ ($this->recordPaymentAction)(['service' => $row['id']]) }}
                    @endif
                </div>
            @endforeach
        @endforeach
        @if ($groups === [])
            <div class="gx-att gx-att-empty">
                <span class="gx-ico gx-ico-sm gx-tone-ok">@svg('heroicon-o-check', 'gx-ico-svg')</span>
                <span class="gx-muted">{{ __('Nothing is overdue or due in the next 30 days') }}</span>
            </div>
        @elseif ($total > $shown)
            <a {{ \Filament\Support\generate_href_html($url) }} class="gx-more">{{ __('and :count more', ['count' => $total - $shown]) }}</a>
        @endif
        <x-filament-actions::modals />
    </section>
</x-filament-widgets::widget>
