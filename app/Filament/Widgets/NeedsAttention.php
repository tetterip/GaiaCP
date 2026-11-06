<?php

namespace App\Filament\Widgets;

use App\Enums\DomainRegistrationType;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\CancellationRequests\CancellationRequestResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\ModuleQueue\ModuleQueueItemResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\UpgradeRequests\UpgradeRequestResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\ActivityLog;
use App\Models\CancellationRequest;
use App\Models\ClientChangeRequest;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\ModuleQueueItem;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\UpgradeRequest;
use App\Monitoring\MonitorStatus;
use App\Support\Currencies;
use App\Support\Health\SchedulerMonitor;
use App\Support\Money;
use App\Support\StoredTime;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * What waits on an admin today, each item only for those who may open it: cron stopped (System health), servers down,
 * pending orders, cancellation and upgrade requests, client change requests, tickets waiting longer than TICKET_HOURS,
 * leads to contact today, overdue payment notices and those due today, domains to check at the registrar (auto renew on
 * and the registrar's expiry within DOMAIN_DAYS, or well before the paid-through date), sent quotes ending within
 * QUOTE_DAYS, and what failed in the last FAILURE_DAYS days (panel / registrar calls, backups, emails, updates, online
 * payments that matched no notice). With nothing to show the card is left off the dashboard (isEmpty()).
 */
class NeedsAttention extends Widget
{
    use FitsDashboard;

    public const FAILURE_DAYS = 3;

    public const FAILURES = ['provisioning.failed', 'backup.failed', 'email.failed', 'update.failed', 'payment.online_unmatched'];

    public const TICKET_HOURS = 24;

    public const DOMAIN_DAYS = 14;

    /** How far the registrar's expiry may fall before the paid-through date (registrars round a day or two). */
    public const DOMAIN_SLACK_DAYS = 7;

    public const QUOTE_DAYS = 3;

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected string $view = 'filament.dashboard.needs-attention';

    /**
     * @return list<array{icon: string, tone: string, title: string, sub: ?string, url: ?string}>
     */
    public static function items(): array
    {
        $items = [];

        if (SystemHealth::canAccess() && ($stopped = app(SchedulerMonitor::class)->stopped())) {
            $items[] = [
                'icon' => 'heroicon-o-clock',
                'tone' => 'bad',
                'title' => $stopped['seen']
                    ? __('Cron last ran :time', ['time' => $stopped['since']->diffForHumans()])
                    : __('Cron has not run yet'),
                'sub' => __('The daily jobs wait until it runs. See System health.'),
                'url' => SystemHealth::getUrl(),
            ];
        }

        if (ServerResource::canAccess() && ($down = Server::query()->where('active', true)->where('monitor_status', MonitorStatus::Down))->exists()) {
            $servers = (clone $down)->orderBy('monitor_status_since')->get(['id', 'name', 'monitor_status_since']);
            $items[] = [
                'icon' => 'heroicon-o-server-stack',
                'tone' => 'bad',
                'title' => __(':count server(s) down', ['count' => $servers->count()]),
                'sub' => $servers->pluck('name')->join(', ').' · '.__('Since :time', ['time' => $servers->first()->monitor_status_since?->diffForHumans() ?? '?']),
                'url' => $servers->count() === 1 ? ServerResource::getUrl('edit', ['record' => $servers->first()]) : ServerResource::getUrl(),
            ];
        }

        if (OrderResource::canAccess() && ($pending = Order::query()->where('status', OrderStatus::Pending))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-shopping-cart',
                'tone' => 'warn',
                'title' => __(':count order(s) waiting for Complete order', ['count' => (clone $pending)->count()]),
                'sub' => __('Oldest :time', ['time' => (clone $pending)->oldest()->value('created_at')?->diffForHumans()]),
                'url' => OrderResource::getUrl(parameters: ['filters' => ['status' => ['value' => OrderStatus::Pending->value]]]),
            ];
        }

        if (CancellationRequestResource::canAccess() && ($cancellations = CancellationRequest::query()->pending())->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-x-circle',
                'tone' => 'warn',
                'title' => __(':count cancellation request(s) to answer', ['count' => (clone $cancellations)->count()]),
                'sub' => __('Oldest :time', ['time' => (clone $cancellations)->oldest()->value('created_at')?->diffForHumans()]),
                'url' => CancellationRequestResource::getUrl(),
            ];
        }

        if (UpgradeRequestResource::canAccess() && ($upgrades = UpgradeRequest::query()->pending())->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-arrows-up-down',
                'tone' => 'warn',
                'title' => __(':count upgrade / downgrade request(s) to answer', ['count' => (clone $upgrades)->count()]),
                'sub' => __('Oldest :time', ['time' => (clone $upgrades)->oldest()->value('created_at')?->diffForHumans()]),
                'url' => UpgradeRequestResource::getUrl(),
            ];
        }

        if (ClientResource::canAccess() && ($requests = ClientChangeRequest::query()->pending())->exists()) {
            $latest = (clone $requests)->latest()->with('client')->first();
            $count = (clone $requests)->count();
            $items[] = [
                'icon' => 'heroicon-o-user',
                'tone' => 'info',
                'title' => __(':count client change request(s)', ['count' => $count]),
                'sub' => $latest?->client ? $latest->client->name.' · '.$latest->created_at?->diffForHumans() : null,
                'url' => $count === 1 && $latest?->client ? ClientResource::getUrl('edit', ['record' => $latest->client]) : ClientResource::getUrl(),
            ];
        }

        if (TicketResource::canAccess() && ($tickets = Ticket::query()->waiting()->where('last_reply_at', '<=', now()->subHours(self::TICKET_HOURS)))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'tone' => 'warn',
                'title' => __(':count ticket(s) waiting for a reply over :hours hours', ['count' => (clone $tickets)->count(), 'hours' => self::TICKET_HOURS]),
                'sub' => __('Oldest :time', ['time' => StoredTime::parse((clone $tickets)->min('last_reply_at'))?->diffForHumans()]),
                'url' => TicketResource::getUrl(parameters: ['activeTab' => 'waiting']),
            ];
        }

        if (LeadResource::canAccess() && ($leads = Lead::query()->toContact())->exists()) {
            $count = (clone $leads)->count();
            $first = (clone $leads)->orderBy('next_contact_on')->limit(3)->get();
            $items[] = [
                'icon' => 'heroicon-o-phone',
                'tone' => 'info',
                'title' => __(':count lead(s) to contact today', ['count' => $count]),
                'sub' => $first->map(fn (Lead $lead) => $lead->displayName())->join(', ').($count > 3 ? '…' : ''),
                'url' => $count === 1 ? LeadResource::getUrl('edit', ['record' => $first->first()]) : LeadResource::getUrl(parameters: ['activeTab' => 'to_contact']),
            ];
        }

        if (InvoiceResource::canAccess() && ($overdue = Invoice::query()->unpaid()->whereOnDate('due_date', '<', today()))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-document-text',
                'tone' => 'bad',
                'title' => __(':count overdue payment notice(s)', ['count' => (clone $overdue)->count()]),
                'sub' => Money::format(Currencies::sumToBase($overdue, 'total_cents'), Currencies::base()),
                'url' => InvoiceResource::getUrl(parameters: ['filters' => ['overdue' => ['isActive' => true]]]),
            ];
        }

        if (InvoiceResource::canAccess() && ($dueToday = Invoice::query()->unpaid()->whereOnDate('due_date', today()))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-calendar',
                'tone' => 'info',
                'title' => __(':count payment notice(s) due today', ['count' => (clone $dueToday)->count()]),
                'sub' => Money::format(Currencies::sumToBase($dueToday, 'total_cents'), Currencies::base()),
                'url' => ($first = (clone $dueToday)->value('id')) && (clone $dueToday)->count() === 1
                    ? InvoiceResource::getUrl('view', ['record' => $first])
                    : InvoiceResource::getUrl(parameters: ['filters' => ['status' => ['value' => 'unpaid']]]),
            ];
        }

        if (ServiceResource::canAccess() && ($domains = self::domainsToCheck())->isNotEmpty()) {
            $items[] = [
                'icon' => 'heroicon-o-globe-alt',
                'tone' => 'warn',
                'title' => __(':count domain(s) to check at the registrar', ['count' => $domains->count()]),
                'sub' => $domains->take(3)->pluck('domain')->join(', ').($domains->count() > 3 ? '…' : ''),
                'url' => $domains->count() === 1
                    ? ServiceResource::getUrl('edit', ['record' => $domains->first()])
                    : ServiceResource::getUrl(parameters: ['activeTab' => 'domains']),
            ];
        }

        if (QuoteResource::canAccess() && ($quotes = Quote::query()->where('status', QuoteStatus::Sent)->whereOnDate('valid_until', '>=', today())->whereOnDate('valid_until', '<=', today()->addDays(self::QUOTE_DAYS)))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-document-check',
                'tone' => 'info',
                'title' => __(':count quote(s) ending within :days days', ['count' => (clone $quotes)->count(), 'days' => self::QUOTE_DAYS]),
                'sub' => (clone $quotes)->orderBy('valid_until')->limit(3)->pluck('number')->join(', '),
                'url' => QuoteResource::getUrl(parameters: ['activeTab' => 'open']),
            ];
        }

        if (ModuleQueueItemResource::canAccess() && ($queue = ModuleQueueItem::query()->open())->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-arrow-path',
                'tone' => 'bad',
                'title' => __(':count failed action(s) in the module queue', ['count' => (clone $queue)->count()]),
                'sub' => __('Last failed :time', ['time' => StoredTime::parse((clone $queue)->max('last_attempt_at'))?->diffForHumans()]),
                'url' => ModuleQueueItemResource::getUrl(),
            ];
        }

        if (ActivityLogResource::canAccess()) {
            $failures = ActivityLog::query()->with('service')
                ->whereIn('event', self::FAILURES)
                ->whereNull('properties->retry') // a module queue retry that failed again shows once, in the item above
                ->where('created_at', '>=', now()->subDays(self::FAILURE_DAYS))
                ->latest('id')->limit(4)->get();

            foreach ($failures as $log) {
                $items[] = [
                    'icon' => $log->event === 'email.failed' ? 'heroicon-o-envelope' : 'heroicon-o-exclamation-triangle',
                    'tone' => 'bad',
                    'title' => match ($log->event) {
                        'backup.failed' => __('Backup failed'),
                        'email.failed' => __('Email not sent'),
                        'update.failed' => __('Update failed'),
                        'payment.online_unmatched' => __('Online payment to match by hand'),
                        default => $log->service?->domain
                            ? __('Panel or registrar call failed: :name', ['name' => $log->service->domain])
                            : __('Panel or registrar call failed'),
                    },
                    'sub' => $log->created_at->diffForHumans(),
                    'url' => $log->service_id && ServiceResource::canAccess()
                        ? ServiceResource::getUrl('edit', ['record' => $log->service_id])
                        : ActivityLogResource::getUrl(parameters: ['filters' => ['event' => ['value' => $log->event]]]),
                ];
            }
        }

        return $items;
    }

    /** Nothing waits: the dashboard leaves the card off (it stays under Show / hide cards). */
    public static function isEmpty(): bool
    {
        return self::items() === [];
    }

    /**
     * Active registered or transferred domains with auto renew on whose registrar expiry is near or past, or falls well
     * before the date they are paid up to (paid but not renewed at the registrar). Tracked domains are left out.
     *
     * @return Collection<int, Service>
     */
    public static function domainsToCheck(): Collection
    {
        return Service::query()
            ->select('services.*')
            ->join('domains', 'domains.service_id', '=', 'services.id')
            ->where('services.type', ServiceType::Domain)
            ->where('services.status', ServiceStatus::Active)
            ->where('services.auto_terminate', false)
            ->where('domains.registration_type', '!=', DomainRegistrationType::External)
            ->whereNotNull('domains.expires_at')
            ->where(fn ($query) => $query
                ->whereOnDate('domains.expires_at', '<=', today()->addDays(self::DOMAIN_DAYS))
                ->orWhereRaw('domains.expires_at < '.self::minusDays('services.next_due_date', self::DOMAIN_SLACK_DAYS)))
            ->orderBy('domains.expires_at')
            ->get();
    }

    /** A date column less some days, in the connection's SQL (SQLite in development and tests, MySQL / MariaDB in production). */
    private static function minusDays(string $column, int $days): string
    {
        return Service::query()->getConnection()->getDriverName() === 'sqlite'
            ? "date({$column}, '-{$days} days')"
            : "DATE_SUB({$column}, INTERVAL {$days} DAY)";
    }

    /** @return array<string, mixed> */
    public static function dashboardLabel(): string
    {
        return __('Needs attention');
    }

    protected function getViewData(): array
    {
        return ['items' => self::items()];
    }
}
