<?php

namespace App\Support;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Client;
use App\Models\Order;
use App\Models\Ticket;
use App\Reports\IncomeReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The day's numbers across the top of the admin dashboard: income recorded today (base currency), new orders, new
 * clients and the tickets waiting for an answer, each next to yesterday's and linked to its list. An admin sees only the
 * boxes of the areas they may open.
 */
class DashboardToday
{
    /** @return list<array{key: string, icon: string, label: string, value: string, sub: string, url: string}> */
    public static function boxes(): array
    {
        $today = CarbonImmutable::today();
        $yesterday = $today->subDay();
        $boxes = [];

        if (PaymentResource::canAccess()) {
            $income = app(IncomeReport::class)->perDay($yesterday, $today);
            $boxes[] = [
                'key' => 'income',
                'icon' => 'heroicon-o-banknotes',
                'label' => __('Income today'),
                'value' => Money::format($income[$today->toDateString()] ?? 0, Currencies::base()),
                'sub' => __('Yesterday: :value', ['value' => Money::format($income[$yesterday->toDateString()] ?? 0, Currencies::base())]),
                'url' => PaymentResource::getUrl(parameters: ['filters' => ['paid_on' => ['from' => $today->toDateString(), 'until' => $today->toDateString()]]]),
            ];
        }

        if (OrderResource::canAccess()) {
            $boxes[] = [
                'key' => 'orders',
                'icon' => 'heroicon-o-shopping-cart',
                'label' => __('New orders'),
                'value' => (string) self::on(Order::query(), $today),
                'sub' => __('Yesterday: :value', ['value' => self::on(Order::query(), $yesterday)]),
                'url' => OrderResource::getUrl(),
            ];
        }

        if (ClientResource::canAccess()) {
            $boxes[] = [
                'key' => 'clients',
                'icon' => 'heroicon-o-user-plus',
                'label' => __('New clients'),
                'value' => (string) self::on(Client::query(), $today),
                'sub' => __('Yesterday: :value', ['value' => self::on(Client::query(), $yesterday)]),
                'url' => ClientResource::getUrl(),
            ];
        }

        if (TicketResource::canAccess()) {
            $waiting = Ticket::query()->waiting();
            $oldest = StoredTime::parse((clone $waiting)->min('last_reply_at'));
            $boxes[] = [
                'key' => 'tickets',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'label' => __('Tickets waiting'),
                'value' => (string) (clone $waiting)->count(),
                'sub' => $oldest ? __('Oldest :time', ['time' => $oldest->diffForHumans()]) : __('None waiting for us'),
                'url' => TicketResource::getUrl(parameters: ['activeTab' => 'waiting']),
            ];
        }

        return $boxes;
    }

    /** @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query how many of them were created on that day */
    private static function on(Builder $query, CarbonImmutable $day): int
    {
        return $query->whereBetween('created_at', [$day->startOfDay(), $day->endOfDay()])->count();
    }
}
