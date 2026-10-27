<?php

namespace App\Console\Commands;

use App\Actions\CancelOrder;
use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:cancel-unpaid-orders {--date= : Run as if today were this date (Y-m-d)} {--dry-run : List what would be cancelled}')]
#[Description('Cancel pending orders left unpaid for gaiacp.automation.cancel_unpaid_orders_days days (empty = never)')]
class CancelUnpaidOrders extends Command
{
    public function handle(CancelOrder $cancel): int
    {
        $days = config('gaiacp.automation.cancel_unpaid_orders_days');

        if (! $days) {
            $this->info('Cancelling unpaid orders is off (Settings → Automation).');

            return self::SUCCESS;
        }

        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $count = 0;

        $orders = Order::query()
            ->with('client')
            ->where('status', OrderStatus::Pending)
            ->whereDate('created_at', '<=', $today->subDays((int) $days))
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            // Something set up or paid: an admin decides
            if (! CancelOrder::canCancel($order) || CancelOrder::hasPayments($order)) {
                continue;
            }

            $line = "#{$order->id} of {$order->client->name}, placed {$order->created_at->toDateString()}";
            $count++;

            if ($this->option('dry-run')) {
                $this->line("[dry run] would cancel order {$line}");

                continue;
            }

            $cancel->handle($order, "unpaid for {$days} days");
            $this->line("Cancelled order {$line}");
        }

        $this->info(sprintf('%d order(s) %s.', $count, $this->option('dry-run') ? 'to cancel' : 'cancelled'));

        return self::SUCCESS;
    }
}
