{{-- Home, like the admin dashboard: what is owed beside credit and the active services per kind, then a tile per shortcut
     and the two lists side by side. The cards are the dashboard's .gx-* (filament.dashboard.styles), the rest
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

    <div class="gcp-home-grid">
        <section class="fi-section gx-card gx-hero">
            <div class="gx-hero-label"><span class="gx-dot"></span>{{ __('To pay') }}</div>
            <div class="gx-hero-amount">
                <div @class(['gx-big', 'gcp-home-owed' => $overdue->isNotEmpty()])>{{ $sum($unpaid) }}</div>
                <div class="gx-muted">{{ match ($unpaid->count()) { 0 => __('Nothing to pay'), 1 => __('1 payment notice'), default => __(':count payment notices', ['count' => $unpaid->count()]) } }}</div>
            </div>
            <div class="gx-boxes">
                @if ($overdue->isNotEmpty())
                <div @class(['gx-box', 'gx-box-bad' => $overdue->isNotEmpty()])>
                    <div class="gx-box-label">{{ __('Past due') }}</div>
                    <div class="gx-box-value">{{ $sum($overdue) }}</div>
                    <div class="gx-box-sub">{{ trans_choice(':count notice|:count notices', $overdue->count()) }}</div>
                </div>
                @endif
                <div class="gx-box">
                    <div class="gx-box-label">{{ __('Next renewal') }}</div>
                    <div class="gx-box-value">{{ $upcoming->isNotEmpty() ? $date($upcoming->first()->next_due_date) : '—' }}</div>
                    <div class="gx-box-sub">{{ $upcoming->first()?->label ?? __('Nothing renews') }}</div>
                </div>
            </div>
            <div class="gx-actions gx-actions-split">
                @if ($unpaid->isNotEmpty())
                    <x-filament::button tag="a" :href="$noticeUrl($overdue->first() ?? $unpaid->first())">{{ __('Pay') }}</x-filament::button>
                @endif
                <x-filament::button tag="a" :href="\App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource::getUrl()" color="gray" outlined icon="heroicon-m-arrow-right" icon-position="after">{{ __('Payment notices') }}</x-filament::button>
            </div>
        </section>

        <div class="gcp-home-side">
            @if (\App\Filament\Client\Pages\Credit::canAccess())
                <section class="fi-section gx-card gcp-home-stat">
                    <div class="gx-hero-label">{{ __('Your credit') }}</div>
                    <div class="gx-big">{{ \App\Support\Money::format((int) $client->credit_cents, $client->currencyCode()) }}</div>
                    <a class="gx-link" href="{{ \App\Filament\Client\Pages\Credit::getUrl() }}">{{ __('Credit') }}</a>
                </section>
            @endif
            <section class="fi-section gx-card gcp-home-stat gcp-home-services">
                <div class="gcp-home-services-top">
                    <div>
                        <div class="gx-hero-label">{{ __('Active services') }}</div>
                        <div class="gx-big">{{ $this->activeCount() }}</div>
                    </div>
                    @if (\App\Filament\Client\Pages\Order::canAccess())
                        <x-filament::button tag="a" :href="\App\Filament\Client\Pages\Order::getUrl()" color="gray" size="sm" outlined icon="heroicon-m-plus">{{ __('Order') }}</x-filament::button>
                    @endif
                </div>
                <div class="gcp-home-types">
                    @forelse ($this->activeByKind() as $type)
                        <a href="{{ $type['url'] }}" class="gcp-home-type">
                            <x-filament::icon :icon="$type['icon']" class="gcp-home-type-icon" />
                            <span class="gcp-home-type-label">{{ $type['label'] }}</span>
                            <span class="gcp-home-type-count">{{ $type['count'] }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="gcp-home-arrow" />
                        </a>
                    @empty
                        <div class="gx-muted">{{ __('Hosting, domains and more') }}</div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    <nav class="gcp-home-tiles" aria-label="{{ __('Shortcuts') }}">
        @foreach ($this->shortcuts() as $shortcut)
            <a href="{{ $shortcut['url'] }}" class="gcp-home-tile">
                <span class="gcp-home-tile-icon"><x-filament::icon :icon="$shortcut['icon']" /></span>
                <span>{{ $shortcut['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="gcp-home-lists">
    @if ($unpaid->isNotEmpty())
        <section class="fi-section gx-card gcp-home-card">
            <div class="gx-head">
                <span class="gx-ico gx-tone-warn">@svg('heroicon-o-document-text', 'gx-ico-svg')</span>
                <h2 class="gx-title">{{ __('Payment notices to pay') }}</h2>
            </div>
            <div class="gcp-home-rows">
                @foreach ($unpaid as $notice)
                    <a href="{{ $noticeUrl($notice) }}" class="gcp-home-row">
                        <span class="gcp-home-row-text">
                            <strong>{{ $notice->number }}</strong>
                            <span @class(['gcp-overdue' => $notice->isOverdue()])>{{ __('Due :date', ['date' => $date($notice->due_date)]) }}</span>
                        </span>
                        <span class="gcp-home-row-amount">{{ \App\Support\Money::format($notice->total_cents, $notice->currency) }}</span>
                        <span class="gcp-home-row-pay">{{ __('Pay') }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

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
                            <span @class(['gcp-overdue' => $service->isOverdue()])>{{ $service->domain && $service->label !== $service->domain ? $service->label : $service->type->getLabel() }} · {{ $date($service->next_due_date) }}</span>
                        </span>
                        <span class="gcp-home-row-amount">{{ \App\Support\Money::format($service->price_cents, $service->currency) }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="gcp-home-arrow" />
                    </a>
                @endforeach
            </div>
        </section>
    @endif
    </div>

    @if (filled(config('gaiacp.company.email')))
        <p class="gcp-home-note gcp-home-contact">
            <x-filament::icon icon="heroicon-o-envelope" />
            {{ __('Questions? Write to us at :email.', ['email' => config('gaiacp.company.email')]) }}
        </p>
    @endif
</x-filament-panels::page>
