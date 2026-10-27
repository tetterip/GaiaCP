<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Credit\ClientCredit;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Client;
use App\Models\Service;
use App\Reports\ClientHealth;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * Over the client page's tabs: what the client owes (and how much of it is overdue), their active services, the
 * next renewal, how they pay (ClientHealth) and how to reach them. Money and services only for admins with those areas.
 */
class ClientSummary extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.clients.summary';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Client $client */
        $client = $this->record;

        return [
            'client' => $client,
            'billing' => InvoiceResource::canAccess() ? $this->billing($client) : null,
            'health' => InvoiceResource::canAccess() ? ClientHealth::for($client) : null,
            'services' => ServiceResource::canAccess() ? $this->services($client) : null,
        ];
    }

    /** @return array{credit: ?string, owed: string, count: int, overdue: string, overdueCount: int} */
    private function billing(Client $client): array
    {
        $unpaid = $client->invoices()->unpaid()->get(['currency', 'total_cents', 'due_date']);
        $overdue = $unpaid->filter(fn ($notice) => $notice->due_date->lessThan(today()));
        // Notices in more than one currency show one amount per currency.
        $sum = fn ($notices) => $notices->isEmpty()
            ? Money::format(0, Currencies::forClient($client))
            : $notices->groupBy('currency')->map(fn ($group, $currency) => Money::format($group->sum('total_cents'), $currency))->join(' + ');

        return [
            // Shown once credit is on, or the client has some.
            'credit' => ClientCredit::enabled() || $client->credit_cents !== 0 ? Money::format((int) $client->credit_cents, $client->currencyCode()) : null,
            'owed' => $sum($unpaid),
            'count' => $unpaid->count(),
            'overdue' => $sum($overdue),
            'overdueCount' => $overdue->count(),
        ];
    }

    /** @return array{active: int, suspended: int, next: ?Service} */
    private function services(Client $client): array
    {
        return [
            'active' => $client->services()->where('status', ServiceStatus::Active)->count(),
            'suspended' => $client->services()->where('status', ServiceStatus::Suspended)->count(),
            'next' => $client->services()->billable()->where('auto_terminate', false)->orderBy('next_due_date')->first(),
        ];
    }
}
