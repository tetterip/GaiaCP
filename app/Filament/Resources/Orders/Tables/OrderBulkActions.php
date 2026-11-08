<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\CancelOrder;
use App\Actions\CompleteOrder;
use App\Credit\ClientCredit;
use App\Enums\OrderStatus;
use App\Filament\Actions\BulkOutcome;
use App\Filament\Actions\MessageClientsBulkAction;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use App\Models\TaxInvoice;
use App\Provisioning\ProvisioningException;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mass actions on the orders picked in a list. Complete and Cancel do what the order page's own actions do, order by
 * order; Delete removes cancelled orders nothing was paid for; Send message emails their clients.
 */
class OrderBulkActions
{
    /** Most orders one action takes: completing one sets items up on servers and registrars. */
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([
            self::complete(),
            self::cancel(),
            MessageClientsBulkAction::make(fn (Collection $records) => $records->pluck('client_id')->all()),
            self::delete(),
        ]);
    }

    /** As Complete order, with the registrar and server each item would get on its own (no picker). */
    public static function complete(): BulkAction
    {
        $skip = fn (Order $order) => $order->status === OrderStatus::Pending ? null : __('not pending');

        return BulkAction::make('complete')
            ->label('Complete order')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Complete orders'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Each item is set up as Complete order would, on the registrar and server it would get by itself; registrations and transfers charge your registrar account. An item that fails stays pending and is named below.'))
            ->modalSubmitActionLabel(__('Complete them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                function (Order $order) {
                    $failures = app(CompleteOrder::class)->handle($order);

                    if ($failures !== []) {
                        $labels = Service::query()->whereKey(array_keys($failures))->pluck('label', 'id');

                        throw new ProvisioningException(collect($failures)->map(fn (string $error, int $id) => "{$labels[$id]}: ".rtrim($error))->join(' '));
                    }
                },
                fn (Order $order) => __('Order #:id', ['id' => $order->id]),
            )->notify(':count order completed|:count orders completed'));
    }

    /** As Cancel order on each that can be: pending, nothing set up. One choice for the money of those with payments. */
    public static function cancel(): BulkAction
    {
        $skip = fn (Order $order) => CancelOrder::canCancel($order) ? null : __('not pending, or an item is already set up');

        return BulkAction::make('cancel')
            ->label('Cancel order')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(__('Cancel orders'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Marks each order, its items and its unpaid payment notice cancelled. Nothing was created on a server or registrar yet.'))
            ->schema(fn (Collection $records) => self::anyPaid($records) ? [
                Radio::make('payments')
                    ->label('The payments of those already paid')
                    ->options(array_filter([
                        CancelOrder::REFUND_TO_CREDIT => ClientCredit::enabled() ? __('Return them to the client\'s credit') : null,
                        CancelOrder::REFUNDED_ELSEWHERE => __('I paid them back outside GaiaCP (Stripe, bank)'),
                        CancelOrder::KEEP => __('Keep them as they are'),
                    ]))
                    ->default(CancelOrder::defaultPaymentOption())
                    ->required(),
            ] : [])
            ->modalSubmitActionLabel(__('Cancel them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => BulkOutcome::run(
                $records,
                $skip,
                fn (Order $order) => app(CancelOrder::class)->handle($order, payments: $data['payments'] ?? null),
                fn (Order $order) => __('Order #:id', ['id' => $order->id]),
            )->notify(':count order cancelled|:count orders cancelled'));
    }

    /** Cancelled orders with no payment and no invoice: deleted with their items and payment notices. */
    public static function delete(): BulkAction
    {
        $skip = fn (Order $order) => self::whyNotDeletable($order);

        return BulkAction::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete orders'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Only cancelled orders that nothing was paid for are deleted, with their items and payment notices. This cannot be undone.'))
            ->modalSubmitActionLabel(__('Delete them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Order $order) => self::destroy($order),
                fn (Order $order) => __('Order #:id', ['id' => $order->id]),
            )->notify(':count order deleted|:count orders deleted'));
    }

    /** @param  Collection<int, Order>  $records  whether one that can be cancelled has payments */
    private static function anyPaid(Collection $records): bool
    {
        return $records->contains(fn (Order $order): bool => CancelOrder::canCancel($order) && CancelOrder::hasPayments($order));
    }

    /** Null when the order can be deleted; else why not (a translated text). */
    public static function whyNotDeletable(Order $order): ?string
    {
        if ($order->status !== OrderStatus::Cancelled) {
            return __('not cancelled');
        }

        $services = $order->services()->pluck('id');
        $invoices = $order->invoices()->pluck('id');

        if (Payment::query()->whereIn('service_id', $services)->exists()
            || TaxInvoice::query()->whereIn('invoice_id', $invoices)->exists()
            || Service::query()->whereIn('parent_service_id', $services)->whereNot('order_id', $order->id)->exists()) {
            return __('paid, invoiced, or an item has add-ons from another order');
        }

        return null;
    }

    private static function destroy(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (($why = self::whyNotDeletable($order)) !== null) {
                throw new RuntimeException($why);
            }

            $order->invoices()->get()->each->delete();
            $order->services()->get()->each->delete();
            $order->delete();

            ActivityLog::record('order.deleted', "Cancelled order #{$order->id} deleted with its items and payment notices", client: $order->client, properties: ['order_id' => $order->id]);
        });
    }
}
