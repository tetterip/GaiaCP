<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-chart">
        <div class="gx-head">
            <span class="gx-ico">@svg('heroicon-o-presentation-chart-line', 'gx-ico-svg')</span>
            <h2 class="gx-title">{{ __('Orders and income') }}</h2>
            <div class="gx-seg" role="group" aria-label="{{ __('Period') }}">
                @foreach ($periods as $key => $label)
                    <button type="button" wire:click="setPeriod('{{ $key }}')" @class(['gx-on' => $period === $key]) aria-pressed="{{ $period === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="gx-legend">
            <span><i class="gx-key gx-key-orders"></i>{{ __('New orders') }}</span>
            <span><i class="gx-key gx-key-completed"></i>{{ __('Completed orders') }}</span>
            <span><i class="gx-key gx-key-income"></i>{{ __('Income') }}</span>
        </div>
        <div class="gx-totals">
            <span class="gx-mid">{{ $income }}</span>
            @if ($change !== null)
                <span @class(['gx-pill', 'gx-pill-ok' => $change >= 0, 'gx-pill-bad' => $change < 0])>{{ ($change > 0 ? '+' : '').$change }}%</span>
            @endif
            <span class="gx-muted">{{ $period === 'today' ? __('income, against :amount yesterday by this time', ['amount' => $previous]) : __('income, against :amount in the period before', ['amount' => $previous]) }}</span>
            <span class="gx-grow"></span>
            <span class="gx-muted">{!! __(':orders orders · :completed completed', ['orders' => '<b>'.e($orders).'</b>', 'completed' => '<b>'.e($completed).'</b>']) !!}</span>
        </div>
        <div class="gx-plot" wire:loading.class="gx-loading">
            <div class="gx-axis">@foreach ($axis as $tick)<span>{{ $tick }}</span>@endforeach</div>
            {{-- Keyed by period, so the bars grow and the line draws again when the period changes --}}
            <div class="gx-plot-main" wire:key="activity-plot-{{ $period }}">
                <svg viewBox="0 0 600 190" preserveAspectRatio="none" role="img" aria-label="{{ __('Orders, completed orders and income per hour, day or month') }}">
                    @foreach ([10, 66, 122] as $y)
                        <line x1="0" y1="{{ $y }}" x2="600" y2="{{ $y }}" class="gx-grid" stroke-dasharray="3 5" vector-effect="non-scaling-stroke"/>
                    @endforeach
                    <line x1="0" y1="{{ $base }}" x2="600" y2="{{ $base }}" class="gx-grid" vector-effect="non-scaling-stroke"/>
                    @foreach ($bars as $b)
                        <g style="--gx-i: {{ $loop->index }}">
                            <title>{{ $b['title'] }}</title>
                            <rect x="{{ $b['x1'] }}" y="{{ $base - $b['h1'] }}" width="{{ $bar }}" height="{{ $b['h1'] }}" rx="2" class="gx-bar-orders"/>
                            <rect x="{{ $b['x2'] }}" y="{{ $base - $b['h2'] }}" width="{{ $bar }}" height="{{ $b['h2'] }}" rx="2" class="gx-bar-completed"/>
                        </g>
                    @endforeach
                    <g class="gx-income">
                        <path d="{{ $area }}" class="gx-area"/>
                        <path d="{{ $line }}" class="gx-line" fill="none" vector-effect="non-scaling-stroke"/>
                    </g>
                </svg>
                <div class="gx-labels">@foreach ($labels as $label)<span style="{{ $label['style'] }}">{{ $label['label'] }}</span>@endforeach</div>
            </div>
        </div>
    </section>
</x-filament-widgets::widget>
