<?php

namespace Plugins\Telegram;

use App\Enums\InvoiceStatus;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Server;
use App\Models\Ticket;
use App\Monitoring\MonitorStatus;
use App\Monitoring\UptimeHistory;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the bot answers in the admins' chat, as Telegram HTML: /owed (overdue payment notices by client), /today
 * (payments, orders and tickets), /tickets (waiting for an answer) and /servers (monitor status and 24-hour uptime).
 * Read only. Written in the app's language, like the other chat messages.
 */
class TelegramCommands
{
    private const LIST_MAX = 10;

    /** command => what it does, also the bot's menu (setMyCommands) */
    public static function list(): array
    {
        return [
            'owed' => __('Who owes: overdue payment notices'),
            'today' => __('Today\'s payments, orders and tickets'),
            'tickets' => __('Tickets waiting for an answer'),
            'servers' => __('Servers and their uptime'),
            'help' => __('What I can answer'),
        ];
    }

    public function answer(string $text): string
    {
        // "/owed@MyGaiaBot extra words" → owed
        $command = strtolower(preg_replace('/@\S+$/', '', strtok(trim($text), ' ') ?: '') ?? '');

        return match (ltrim($command, '/')) {
            'owed' => $this->owed(),
            'today' => $this->today(),
            'tickets' => $this->tickets(),
            'servers' => $this->servers(),
            default => $this->help(),
        };
    }

    private function help(): string
    {
        return '<b>'.e(__('GaiaCP bot')).'</b>'."\n".collect(self::list())->map(fn (string $what, string $command) => "/{$command} – ".e($what))->join("\n");
    }

    private function owed(): string
    {
        $today = CarbonImmutable::today();
        $notices = Invoice::query()->with('client')->where('status', InvoiceStatus::Unpaid)->whereDate('due_date', '<', $today)->get();

        if ($notices->isEmpty()) {
            return '✅ '.e(__('No overdue payment notices.'));
        }

        $lines = $notices->groupBy(fn (Invoice $invoice) => $invoice->client_id.'|'.$invoice->currency)
            ->map(fn (Collection $group) => [
                'client' => $group->first()->client,
                'currency' => $group->first()->currency,
                'total' => $group->sum('total_cents'),
                'count' => $group->count(),
                'oldest' => $group->min('due_date'),
            ])
            ->sortByDesc('total')
            ->values();

        $text = '🧾 <b>'.e(__('Overdue: :count payment notice(s), :total', ['count' => $notices->count(), 'total' => self::totals($notices)])).'</b>';

        foreach ($lines->take(self::LIST_MAX) as $i => $line) {
            $text .= "\n".($i + 1).'. '.e(self::client($line['client'])).' – '.e(__(':amount (:count, since :date)', [
                'amount' => Money::format($line['total'], $line['currency']),
                'count' => $line['count'],
                'date' => $line['oldest']->format(AppServiceProvider::dateFormat()),
            ]));
        }

        if ($lines->count() > self::LIST_MAX) {
            $text .= "\n".e(__('…and :count more clients.', ['count' => $lines->count() - self::LIST_MAX]));
        }

        return $text;
    }

    private function today(): string
    {
        $today = CarbonImmutable::today();
        $payments = Payment::query()->whereDate('paid_on', $today)->get();
        $orders = Order::query()->whereDate('created_at', $today)->count();
        $tickets = Ticket::query()->whereDate('created_at', $today)->count();

        return '📅 <b>'.e(__('Today, :date', ['date' => $today->format(AppServiceProvider::dateFormat())])).'</b>'
            ."\n💶 ".e(__('Payments: :count, :total', ['count' => $payments->count(), 'total' => $payments->isEmpty() ? Money::format(0, (string) config('gaiacp.currency', 'EUR')) : self::totals($payments, 'amount_cents')]))
            ."\n🛒 ".e(__('New orders: :count', ['count' => $orders]))
            ."\n🎫 ".e(__('New tickets: :count', ['count' => $tickets]));
    }

    private function tickets(): string
    {
        $waiting = Ticket::query()->with('client')->where('status', TicketStatus::Open)->oldest('updated_at')->get();

        if ($waiting->isEmpty()) {
            return '✅ '.e(__('No ticket is waiting for an answer.'));
        }

        $text = '🎫 <b>'.e(__('Waiting for an answer: :count', ['count' => $waiting->count()])).'</b>';

        foreach ($waiting->take(self::LIST_MAX) as $ticket) {
            $text .= "\n".e("{$ticket->number} · {$ticket->subject} · ".($ticket->client ? self::client($ticket->client) : ($ticket->name ?: $ticket->email)));
        }

        return $text;
    }

    private function servers(): string
    {
        $servers = Server::query()->where('active', true)->orderBy('name')->get();

        if ($servers->isEmpty()) {
            return e(__('No active servers.'));
        }

        return '🖥 <b>'.e(__('Servers')).'</b>'.$servers->map(function (Server $server) {
            if (blank($server->monitor_module)) {
                return "\n⚪ ".e(__(':server: not monitored', ['server' => $server->name]));
            }

            $status = $server->monitor_status ?? MonitorStatus::Unknown;
            $dot = match ($status) {
                MonitorStatus::Up => '🟢',
                MonitorStatus::Down => '🔴',
                MonitorStatus::Pending, MonitorStatus::Maintenance => '🟡',
                default => '⚪',
            };
            $uptime = UptimeHistory::percent(UptimeHistory::for($server, '24h')['uptime']);

            return "\n{$dot} ".e($uptime === null
                ? __(':server: :status', ['server' => $server->name, 'status' => $status->label()])
                : __(':server: :status, :uptime in 24 hours', ['server' => $server->name, 'status' => $status->label(), 'uptime' => $uptime]));
        })->join('');
    }

    private static function client(?Client $client): string
    {
        return $client === null ? '—' : ($client->company ?: $client->name);
    }

    /** "€320.00 + $40.00": the sum in each currency. */
    private static function totals(Collection $rows, string $column = 'total_cents'): string
    {
        return $rows->groupBy('currency')->map(fn (Collection $group, string $currency) => Money::format($group->sum($column), $currency))->join(' + ');
    }
}
