<?php

namespace App\Filament\Widgets;

use App\Actions\CompleteOrder;
use App\Actions\ModuleQueue;
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
use App\Provisioning\ProvisioningException;
use App\Support\Currencies;
use App\Support\Health\SchedulerMonitor;
use App\Support\Money;
use App\Support\StoredTime;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What waits on an admin today, each item only for those who may open it: cron stopped (System health), servers down,
 * pending orders, cancellation and upgrade requests, client change requests, tickets waiting longer than TICKET_HOURS,
 * leads to contact today, overdue payment notices and those due today, domains to check at the registrar (auto renew on
 * and the registrar's expiry within DOMAIN_DAYS, or well before the paid-through date), sent quotes ending within
 * QUOTE_DAYS, and what failed in the last FAILURE_DAYS days (panel / registrar calls, backups, emails, updates, online
 * payments that matched no notice; one row per kind with their count). The urgent rows come first; the first SHOWN show,
 * the rest unfold. With nothing to show the card is left off the dashboard (isEmpty()). It starts in the row across the
 * top of the dashboard (DashboardLayout::TOP), where its rows sit in two columns on a wide screen.
 */
class NeedsAttention extends Widget implements HasActions, HasSchemas
{
    use FitsDashboard;
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** The rows shown at first; the rest wait under "Show … more". */
    public const SHOWN = 6;

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
     * A row's `action` is the one thing done right on it: a link (`url`) or one of the card's own actions (`call`).
     *
     * @return list<array{icon: string, tone: string, title: string, sub: ?string, url: ?string, action?: array{label: string, url?: string, call?: string}|null}>
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
                'title' => trans_choice(':count server down|:count servers down', $servers->count()),
                'sub' => $servers->pluck('name')->join(', ').' · '.__('Since :time', ['time' => $servers->first()->monitor_status_since?->diffForHumans() ?? '?']),
                'url' => $servers->count() === 1 ? ServerResource::getUrl('edit', ['record' => $servers->first()]) : ServerResource::getUrl(),
            ];
        }

        if (OrderResource::canAccess() && ($pending = Order::query()->where('status', OrderStatus::Pending))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-shopping-cart',
                'tone' => 'warn',
                'title' => trans_choice(':count order waiting for Complete order|:count orders waiting for Complete order', (clone $pending)->count()),
                'sub' => __('Oldest :time', ['time' => (clone $pending)->oldest()->value('created_at')?->diffForHumans()]),
                'url' => OrderResource::getUrl(parameters: ['filters' => ['status' => ['value' => OrderStatus::Pending->value]]]),
                'action' => ['label' => (clone $pending)->count() === 1 ? __('Complete it') : __('Complete them'), 'call' => 'completeOrders'],
            ];
        }

        if (CancellationRequestResource::canAccess() && ($cancellations = CancellationRequest::query()->pending())->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-x-circle',
                'tone' => 'warn',
                'title' => trans_choice(':count cancellation request to answer|:count cancellation requests to answer', (clone $cancellations)->count()),
                'sub' => __('Oldest :time', ['time' => (clone $cancellations)->oldest()->value('created_at')?->diffForHumans()]),
                'url' => CancellationRequestResource::getUrl(),
            ];
        }

        if (UpgradeRequestResource::canAccess() && ($upgrades = UpgradeRequest::query()->pending())->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-arrows-up-down',
                'tone' => 'warn',
                'title' => trans_choice(':count upgrade / downgrade request to answer|:count upgrade / downgrade requests to answer', (clone $upgrades)->count()),
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
                'title' => trans_choice(':count client change request|:count client change requests', $count),
                'sub' => $latest?->client ? $latest->client->name.' · '.$latest->created_at?->diffForHumans() : null,
                'url' => $count === 1 && $latest?->client ? ClientResource::getUrl('edit', ['record' => $latest->client]) : ClientResource::getUrl(),
            ];
        }

        if (TicketResource::canAccess() && ($tickets = Ticket::query()->waiting()->where('last_reply_at', '<=', now()->subHours(self::TICKET_HOURS)))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'tone' => 'warn',
                'title' => trans_choice(':count ticket waiting for a reply over :hours hours|:count tickets waiting for a reply over :hours hours', (clone $tickets)->count(), ['hours' => self::TICKET_HOURS]),
                'sub' => __('Oldest :time', ['time' => StoredTime::parse((clone $tickets)->min('last_reply_at'))?->diffForHumans()]),
                'url' => TicketResource::getUrl(parameters: ['activeTab' => 'waiting']),
                // Straight to the one that waited longest, where the reply box is
                'action' => ['label' => __('Reply'), 'url' => TicketResource::getUrl('view', ['record' => (clone $tickets)->orderBy('last_reply_at')->value('id')])],
            ];
        }

        if (LeadResource::canAccess() && ($leads = Lead::query()->toContact())->exists()) {
            $count = (clone $leads)->count();
            $first = (clone $leads)->orderBy('next_contact_on')->limit(3)->get();
            $items[] = [
                'icon' => 'heroicon-o-phone',
                'tone' => 'info',
                'title' => trans_choice(':count lead to contact today|:count leads to contact today', $count),
                'sub' => $first->map(fn (Lead $lead) => $lead->displayName())->join(', ').($count > 3 ? '…' : ''),
                'url' => $count === 1 ? LeadResource::getUrl('edit', ['record' => $first->first()]) : LeadResource::getUrl(parameters: ['activeTab' => 'to_contact']),
            ];
        }

        if (InvoiceResource::canAccess() && ($overdue = Invoice::query()->unpaid()->whereOnDate('due_date', '<', today()))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-document-text',
                'tone' => 'bad',
                'title' => trans_choice(':count overdue payment notice|:count overdue payment notices', (clone $overdue)->count()),
                'sub' => Money::format(Invoice::owedInBase($overdue), Currencies::base()),
                'url' => InvoiceResource::getUrl(parameters: ['filters' => ['overdue' => ['isActive' => true]]]),
            ];
        }

        if (InvoiceResource::canAccess() && ($dueToday = Invoice::query()->unpaid()->whereOnDate('due_date', today()))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-calendar',
                'tone' => 'info',
                'title' => trans_choice(':count payment notice due today|:count payment notices due today', (clone $dueToday)->count()),
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
                'title' => trans_choice(':count domain to check at the registrar|:count domains to check at the registrar', $domains->count()),
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
                'title' => trans_choice(':count quote ending within :days days|:count quotes ending within :days days', (clone $quotes)->count(), ['days' => self::QUOTE_DAYS]),
                'sub' => (clone $quotes)->orderBy('valid_until')->limit(3)->pluck('number')->join(', '),
                'url' => QuoteResource::getUrl(parameters: ['activeTab' => 'open']),
            ];
        }

        if (ModuleQueueItemResource::canAccess() && ($queue = ModuleQueueItem::query()->open())->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-arrow-path',
                'tone' => 'bad',
                'title' => trans_choice(':count failed action in the module queue|:count failed actions in the module queue', (clone $queue)->count()),
                'sub' => __('Last failed :time', ['time' => StoredTime::parse((clone $queue)->max('last_attempt_at'))?->diffForHumans()]),
                'url' => ModuleQueueItemResource::getUrl(),
                'action' => self::retryable()->exists() ? ['label' => __('Retry'), 'call' => 'retryQueue'] : null,
            ];
        }

        if (ActivityLogResource::canAccess()) {
            // One row per kind of failure, however many there were (a server that is down fails every call to it).
            $failures = ActivityLog::query()
                ->whereIn('event', self::FAILURES)
                ->whereNull('properties->retry') // a module queue retry that failed again shows once, in the item above
                ->where('created_at', '>=', now()->subDays(self::FAILURE_DAYS));

            $counts = (clone $failures)->toBase()->selectRaw('event, count(*) as failures, max(id) as latest')->groupBy('event')->orderByDesc('latest')->get();
            $latest = ActivityLog::query()->with('service')->findMany($counts->pluck('latest'))->keyBy('id');

            foreach ($counts as $row) {
                $log = $latest[$row->latest] ?? null;
                if (! $log) {
                    continue;
                }

                $count = (int) $row->failures;
                $list = ActivityLogResource::getUrl(parameters: ['filters' => ['event' => ['value' => $log->event]]]);
                $domains = $log->event === 'provisioning.failed' && $count > 1
                    ? Service::query()->whereIn('id', (clone $failures)->where('event', $log->event)->whereNotNull('service_id')->latest('id')->limit(20)->pluck('service_id'))
                        ->whereNotNull('domain')->limit(3)->pluck('domain')
                    : collect();

                $items[] = [
                    'icon' => $log->event === 'email.failed' ? 'heroicon-o-envelope' : 'heroicon-o-exclamation-triangle',
                    'tone' => 'bad',
                    'title' => $count > 1
                        ? match ($log->event) {
                            'backup.failed' => __(':count backups failed', ['count' => $count]),
                            'email.failed' => __(':count emails not sent', ['count' => $count]),
                            'update.failed' => __(':count updates failed', ['count' => $count]),
                            'payment.online_unmatched' => __(':count online payments to match by hand', ['count' => $count]),
                            default => __(':count panel or registrar calls failed', ['count' => $count]),
                        }
                    : match ($log->event) {
                        'backup.failed' => __('Backup failed'),
                        'email.failed' => __('Email not sent'),
                        'update.failed' => __('Update failed'),
                        'payment.online_unmatched' => __('Online payment to match by hand'),
                        default => $log->service?->domain
                            ? __('Panel or registrar call failed: :name', ['name' => $log->service->domain])
                            : __('Panel or registrar call failed'),
                    },
                    'sub' => $count > 1
                        ? ($domains->isNotEmpty() ? $domains->join(', ').($count > $domains->count() ? '…' : '').' · ' : '').__('Last :time', ['time' => $log->created_at->diffForHumans()])
                        : $log->created_at->diffForHumans(),
                    'url' => $count === 1 && $log->service_id && ServiceResource::canAccess()
                        ? ServiceResource::getUrl('edit', ['record' => $log->service_id])
                        : $list,
                ];
            }
        }

        // The urgent rows (red) first, then what waits on an answer, then the rest; each keeps its place among its own.
        $order = ['bad' => 0, 'warn' => 1];
        $items = collect($items)->sortBy(fn (array $item) => $order[$item['tone']] ?? 2)->values()->all();

        return $items;
    }

    /**
     * Complete order on every pending order, right from its row: each item on the registrar and server it would get by
     * itself, as the Orders list's bulk action does (an order that needs a choice is completed from its own page).
     */
    public function completeOrdersAction(): Action
    {
        return Action::make('completeOrders')
            ->label(__('Complete order'))
            ->requiresConfirmation()
            ->modalHeading(fn () => trans_choice('Complete :count order|Complete :count orders', Order::query()->where('status', OrderStatus::Pending)->count()))
            ->modalDescription(__('Each item is set up as Complete order would, on the registrar and server it would get by itself; registrations and transfers charge your registrar account. An item that fails stays pending and is named below.'))
            ->modalSubmitActionLabel(__('Complete them'))
            ->visible(fn () => OrderResource::canAccess())
            ->action(function () {
                $done = 0;
                $problems = [];

                foreach (Order::query()->where('status', OrderStatus::Pending)->oldest()->get() as $order) {
                    try {
                        $failures = app(CompleteOrder::class)->handle($order);
                    } catch (ProvisioningException $e) {
                        $failures = [0 => $e->getMessage()];
                    }

                    if ($failures === []) {
                        $done++;

                        continue;
                    }

                    $labels = Service::query()->whereKey(array_keys($failures))->pluck('label', 'id');
                    $problems[] = __('Order #:id', ['id' => $order->id]).': '.collect($failures)
                        ->map(fn (string $error, int $id) => (isset($labels[$id]) ? "{$labels[$id]}: " : '').Str::finish(rtrim($error), '.'))->join(' ');
                }

                $notification = Notification::make()
                    ->title(trans_choice(':count order completed|:count orders completed', $done))
                    ->body($problems === [] ? null : implode("\n", $problems))
                    ->status($problems === [] ? 'success' : 'warning');

                // What failed stays on screen until read
                ($problems === [] ? $notification : $notification->persistent())->send();
            });
    }

    /** Retry on the module queue's row: every open item tried again now, except terminations (those ask for a password, in the queue). */
    public function retryQueueAction(): Action
    {
        return Action::make('retryQueue')
            ->label(__('Retry'))
            ->requiresConfirmation()
            ->modalHeading(__('Retry'))
            ->modalDescription(__('Every open item is tried again now, domain renewals too. Terminations wait in the module queue.'))
            ->modalSubmitActionLabel(__('Retry'))
            ->visible(fn () => ModuleQueueItemResource::canAccess())
            ->action(function () {
                $queue = app(ModuleQueue::class);
                $items = self::retryable()->with('service')->orderBy('id')->get();
                $fixed = $items->filter(fn (ModuleQueueItem $item) => $queue->retry($item))->count();
                $failed = $items->count() - $fixed;

                Notification::make()
                    ->title(__(':fixed done, :failed still failing', ['fixed' => $fixed, 'failed' => $failed]))
                    ->status($failed > 0 ? 'warning' : 'success')
                    ->send();
            });
    }

    /** @return Builder<ModuleQueueItem> the open queue items Retry tries from the dashboard */
    private static function retryable(): Builder
    {
        return ModuleQueueItem::query()->open()->where('action', '!=', ModuleQueueItem::TERMINATE);
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
