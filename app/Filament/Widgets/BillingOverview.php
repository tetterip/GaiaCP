<?php

namespace App\Filament\Widgets;

use App\Filament\Clusters\Reports\Pages\IncomeForecast as ForecastPage;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Invoice;
use App\Reports\IncomeForecast;
use App\Support\Currencies;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * The open balance: unpaid payment notices, how much of it is overdue and how much falls due within WEEK days, and the
 * renewals expected in the next FORECAST_DAYS days (App\Reports\IncomeForecast), all in the base currency.
 */
class BillingOverview extends Widget
{
    use FitsDashboard;

    public const WEEK = 7;

    public const FORECAST_DAYS = 30;

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
        $overdue = Invoice::query()->unpaid()->whereOnDate('due_date', '<', today());
        $week = Invoice::query()->unpaid()->whereOnDate('due_date', '>=', today())->whereOnDate('due_date', '<=', today()->addDays(self::WEEK));
        $until = CarbonImmutable::today()->addDays(self::FORECAST_DAYS);
        $expected = (new IncomeForecast)->dues(2)->filter(fn (array $due) => ! $due['overdue'] && $due['due'] <= $until);

        return [
            'balance' => Money::format(Invoice::owedInBase(Invoice::query()->unpaid()), $currency),
            'notices' => Invoice::query()->unpaid()->count(),
            'overdue' => Money::format(Invoice::owedInBase($overdue), $currency),
            'overdueCount' => (clone $overdue)->count(),
            'week' => Money::format(Invoice::owedInBase($week), $currency),
            'weekCount' => (clone $week)->count(),
            'expected' => Money::format((int) $expected->sum('cents'), $currency),
            'expectedCount' => $expected->count(),
            'forecastUrl' => ForecastPage::canAccess() ? ForecastPage::getUrl() : null,
            'noticesUrl' => InvoiceResource::getUrl(),
            'paymentsUrl' => PaymentResource::canAccess() ? PaymentResource::getUrl() : null,
        ];
    }
}
