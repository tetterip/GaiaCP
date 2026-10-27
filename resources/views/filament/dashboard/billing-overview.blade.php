<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gx-hero">
        <div class="gx-hero-label"><span class="gx-dot"></span>{{ __('Open balance') }}</div>
        <div class="gx-hero-amount">
            <div class="gx-big">{{ $balance }}</div>
            <div class="gx-muted">{{ __('in :count unpaid payment notice(s)', ['count' => $notices]) }}</div>
        </div>
        <div class="gx-boxes gx-boxes-stacked">
            <div @class(['gx-box', 'gx-box-bad' => $overdueCount > 0])>
                <div class="gx-box-label">{{ __('Past due') }}</div>
                <div class="gx-box-value">{{ $overdue }}</div>
                <div class="gx-box-sub">{{ __(':count notice(s)', ['count' => $overdueCount]) }}</div>
            </div>
            <div class="gx-box">
                <div class="gx-box-label">{{ __('Due in 7 days') }}</div>
                <div class="gx-box-value">{{ $week }}</div>
                <div class="gx-box-sub">{{ __(':count service(s)', ['count' => $weekCount]) }}</div>
            </div>
        </div>
        <div class="gx-actions gx-actions-split">
            <x-filament::button tag="a" :href="$noticesUrl">{{ __('Payment notices') }}</x-filament::button>
            @if ($paymentsUrl)
                <x-filament::button tag="a" :href="$paymentsUrl" color="gray" outlined icon="heroicon-m-arrow-right" icon-position="after">{{ __('Payments') }}</x-filament::button>
            @endif
        </div>
    </section>
</x-filament-widgets::widget>
