<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Invoice;
use App\Models\Service;
use App\Support\Currencies;
use App\Support\Money;
use Filament\Widgets\Widget;

/** The open balance: unpaid payment notices, how much of it is overdue and what falls due this week (base currency). */
class BillingOverview extends Widget
{
    use FitsDashboard;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected string $view = 'filament.dashboard.billing-overview';

    public static function dashboardLabel(): string
    {
        return __('Open balance');
    }

    public static function canView(): bool
    {
        return InvoiceResource::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $currency = Currencies::base();
        $overdue = Invoice::query()->unpaid()->whereDate('due_date', '<', today());
        $week = Service::query()->billable()->whereDate('next_due_date', '>=', today())->whereDate('next_due_date', '<=', today()->addDays(7));

        return [
            'balance' => Money::format(Currencies::sumToBase(Invoice::query()->unpaid(), 'total_cents'), $currency),
            'notices' => Invoice::query()->unpaid()->count(),
            'overdue' => Money::format(Currencies::sumToBase($overdue, 'total_cents'), $currency),
            'overdueCount' => (clone $overdue)->count(),
            'week' => Money::format(Currencies::sumToBase($week, 'price_cents'), $currency),
            'weekCount' => (clone $week)->count(),
            'noticesUrl' => InvoiceResource::getUrl(),
            'paymentsUrl' => PaymentResource::canAccess() ? PaymentResource::getUrl() : null,
        ];
    }
}
