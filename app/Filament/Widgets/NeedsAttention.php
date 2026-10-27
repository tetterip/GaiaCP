<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\ActivityLog;
use App\Models\ClientChangeRequest;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Support\Currencies;
use App\Support\Health\SchedulerMonitor;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * What waits on an admin, each item only for those who may open it: cron stopped (System health), pending orders,
 * client change requests, leads to contact today, overdue payment notices and what failed in the last FAILURE_DAYS days (panel / registrar
 * calls, backups, emails, updates, online payments that matched no notice).
 */
class NeedsAttention extends Widget
{
    use FitsDashboard;

    public const FAILURE_DAYS = 3;

    public const FAILURES = ['provisioning.failed', 'backup.failed', 'email.failed', 'update.failed', 'payment.online_unmatched'];

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

        if (OrderResource::canAccess() && ($pending = Order::query()->where('status', OrderStatus::Pending))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-shopping-cart',
                'tone' => 'warn',
                'title' => __(':count order(s) waiting for Complete order', ['count' => (clone $pending)->count()]),
                'sub' => __('Oldest :time', ['time' => (clone $pending)->oldest()->value('created_at')?->diffForHumans()]),
                'url' => OrderResource::getUrl(parameters: ['filters' => ['status' => ['value' => OrderStatus::Pending->value]]]),
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

        if (InvoiceResource::canAccess() && ($overdue = Invoice::query()->unpaid()->whereDate('due_date', '<', today()))->exists()) {
            $items[] = [
                'icon' => 'heroicon-o-document-text',
                'tone' => 'bad',
                'title' => __(':count overdue payment notice(s)', ['count' => (clone $overdue)->count()]),
                'sub' => Money::format(Currencies::sumToBase($overdue, 'total_cents'), Currencies::base()),
                'url' => InvoiceResource::getUrl(parameters: ['filters' => ['overdue' => ['isActive' => true]]]),
            ];
        }

        if (ActivityLogResource::canAccess()) {
            $failures = ActivityLog::query()->with('service')
                ->whereIn('event', self::FAILURES)
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
