{{-- Home, like the admin dashboard: what is owed beside credit and services, then shortcuts and lists. The cards are the
     dashboard's .gx-* (filament.dashboard.styles), the rest filament.themes.client. --}}
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
            : $notices->groupBy('currency')->map(fn ($group, $currency) => \App\Support\Money::format($group->sum('total_cents'), $currency))->join(' + ');
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
                <div @class(['gx-box', 'gx-box-bad' => $overdue->isNotEmpty()])>
                    <div class="gx-box-label">{{ __('Past due') }}</div>
                    <div class="gx-box-value">{{ $sum($overdue) }}</div>
                    <div class="gx-box-sub">{{ __(':count notice(s)', ['count' => $overdue->count()]) }}</div>
                </div>
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
            <section class="fi-section gx-card gcp-home-stat">
                <div class="gx-hero-label">{{ __('Active services') }}</div>
                <div class="gx-big">{{ $this->activeCount() }}</div>
                <div class="gx-muted">{{ __('Hosting, domains and more') }}</div>
            </section>
        </div>
    </div>

    <div class="gcp-home-shortcuts">
        @foreach ($this->shortcuts() as $shortcut)
            <x-filament::button tag="a" :href="$shortcut['url']" :icon="$shortcut['icon']" color="gray" size="sm" outlined>
                {{ $shortcut['label'] }}
            </x-filament::button>
        @endforeach
    </div>

    @if ($unpaid->isNotEmpty())
        <x-filament::section :heading="__('Payment notices to pay')">
            <table class="gcp-home-list">
                @foreach ($unpaid as $notice)
                    <tr>
                        <td><a href="{{ $noticeUrl($notice) }}">{{ $notice->number }}</a></td>
                        <td @class(['gcp-end', 'gcp-overdue' => $notice->isOverdue()])>{{ __('Due :date', ['date' => $date($notice->due_date)]) }}</td>
                        <td class="gcp-end">{{ \App\Support\Money::format($notice->total_cents, $notice->currency) }}</td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif

    @if ($upcoming->isNotEmpty())
        <x-filament::section :heading="__('Coming up for renewal')">
            <table class="gcp-home-list">
                @foreach ($upcoming as $service)
                    <tr>
                        <td><a href="{{ $this->serviceUrl($service) }}">{{ $service->label }}</a></td>
                        <td @class(['gcp-end', 'gcp-overdue' => $service->isOverdue()])>{{ $date($service->next_due_date) }}</td>
                        <td class="gcp-end">{{ \App\Support\Money::format($service->price_cents, $service->currency) }}</td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif

    @if (filled(config('gaiacp.company.email')))
        <p class="gcp-home-note">
            {{ __('Questions? Write to us at :email.', ['email' => config('gaiacp.company.email')]) }}
        </p>
    @endif
</x-filament-panels::page>
