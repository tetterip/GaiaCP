<?php

namespace App\Filament\Client\Pages;

use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\CertificateStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\TicketStatus;
use App\Filament\Client\Actions\HostingSelfService;
use App\Filament\Client\Resources\Domains\DomainResource;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Quotes\QuoteResource;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use App\Monitoring\MonitorStatus;
use App\Providers\AppServiceProvider;
use App\Support\Currencies;
use App\Support\Greeting;
use App\Support\MailTemplates;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The client area's home page: what waits for the client (waiting()), what they owe (each notice with its Pay, or one
 * line when all is paid), their services with the way into each one's control panel, what renews next and their open
 * tickets. A client with no service yet gets Start here (isNew(), starters()) in place of the empty cards.
 */
class Dashboard extends Page
{
    use TranslatesPageLabels;

    /** How many upcoming renewals Home lists. */
    public const UPCOMING = 5;

    /** How many services and unpaid notices Home lists before pointing to the whole list. */
    public const SERVICES = 5;

    public const NOTICES = 4;

    /** How many rows "Waiting for you" shows, open tickets the Support card lists, and products Start here offers. */
    public const WAITING = 6;

    public const TICKETS = 3;

    public const STARTERS = 3;

    /** A domain with auto renew off is listed under "Waiting for you" this many days before it ends. */
    public const DOMAIN_DAYS = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $title = 'Home';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.client.dashboard';

    /** @var Collection<int, Invoice>|null read once per request (the cards and "Waiting for you" all ask) */
    private ?Collection $unpaid = null;

    /** "Good afternoon, Maria", like the admin dashboard; the page title without a first name. */
    public function getHeading(): string|Htmlable
    {
        $name = trim((string) $this->client()?->first_name);

        return $name === '' ? $this->getTitle() : Greeting::for($name);
    }

    public function getSubheading(): ?string
    {
        return now()->locale(app()->getLocale())->translatedFormat('l j F');
    }

    public function client(): ?Client
    {
        return filament()->auth()->user()?->client;
    }

    /**
     * Messages to clients shown in the client area today that match this client, newest first, in their language.
     *
     * @return list<array{subject: string, body: HtmlString}>
     */
    public function announcements(): array
    {
        $client = $this->client();

        if (! $client) {
            return [];
        }

        $values = MailTemplates::common($client);

        return ClientMessage::query()->showing()->latest()->get()
            ->filter(fn (ClientMessage $message) => $message->matches($client))
            ->map(function (ClientMessage $message) use ($client, $values) {
                $text = $message->text($client->preferredLocale());

                return [
                    'subject' => html_entity_decode(strip_tags(MailTemplates::fill($text['subject'], $values)), ENT_QUOTES),
                    // No HTML: written by any admin with the Clients area, and shown inside the client area (also to admins looking as the client)
                    'body' => new HtmlString(Str::markdown(MailTemplates::fill($text['body'], $values), ['html_input' => 'strip', 'allow_unsafe_links' => false])),
                ];
            })
            ->values()->all();
    }

    /** @return Collection<int, Invoice> unpaid payment notices, the oldest due first */
    public function unpaidNotices(): Collection
    {
        return $this->unpaid ??= PaymentNoticeResource::getEloquentQuery()->unpaid()->withOwed()->orderBy('due_date')->get();
    }

    /** Active services and domains shown in the client area. */
    public function activeCount(): int
    {
        return $this->visibleServices()->where('status', ServiceStatus::Active)->count();
    }

    /**
     * The active services per kind, as the sidebar splits them, each with its list; a kind with none is left out.
     *
     * @return list<array{label: string, count: int, url: string, icon: Heroicon}>
     */
    public function activeByKind(): array
    {
        $types = $this->visibleServices()->where('status', ServiceStatus::Active)->toBase()
            ->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $hosting = (int) $types->get(ServiceType::Hosting->value, 0);
        $domains = (int) $types->get(ServiceType::Domain->value, 0);
        $other = (int) $types->sum() - $hosting - $domains;

        return array_values(array_filter([
            $hosting > 0 ? ['label' => __('Hosting'), 'count' => $hosting, 'url' => ServiceResource::getUrl('index', ['kind' => 'hosting']), 'icon' => Heroicon::OutlinedServerStack] : null,
            $domains > 0 ? ['label' => __('Domains'), 'count' => $domains, 'url' => DomainResource::getUrl(), 'icon' => Heroicon::OutlinedGlobeAlt] : null,
            $other > 0 ? ['label' => __('Other services'), 'count' => $other, 'url' => ServiceResource::getUrl('index', ['kind' => 'other']), 'icon' => Heroicon::OutlinedWrenchScrewdriver] : null,
        ]));
    }

    /**
     * The client's services Home lists first: hosting before domains before the rest, a suspended one before the active
     * ones of its kind, each with its server's state dot (as the Hosting list shows it) and, where the panel lets
     * clients in, the link that logs them in to it.
     *
     * @return list<array{service: Service, url: string, state: array{status: MonitorStatus, text: string}|null, login: ?string, panel: ?string}>
     */
    public function services(): array
    {
        $kind = [ServiceType::Hosting->value => 0, ServiceType::Domain->value => 1];

        return $this->visibleServices()
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->with('server')
            ->orderBy('next_due_date')
            ->get()
            ->sortBy(fn (Service $service) => [$kind[$service->type->value] ?? 2, $service->status === ServiceStatus::Suspended ? 0 : 1])
            ->take(self::SERVICES)
            ->map(fn (Service $service) => [
                'service' => $service,
                'url' => $this->serviceUrl($service),
                'state' => ServiceResource::serverState($service),
                'login' => HostingSelfService::canLogIn($service) ? route('filament.client.services.panel-login', ['service' => $service->id]) : null,
                'panel' => HostingSelfService::canLogIn($service) ? HostingSelfService::panelName($service) : null,
            ])
            ->values()->all();
    }

    /**
     * The next renewals of services and domains still billed. A domain with auto renew off is listed too (it ends on
     * that day unless they renew it); other services set to end with their period are left out.
     *
     * @return Collection<int, Service>
     */
    public function upcoming(): Collection
    {
        return $this->visibleServices()
            ->billable()
            ->where(fn (Builder $query) => $query->where('auto_terminate', false)->orWhere('type', ServiceType::Domain))
            ->orderBy('next_due_date')
            ->limit(self::UPCOMING)
            ->get();
    }

    /**
     * What waits for the client to do something, the most pressing first; empty when nothing does. Each row has the
     * one page that settles it.
     *
     * @return list<array{text: string, detail: ?string, action: string, url: string, tone: string, icon: string}>
     */
    public function waiting(): array
    {
        $client = $this->client();

        if (! $client) {
            return [];
        }

        $rows = [];
        $date = fn ($value) => $value->format(AppServiceProvider::dateFormat());
        $name = fn (Service $service) => $service->domain ?: $service->label;
        $row = fn (string $text, ?string $detail, string $action, string $url, string $tone, string $icon) => compact('text', 'detail', 'action', 'url', 'tone', 'icon');

        $overdue = $this->unpaidNotices()->filter(fn (Invoice $notice) => $notice->isOverdue())->values();

        if ($overdue->isNotEmpty()) {
            $owed = $overdue->groupBy('currency')->map(fn (Collection $group, string $currency) => Money::format((int) $group->sum(fn (Invoice $notice) => (int) ($notice->owed_cents ?? $notice->total_cents)), $currency))->join(' + ');
            $rows[] = $row(
                trans_choice(':count payment notice is past due|:count payment notices are past due', $overdue->count()),
                $overdue->count() === 1 ? __(':number, was due :date', ['number' => $overdue->first()->number, 'date' => $date($overdue->first()->due_date)]) : $owed,
                __('Pay'),
                $overdue->count() === 1 ? PaymentNoticeResource::getUrl('view', ['record' => $overdue->first()]) : PaymentNoticeResource::getUrl(),
                'bad', 'heroicon-o-banknotes',
            );
        }

        foreach ($this->visibleServices()->where('status', ServiceStatus::Suspended)->orderBy('next_due_date')->get() as $service) {
            $rows[] = $row(__(':name is suspended', ['name' => $name($service)]), $overdue->isNotEmpty() ? __('It comes back once its payment notice is paid') : null,
                __('View'), $this->serviceUrl($service), 'bad', 'heroicon-o-pause-circle');
        }

        if (TicketResource::canAccess()) {
            foreach (Ticket::query()->where('client_id', $client->id)->where('status', TicketStatus::Answered)->latest('last_reply_at')->get() as $ticket) {
                $rows[] = $row(__('We answered your ticket :number', ['number' => $ticket->number]), Str::limit((string) $ticket->subject, 70),
                    __('Read'), TicketResource::getUrl('view', ['record' => $ticket]), 'info', 'heroicon-o-chat-bubble-left-right');
            }
        }

        foreach (QuoteResource::getEloquentQuery()->where('status', QuoteStatus::Sent)->whereOnDate('valid_until', '>=', today())->orderBy('valid_until')->get() as $quote) {
            /** @var Quote $quote */
            $rows[] = $row(__('Quote :number waits for your answer', ['number' => $quote->number]), __('Valid until :date', ['date' => $date($quote->valid_until)]),
                __('View'), QuoteResource::getUrl(), 'info', 'heroicon-o-document-check');
        }

        $certificates = $this->visibleServices()->where('type', ServiceType::Ssl)->where('status', ServiceStatus::Active)
            ->whereHas('certificateDetails', fn (Builder $query) => $query->where('status', CertificateStatus::Pending))->get();

        foreach ($certificates as $service) {
            $rows[] = $row(__('The SSL certificate of :name waits for validation', ['name' => $name($service)]), null,
                __('Validate'), $this->serviceUrl($service), 'warn', 'heroicon-o-lock-closed');
        }

        $ending = $this->visibleServices()->billable()->where('type', ServiceType::Domain)->where('auto_terminate', true)
            ->whereOnDate('next_due_date', '<=', today()->addDays(self::DOMAIN_DAYS))->orderBy('next_due_date')->get();

        foreach ($ending as $service) {
            $rows[] = $row(__(':name ends on :date', ['name' => $name($service), 'date' => $date($service->next_due_date)]), __('Does not renew automatically'),
                __('Renew'), $this->serviceUrl($service), 'warn', 'heroicon-o-globe-alt');
        }

        $usage = DB::table('usage_warnings')->where('period', today()->format('Y-m'))
            ->whereIn('service_id', $this->visibleServices()->where('status', ServiceStatus::Active)->select('id'))
            ->orderByDesc('percent')->get()->groupBy('service_id');

        if ($usage->isNotEmpty()) {
            $services = Service::query()->findMany($usage->keys())->keyBy('id');

            foreach ($usage as $id => $kinds) {
                $rows[] = $row(__(':name is close to its limit', ['name' => $name($services[$id])]),
                    $kinds->map(fn (object $warning) => $warning->kind === 'disk' ? __('Disk space :percent%', ['percent' => $warning->percent]) : __('Bandwidth :percent%', ['percent' => $warning->percent]))->join(' · '),
                    __('View'), $this->serviceUrl($services[$id]), 'warn', 'heroicon-o-chart-pie');
            }
        }

        if ($client->changeRequests()->pending()->exists()) {
            $rows[] = $row(__('The changes to your details wait for our approval'), null, __('Your profile'), (string) filament()->getProfileUrl(), 'muted', 'heroicon-o-user-circle');
        }

        return $rows;
    }

    /**
     * The client's tickets that are not closed, the answered ones (their turn) first; empty while clients have no
     * tickets in the client area.
     *
     * @return Collection<int, Ticket>
     */
    public function tickets(): Collection
    {
        if (! TicketResource::canAccess()) {
            return new Collection;
        }

        return Ticket::query()->where('client_id', $this->client()?->id)->active()->latest('last_reply_at')->get()
            ->sortBy(fn (Ticket $ticket) => $ticket->status === TicketStatus::Answered ? 0 : 1)
            ->take(self::TICKETS)->values();
    }

    /** Nothing here yet: no service of any kind and nothing to pay, so Home shows Start here in place of empty cards. */
    public function isNew(): bool
    {
        return $this->client() !== null && ! $this->visibleServices()->exists() && $this->unpaidNotices()->isEmpty();
    }

    /**
     * The products Start here offers a new client: the ones marked Popular, each with its lowest price.
     *
     * @return list<array{name: string, description: ?string, price: ?string, url: string}>
     */
    public function starters(): array
    {
        if (! Order::canAccess()) {
            return [];
        }

        $currency = Currencies::forClient($this->client());

        return ClientOrder::products()->where('popular', true)->take(self::STARTERS)
            ->map(function (Product $product) use ($currency) {
                /** @var BillingCycle|null $cycle */
                $cycle = collect($product->cycles())->sortBy(fn (BillingCycle $cycle) => $product->orderPriceFor($cycle, $currency) / max(1, $cycle->months()))->first();

                return [
                    'name' => $product->localName(),
                    'description' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $product->localDescription()), ENT_QUOTES | ENT_HTML5))), 90) ?: null,
                    'price' => $cycle ? __('From :price', ['price' => Money::format((int) $product->orderPriceFor($cycle, $currency), $currency).' / '.$cycle->getLabel()]) : null,
                    'url' => Order::getUrl(['product' => $product->id]),
                ];
            })
            ->values()->all();
    }

    /** The address their payment notices and domains need is not filled in yet. */
    public function profileIncomplete(): bool
    {
        $client = $this->client();

        return $client !== null && (blank($client->address1) || blank($client->city) || blank($client->postcode));
    }

    /** Where Home's domain search field sends the name typed (its `q`), when clients search for domains. */
    public function domainSearchUrl(): ?string
    {
        return SearchDomains::canAccess() ? SearchDomains::getUrl() : null;
    }

    /** Where Home's article search sends the words typed (its `q`), while the knowledge base is on. */
    public function knowledgeBaseUrl(): ?string
    {
        return KnowledgeBase::canAccess() ? KnowledgeBase::getUrl() : null;
    }

    /**
     * Home's shortcuts, by what a client comes to do (the menu lists the pages). Shown on a phone, where the menu is
     * folded away.
     *
     * @return list<array{label: string, url: string, icon: Heroicon}>
     */
    public function shortcuts(): array
    {
        return array_values(array_filter([
            Order::canAccess() ? ['label' => __('Order'), 'url' => Order::getUrl(), 'icon' => Heroicon::OutlinedShoppingCart] : null,
            TicketResource::canAccess() ? ['label' => __('New ticket'), 'url' => TicketResource::getUrl('create'), 'icon' => Heroicon::OutlinedChatBubbleLeftRight] : null,
            SearchDomains::canAccess() ? ['label' => __('Register a domain'), 'url' => SearchDomains::getUrl(), 'icon' => Heroicon::OutlinedGlobeAlt] : null,
            Credit::canAccess() ? ['label' => __('Add funds'), 'url' => Credit::getUrl(), 'icon' => Heroicon::OutlinedWallet] : null,
            ['label' => __('Your profile'), 'url' => filament()->getProfileUrl(), 'icon' => Heroicon::OutlinedUserCircle],
        ]));
    }

    public function serviceUrl(Service $service): string
    {
        return $service->type === ServiceType::Domain
            ? DomainResource::getUrl('view', ['record' => $service])
            : ServiceResource::getUrl('view', ['record' => $service]);
    }

    /** @return Builder<Service> */
    private function visibleServices()
    {
        return Service::query()->where('client_id', $this->client()?->id)->clientVisible();
    }
}
