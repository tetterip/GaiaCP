<?php

namespace App\Invoices;

use App\Actions\ChangePrice;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use App\Support\ProductOptions;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates the payment notices for the coming due dates: one per client and due date, holding every
 * active or suspended service due then (and one per pending order for its first payment), `invoices.days_before` days ahead (overdue services that
 * have none get one at once). Safe to re-run: a service's due date that already sits on a notice
 * that isn't cancelled is skipped.
 */
class GenerateInvoices
{
    /**
     * @param  Order|null  $order  only this order's items (right after it is placed)
     * @return list<Invoice> the notices created (unsaved ones on a dry run)
     */
    public function handle(CarbonInterface $today, bool $dryRun = false, ?Order $order = null): array
    {
        $today = CarbonImmutable::parse($today)->startOfDay();

        // An order's unpaid items get a notice of their own, even when other services fall due the same day.
        $groups = $this->dueServices($today, $order)->groupBy(fn (Service $s) => $s->client_id.'|'.$s->next_due_date->toDateString().'|'.$s->currency
            .'|'.($s->status === ServiceStatus::Pending ? $s->order_id : ''));

        $created = [];

        foreach ($groups as $services) {
            $created[] = $dryRun ? $this->build($services, $today) : DB::transaction(fn () => $this->create($services, $today));
        }

        return $created;
    }

    /** @return Collection<int, Service> */
    public function dueServices(CarbonImmutable $today, ?Order $order = null): Collection
    {
        return Service::query()
            // Active and suspended services, and the items of a pending order: an order owes its first payment as soon as it is placed.
            ->where(fn (Builder $q) => $q->billable()->orWhere(fn (Builder $q) => $q
                ->where('status', ServiceStatus::Pending)
                ->whereHas('order', fn (Builder $q) => $q->where('status', OrderStatus::Pending))
                ->whereDoesntHave('payments')))
            ->when($order, fn (Builder $q) => $q->where('order_id', $order->id))
            // Free, unless a first payment (e.g. a setup fee) is still owed.
            ->where(fn (Builder $q) => $q->where('price_cents', '>', 0)->orWhere('new_price_cents', '>', 0)
                ->orWhere(fn (Builder $q) => $q->where('first_payment_cents', '>', 0)->whereDoesntHave('payments')))
            ->where('auto_terminate', false) // ends at its due date, so nothing more is owed
            ->whereDate('next_due_date', '<=', $today->addDays((int) config('gaiacp.invoices.days_before', 14)))
            ->whereDoesntHave('invoiceItems', fn (Builder $q) => $q
                ->whereColumn('invoice_items.due_date', 'services.next_due_date')
                ->forCycle()
                ->whereHas('invoice', fn (Builder $q) => $q->where('status', '!=', InvoiceStatus::Cancelled)))
            ->with(['client', 'payments' => fn ($q) => $q->select('id', 'service_id')->limit(1)])
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->get();
    }

    /** @param  Collection<int, Service>  $services */
    private function create(Collection $services, CarbonImmutable $today): Invoice
    {
        $invoice = $this->build($services, $today);
        $invoice->number = Invoice::nextNumber($today);
        $invoice->save();
        $invoice->items()->saveMany($invoice->getRelation('items'));
        $services->each(fn (Service $service) => ChangePrice::apply($service));

        ActivityLog::record('invoice.created', sprintf(
            'Payment notice %s for %s due %s',
            $invoice->number,
            Money::format($invoice->total_cents, $invoice->currency),
            $invoice->due_date->toDateString(),
        ), client: $invoice->client, properties: ['invoice_id' => $invoice->id, 'service_ids' => $services->pluck('id')->all()]);

        return $invoice;
    }

    /** @param  Collection<int, Service>  $services */
    private function build(Collection $services, CarbonImmutable $today): Invoice
    {
        /** @var Service $first */
        $first = $services->first();
        /** @var Client $client */
        $client = $first->client;

        $rule = Tax::ruleFor($client);
        $inclusive = Tax::inclusive();

        $items = $services->map(function (Service $service) use ($rule, $inclusive) {
            // A service that was never paid owes its first payment (e.g. a domain's registration price).
            // A scheduled price change (ChangePrice) bills its new price from its date on.
            $amount = $service->payments->isEmpty() && $service->first_payment_cents !== null
                ? $service->first_payment_cents
                : $service->priceOn($service->next_due_date);
            $tax = Tax::apply($amount, $rule['rate'] ?? null, $inclusive);

            return new InvoiceItem([
                'service_id' => $service->id,
                'description' => self::describe($service),
                'due_date' => $service->next_due_date,
                'period_end' => $service->billing_cycle->advance($service->next_due_date, $service->anchorDay()),
                'amount_cents' => $amount,
                'tax_cents' => $tax['tax'],
                'total_cents' => $tax['total'],
            ]);
        });

        $taxCents = $items->sum('tax_cents');
        $totalCents = $items->sum('total_cents');

        $orderIds = $services->where('status', ServiceStatus::Pending)->pluck('order_id')->unique();

        $invoice = new Invoice([
            'client_id' => $client->id,
            'order_id' => $orderIds->count() === 1 ? $orderIds->first() : null,
            'status' => InvoiceStatus::Unpaid,
            'date' => $today,
            'due_date' => $first->next_due_date,
            'currency' => $first->currency,
            'subtotal_cents' => $totalCents - $taxCents,
            'tax_name' => $rule['name'] ?? null,
            'tax_rate' => $rule['rate'] ?? null,
            'tax_inclusive' => $rule !== null && $inclusive,
            'tax_cents' => $taxCents,
            'total_cents' => $totalCents,
        ]);
        $invoice->setRelation('client', $client);
        $invoice->setRelation('items', $items);

        return $invoice;
    }

    public static function describe(Service $service): string
    {
        $label = $service->domain && $service->label !== $service->domain ? "{$service->label} ({$service->domain})" : ($service->label ?: (string) $service->domain);

        // The product options picked, but not those hidden from clients.
        $options = ProductOptions::describe($service->options);

        return mb_substr($label.' · '.$service->billing_cycle->getLabel().($options !== '' ? ' · '.$options : ''), 0, 255);
    }

    public static function period(InvoiceItem $item): string
    {
        if ($item->fee !== null && $item->fee !== InvoiceItem::UPGRADE) {
            return '';
        }

        return $item->due_date->format(AppServiceProvider::dateFormat()).' - '.$item->period_end->subDay()->format(AppServiceProvider::dateFormat());
    }
}
