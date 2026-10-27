<?php

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an order that nothing was set up for yet: the order, its items and its unpaid payment notice. Used by the
 * order page's "Cancel order" and by gaiacp:cancel-unpaid-orders (Settings → Automation → Cancel unpaid orders after).
 */
class CancelOrder
{
    /** Pending, with every item still pending (an active one has to be terminated on its own). */
    public static function canCancel(Order $order): bool
    {
        return $order->status === OrderStatus::Pending
            && ! $order->services()->where('status', '!=', ServiceStatus::Pending)->exists();
    }

    /** Whether any of its items has a payment recorded: such an order is never cancelled automatically. */
    public static function hasPayments(Order $order): bool
    {
        return Payment::query()->whereIn('service_id', $order->services()->select('id'))->exists();
    }

    public function handle(Order $order, ?string $why = null): void
    {
        if (! self::canCancel($order)) {
            return;
        }

        DB::transaction(function () use ($order) {
            $order->services()->update(['status' => ServiceStatus::Cancelled]);
            $order->update(['status' => OrderStatus::Cancelled]);
            $order->invoices()->unpaid()->update(['status' => InvoiceStatus::Cancelled]);
        });

        ActivityLog::record($why ? 'order.auto_cancelled' : 'order.cancelled', "Order #{$order->id} cancelled".($why ? ": {$why}" : ''), client: $order->client, properties: ['order_id' => $order->id]);
    }
}
