{{-- Home: what is owed, each notice with its Pay (one low line when all is paid), beside the client's services with the way
     into each one's control panel; then the shortcuts (a phone only) and what renews next. The cards are the dashboard's .gx-* (filament.dashboard.styles), the rest
     filament.themes.client. --}}
<x-filament-panels::page>
    @include('filament.dashboard.styles')

    @php
        $client = $this->client();
        $unpaid = $this->unpaidNotices();
        $overdue = $unpaid->filter(fn ($notice) => $notice->isOverdue());
        $upcoming = $this->upcoming();
        $date = fn ($value) => $value->format(\App\Providers\AppServiceProvider::dateFormat());
        // Notices in more than one currency show one amount per currency.
        $sum = fn ($notices) => $notices->isEmpty()
            ? \App\Support\Money::format(0, \App\Support\Currencies::forClient($client))
            : $notices->groupBy('currency')->map(fn ($group, $currency) => \App\Support\Money::format((int) $group->sum('owed_cents'), $currency))->join(' + ');
        $noticeUrl = fn ($notice) => \App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource::getUrl('view', ['record' => $notice]);
    @endphp

    @foreach ($this->announcements() as $announcement)
        <div class="gcp-incident is-news" role="status">
            <x-filament::icon icon="heroicon-o-megaphone" class="gcp-incident-icon" />
            <div>
                <strong>{{ $announcement['subject'] }}</strong>
                <div class="gcp-news-body">{!! $announcement['body'] !!}</div>
            </div>
        </div>
    @endforeach

    @php
        $services = $this->services();
        $kinds = $this->activeByKind();
        $credit = \App\Filament\Client\Pages\Credit::canAccess();
        $noticesUrl = \App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource::getUrl();
    @endphp

    <div class="gcp-home-grid">
        @if ($unpaid->isNotEmpty())
            <section class="fi-section gx-card gx-hero gcp-home-pay">
                <div class="gx-hero-label"><span @class(['gx-dot', 'gcp-home-dot-bad' => $overdue->isNotEmpty()])></span>{{ __('To pay') }}</div>
                <div class="gcp-home-pay-amount">
                    <div @class(['gx-big', 'gcp-home-owed' => $overdue->isNotEmpty()])>{{ $sum($unpaid) }}</div>
                    <div class="gx-muted">
                        {{ trans_choice(':count payment notice|:count payment notices', $unpaid->count()) }}@if ($overdue->isNotEmpty()) · <span class="gcp-overdue">{{ __(':amount past due', ['amount' => $sum($overdue)]) }}</span>@endif
                    </div>
                </div>
                <div class="gcp-home-rows">
                    @foreach ($unpaid->take(\App\Filament\Client\Pages\Dashboard::NOTICES) as $notice)
                        <a href="{{ $noticeUrl($notice) }}" class="gcp-home-row">
                            <span class="gcp-home-row-text">
                                <strong>{{ $notice->number }}</strong>
                                <span @class(['gcp-overdue' => $notice->isOverdue()])>{{ $notice->isOverdue() ? __('Was due :date', ['date' => $date($notice->due_date)]) : __('Due :date', ['date' => $date($notice->due_date)]) }}</span>
                            </span>
                            <span class="gcp-home-row-amount">{{ \App\Support\Money::format((int) ($notice->owed_cents ?? $notice->total_cents), $notice->currency) }}</span>
                            <span class="gcp-home-row-pay">{{ __('Pay') }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="gcp-home-foot">
                    <a class="gx-link" href="{{ $noticesUrl }}">{{ $unpaid->count() > \App\Filament\Client\Pages\Dashboard::NOTICES ? __('All :count payment notices to pay', ['count' => $unpaid->count()]) : __('Payment notices') }}</a>
                    @if ($credit)
                        <a class="gcp-home-credit" href="{{ \App\Filament\Client\Pages\Credit::getUrl() }}">{{ __('Your credit') }} <strong>{{ \App\Support\Money::format((int) $client->credit_cents, $client->currencyCode()) }}</strong></a>
                    @endif
                </div>
            </section>
        @else
            <section class="fi-section gx-card gcp-home-pay gcp-home-paid">
                <div class="gcp-home-paid-line">
                    <span class="gx-ico gx-tone-ok">@svg('heroicon-o-check', 'gx-ico-svg')</span>
                    <div class="gcp-home-paid-text">
                        <strong>{{ __('Everything is paid') }}</strong>
                        <span class="gx-muted">
                            @if ($upcoming->isNotEmpty())
                                {{ __('Next renewal :date, :name', ['date' => $date($upcoming->first()->next_due_date), 'name' => $upcoming->first()->domain ?: $upcoming->first()->label]) }}
                            @else
                                {{ __('Nothing renews') }}
                            @endif
                        </span>
                    </div>
                </div>
                <div class="gcp-home-foot">
                    <a class="gx-link" href="{{ $noticesUrl }}">{{ __('Payment notices') }}</a>
                    @if ($credit)
                        <a class="gcp-home-credit" href="{{ \App\Filament\Client\Pages\Credit::getUrl() }}">{{ __('Your credit') }} <strong>{{ \App\Support\Money::format((int) $client->credit_cents, $client->currencyCode()) }}</strong></a>
                    @endif
                </div>
            </section>
        @endif

        <section class="fi-section gx-card gcp-home-card gcp-home-services">
            <div class="gx-head">
                <span class="gx-ico">@svg('heroicon-o-server-stack', 'gx-ico-svg')</span>
                <h2 class="gx-title">{{ __('My services') }}</h2>
                @if (\App\Filament\Client\Pages\Order::canAccess())
                    <x-filament::button tag="a" :href="\App\Filament\Client\Pages\Order::getUrl()" color="gray" size="sm" outlined icon="heroicon-m-plus" class="gcp-home-order">{{ __('Order') }}</x-filament::button>
                @endif
            </div>
            @if ($services)
                <div class="gcp-home-rows">
                    @foreach ($services as $row)
                        @php($service = $row['service'])
                        <div class="gcp-home-row">
                            <a href="{{ $row['url'] }}" class="gcp-home-row-text">
                                <strong>
                                    @if ($row['state'])
                                        <span class="gcp-live gcp-live-{{ $row['state']['status']->value }}" title="{{ $row['state']['text'] }}" aria-label="{{ $row['state']['text'] }}" role="img"></span>
                                    @endif
                                    {{ $service->domain ?: $service->label }}
                                </strong>
                                <span>
                                    @if ($service->status === \App\Enums\ServiceStatus::Suspended)
                                        <span class="gcp-overdue">{{ __('Suspended') }}</span> ·
                                    @endif
                                    {{ $service->domain && $service->label !== $service->domain ? $service->label : $service->type->getLabel() }}@if ($service->next_due_date) · {{ $service->auto_terminate ? __('ends :date', ['date' => $date($service->next_due_date)]) : __('renews :date', ['date' => $date($service->next_due_date)]) }}@endif
                                </span>
                            </a>
                            @if ($row['login'])
                                <a href="{{ $row['login'] }}" target="_blank" rel="noopener" class="gcp-home-row-open">{{ __('Log in to :panel', ['panel' => $row['panel']]) }}</a>
                            @else
                                <a href="{{ $row['url'] }}" class="gcp-home-row-open gcp-home-row-manage">{{ __('Manage') }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="gx-muted">{{ __('Hosting, domains and more') }}</div>
            @endif
            @if ($kinds)
                <div class="gcp-home-kinds">
                    <span class="gx-muted">{{ trans_choice(':count active service|:count active services', $this->activeCount()) }}</span>
                    @foreach ($kinds as $type)
                        <a href="{{ $type['url'] }}" class="gcp-home-kind">{{ $type['label'] }} <strong>{{ $type['count'] }}</strong></a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <nav class="gcp-home-tiles" aria-label="{{ __('Shortcuts') }}">
        @foreach ($this->shortcuts() as $shortcut)
            <a href="{{ $shortcut['url'] }}" class="gcp-home-tile">
                <span class="gcp-home-tile-icon"><x-filament::icon :icon="$shortcut['icon']" /></span>
                <span>{{ $shortcut['label'] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($upcoming->isNotEmpty())
        <section class="fi-section gx-card gcp-home-card">
            <div class="gx-head">
                <span class="gx-ico">@svg('heroicon-o-arrow-path', 'gx-ico-svg')</span>
                <h2 class="gx-title">{{ __('Coming up for renewal') }}</h2>
            </div>
            <div class="gcp-home-rows">
                @foreach ($upcoming as $service)
                    <a href="{{ $this->serviceUrl($service) }}" class="gcp-home-row">
                        <span class="gcp-home-row-text">
                            <strong>{{ $service->domain ?: $service->label }}</strong>
                            <span @class(['gcp-overdue' => $service->isOverdue()])>
                                {{ $service->domain && $service->label !== $service->domain ? $service->label : $service->type->getLabel() }} · {{ $service->billing_cycle->getLabel() }} · {{ $date($service->next_due_date) }}
                                @if ($service->auto_terminate)
                                    <span class="gcp-home-flag">{{ __('Does not renew automatically') }}</span>
                                @endif
                            </span>
                        </span>
                        <span class="gcp-home-row-amount">{{ \App\Support\Money::format($service->price_cents, $service->currency) }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="gcp-home-arrow" />
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if (filled(config('gaiacp.company.email')))
        <p class="gcp-home-note gcp-home-contact">
            <x-filament::icon icon="heroicon-o-envelope" />
            {{ __('Questions? Write to us at :email.', ['email' => config('gaiacp.company.email')]) }}
        </p>
    @endif
</x-filament-panels::page>
