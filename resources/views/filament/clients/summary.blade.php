{{-- App\Filament\Resources\Clients\Widgets\ClientSummary, over the client page's tabs; the dashboard's card styles. --}}
<x-filament-widgets::widget class="gx-card-widget">
    @include('filament.dashboard.styles')
    @php($date = fn ($value) => $value->format(\App\Providers\AppServiceProvider::dateFormat()))
    <section class="fi-section gx-card">
        <div class="gx-boxes">
            @if ($billing)
                <div @class(['gx-box', 'gx-box-bad' => $billing['overdueCount'] > 0])>
                    <div class="gx-box-label">{{ __('Open balance') }}</div>
                    <div class="gx-box-value">{{ $billing['owed'] }}</div>
                    <div class="gx-box-sub">
                        @if ($billing['overdueCount'] > 0)
                            {{ __(':amount past due', ['amount' => $billing['overdue']]) }}
                        @elseif ($billing['count'] > 0)
                            {{ __('in :count unpaid payment notice(s)', ['count' => $billing['count']]) }}
                        @else
                            {{ __('Nothing to pay') }}
                        @endif
                    </div>
                </div>
            @endif
            @if ($billing && $billing['credit'] !== null)
                <div class="gx-box">
                    <div class="gx-box-label">{{ __('Credit') }}</div>
                    <div class="gx-box-value">{{ $billing['credit'] }}</div>
                    <div class="gx-box-sub">{{ __('Pays payment notices') }}</div>
                </div>
            @endif
            @if ($health)
                <div class="gx-box">
                    <div class="gx-box-label">{{ __('Income') }}</div>
                    <div class="gx-box-value">{{ $health['income'] }}</div>
                    <div class="gx-box-sub">{{ __(':amount in the last 12 months', ['amount' => $health['incomeYear']]) }}</div>
                </div>
                <div @class(['gx-box', 'gx-box-warn' => $health['rating'] === 'watch', 'gx-box-bad' => $health['rating'] === 'late'])>
                    <div class="gx-box-label">{{ __('Payment record') }}</div>
                    <div class="gx-box-value">
                        {{ match ($health['rating']) { 'on_time' => __('Pays on time'), 'watch' => __('Watch'), 'late' => __('Pays late'), default => '—' } }}
                    </div>
                    <div class="gx-box-sub">
                        @if ($health['averageDays'] === null)
                            {{ __('No payments in the last 12 months') }}
                        @else
                            {{ __('On average :days day(s) late, :late late payment(s) and :suspensions suspension(s) in 12 months', ['days' => $health['averageDays'], 'late' => $health['late'], 'suspensions' => $health['suspensions']]) }}
                        @endif
                    </div>
                </div>
            @endif
            @if ($services)
                <div class="gx-box">
                    <div class="gx-box-label">{{ __('Active services') }}</div>
                    <div class="gx-box-value">{{ $services['active'] }}</div>
                    <div class="gx-box-sub">
                        {{ $services['suspended'] > 0 ? __(':count suspended', ['count' => $services['suspended']]) : __('Hosting, domains and more') }}
                    </div>
                </div>
                <div class="gx-box">
                    <div class="gx-box-label">{{ __('Next renewal') }}</div>
                    <div class="gx-box-value">{{ $services['next'] ? $date($services['next']->next_due_date) : '—' }}</div>
                    <div class="gx-box-sub">{{ $services['next']?->domain ?: ($services['next']?->label ?? __('Nothing renews')) }}</div>
                </div>
            @endif
            <div class="gx-box">
                <div class="gx-box-label">{{ __('Contact') }}</div>
                <div class="gx-box-value gx-client-contact">
                    <a href="mailto:{{ $client->email }}">{{ $client->email }}</a>
                </div>
                <div class="gx-box-sub">
                    @if (filled($client->phone))
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $client->phone) }}">{{ $client->phone }}</a> ·
                    @endif
                    {{ $client->status->getLabel() }}
                </div>
            </div>
        </div>
    </section>
    <style>
        .gx-client-contact { font-size: .95rem; overflow-wrap: anywhere; }
        .gx-box-warn { background: var(--warning-50); }
        .dark .gx-box-warn { background: color-mix(in oklab, var(--warning-500) 16%, transparent); }
        .gx-box-warn > * { color: var(--warning-700) !important; }
        .dark .gx-box-warn > * { color: var(--warning-300) !important; }
        .gx-client-contact a:hover, .gx-box-sub a:hover { text-decoration: underline; }
    </style>
</x-filament-widgets::widget>
