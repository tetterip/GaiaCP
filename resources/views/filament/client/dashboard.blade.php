{{-- Home: what waits for the client (only when something does), then what is owed, each notice with its Pay (one low line
     when all is paid), beside the client's services with the way into each one's control panel; the domain search, the
     shortcuts (a phone only), what renews next and the Support card. A client with no service yet gets Start here in
     place of the two cards. The cards are the dashboard's .gx-* (filament.dashboard.styles), the rest filament.themes.client. --}}
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
        $waiting = $this->waiting();
        $new = $this->isNew();
        $domainSearch = $this->domainSearchUrl();
        $ticketsOn = \App\Filament\Client\Resources\Tickets\TicketResource::canAccess();
        $kb = $this->knowledgeBaseUrl();
        $tickets = $this->tickets();
    @endphp

    @if ($waiting)
        <section class="fi-section gx-card gcp-home-card gcp-home-waiting">
            <div class="gx-head">
                <span class="gx-ico gx-tone-warn">@svg('heroicon-o-bell-alert', 'gx-ico-svg')</span>
                <h2 class="gx-title">{{ __('Waiting for you') }}</h2>
            </div>
            <div class="gcp-home-rows">
                @foreach (array_slice($waiting, 0, \App\Filament\Client\Pages\Dashboard::WAITING) as $item)
                    <a href="{{ $item['url'] }}" class="gcp-home-row gcp-home-wait gcp-home-wait-{{ $item['tone'] }}">
                        <x-filament::icon :icon="$item['icon']" class="gcp-home-wait-icon" />
                        <span class="gcp-home-row-text">
                            <strong>{{ $item['text'] }}</strong>
                            @if ($item['detail'])
                                <span>{{ $item['detail'] }}</span>
                            @endif
                        </span>
                        <span class="gcp-home-row-open">{{ $item['action'] }}</span>
                    </a>
                @endforeach
            </div>
            @if (count($waiting) > \App\Filament\Client\Pages\Dashboard::WAITING)
                <div class="gx-muted">{{ __('and :count more', ['count' => count($waiting) - \App\Filament\Client\Pages\Dashboard::WAITING]) }}</div>
            @endif
        </section>
    @endif

    @if ($new)
        @php($starters = $this->starters())
        <section class="fi-section gx-card gcp-home-card gcp-home-start">
            <div class="gx-head">
                <span class="gx-ico">@svg('heroicon-o-sparkles', 'gx-ico-svg')</span>
                <h2 class="gx-title">{{ __('Start here') }}</h2>
            </div>
            <p class="gx-muted">{{ __('You have no services yet. Pick one to begin, or look for your domain name.') }}</p>
            @if ($domainSearch)
                <form method="get" action="{{ $domainSearch }}" class="gcp-domain-search gcp-home-search">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-globe-alt" class="gcp-domain-search-input">
                        <x-filament::input type="search" name="q" :placeholder="__('Find your domain, e.g. myshop.gr')" :aria-label="__('Search for a domain')" autocomplete="off" required />
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">{{ __('Search') }}</x-filament::button>
                </form>
            @endif
            @if ($starters)
                <div class="gcp-home-starters">
                    @foreach ($starters as $product)
                        <a href="{{ $product['url'] }}" class="gcp-home-starter">
                            <span class="gcp-home-starter-badge">{{ __('Popular') }}</span>
                            <strong>{{ $product['name'] }}</strong>
                            @if ($product['description'])
                                <span class="gx-muted">{{ $product['description'] }}</span>
                            @endif
                            @if ($product['price'])
                                <span class="gcp-home-starter-price">{{ $product['price'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
            <div class="gcp-home-start-actions">
                @if (\App\Filament\Client\Pages\Order::canAccess())
                    <x-filament::button tag="a" :href="\App\Filament\Client\Pages\Order::getUrl()" :color="$starters ? 'gray' : 'primary'" :outlined="(bool) $starters" icon="heroicon-m-shopping-cart">{{ __('See everything we offer') }}</x-filament::button>
                @endif
                @if ($this->profileIncomplete())
                    <x-filament::button tag="a" :href="filament()->getProfileUrl()" color="gray" outlined icon="heroicon-m-user-circle">{{ __('Complete your profile') }}</x-filament::button>
                @endif
            </div>
        </section>
    @else
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

    @if ($domainSearch)
        <form method="get" action="{{ $domainSearch }}" class="gcp-domain-search gcp-home-search gcp-home-search-bar">
            <x-filament::input.wrapper prefix-icon="heroicon-o-globe-alt" class="gcp-domain-search-input">
                <x-filament::input type="search" name="q" :placeholder="__('Find your domain, e.g. myshop.gr')" :aria-label="__('Search for a domain')" autocomplete="off" required />
            </x-filament::input.wrapper>
            <x-filament::button type="submit" color="gray" icon="heroicon-m-magnifying-glass">{{ __('Search') }}</x-filament::button>
        </form>
    @endif
    @endif

    <nav class="gcp-home-tiles" aria-label="{{ __('Shortcuts') }}">
        @foreach ($this->shortcuts() as $shortcut)
            <a href="{{ $shortcut['url'] }}" class="gcp-home-tile">
                <span class="gcp-home-tile-icon"><x-filament::icon :icon="$shortcut['icon']" /></span>
                <span>{{ $shortcut['label'] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($upcoming->isNotEmpty() || $ticketsOn || $kb)
    <div @class(['gcp-home-grid', 'gcp-home-grid-one' => $upcoming->isEmpty() || ! ($ticketsOn || $kb)])>
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

        @if ($ticketsOn || $kb)
            <section class="fi-section gx-card gcp-home-card gcp-home-support">
                <div class="gx-head">
                    <span class="gx-ico">@svg('heroicon-o-lifebuoy', 'gx-ico-svg')</span>
                    <h2 class="gx-title">{{ __('Support') }}</h2>
                    @if ($ticketsOn)
                        <x-filament::button tag="a" :href="\App\Filament\Client\Resources\Tickets\TicketResource::getUrl('create')" color="gray" size="sm" outlined icon="heroicon-m-plus" class="gcp-home-order">{{ __('New ticket') }}</x-filament::button>
                    @endif
                </div>
                @if ($tickets->isNotEmpty())
                    <div class="gcp-home-rows">
                        @foreach ($tickets as $ticket)
                            <a href="{{ \App\Filament\Client\Resources\Tickets\TicketResource::getUrl('view', ['record' => $ticket]) }}" class="gcp-home-row">
                                <span class="gcp-home-row-text">
                                    <strong>{{ $ticket->subject }}</strong>
                                    <span>{{ $ticket->number }} · {{ ($ticket->last_reply_at ?? $ticket->created_at)->diffForHumans() }}</span>
                                </span>
                                <span @class(['gcp-home-flag', 'gcp-home-flag-info' => $ticket->status === \App\Enums\TicketStatus::Answered, 'gcp-home-flag-muted' => $ticket->status !== \App\Enums\TicketStatus::Answered])>
                                    {{ match ($ticket->status) {
                                        \App\Enums\TicketStatus::Answered => __('Answered'),
                                        \App\Enums\TicketStatus::Open => __('Waiting for us'),
                                        default => $ticket->status->getLabel(),
                                    } }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                @elseif ($ticketsOn)
                    <div class="gx-muted">{{ __('No open tickets. Write to us whenever you need help.') }}</div>
                @endif
                @if ($kb)
                    <form method="get" action="{{ $kb }}" class="gcp-domain-search gcp-home-search">
                        <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" class="gcp-domain-search-input">
                            <x-filament::input type="search" name="q" :placeholder="__('Search the articles')" :aria-label="__('Search the articles')" autocomplete="off" required />
                        </x-filament::input.wrapper>
                        <x-filament::button type="submit" color="gray">{{ __('Search') }}</x-filament::button>
                    </form>
                @endif
                @if ($ticketsOn && $tickets->isNotEmpty())
                    <div class="gcp-home-foot">
                        <a class="gx-link" href="{{ \App\Filament\Client\Resources\Tickets\TicketResource::getUrl() }}">{{ __('All tickets') }}</a>
                    </div>
                @endif
            </section>
        @endif
    </div>
    @endif

    @if (! $ticketsOn && filled(config('gaiacp.company.email')))
        <p class="gcp-home-note gcp-home-contact">
            <x-filament::icon icon="heroicon-o-envelope" />
            {{ __('Questions? Write to us at :email.', ['email' => config('gaiacp.company.email')]) }}
        </p>
    @endif
</x-filament-panels::page>
