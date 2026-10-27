<?php

namespace App\Actions;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Invoices\SendInvoiceEmails;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Support\Money;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add-ons a client adds to a running service from its page in the client area (while Settings → Client area lets
 * clients order). The add-on is a pending service on the same cycle and due date (as App\Actions\AddAddon makes them),
 * with a payment notice due today for the days until that date and its setup fee: an "upgrade" line, paid without
 * moving the due date. Once it is paid (or at once when nothing is owed) the add-on becomes active (activate()) and
 * renews with the service. Add-ons are billing only: the admins are told (bell) and set up the rest by hand.
 */
class ClientAddons
{
    public function __construct(private SendInvoiceEmails $emails) {}

    /** Whether clients may add add-ons to this service now. */
    public static function canAdd(Service $service): bool
    {
        return (bool) config('gaiacp.client_area.orders')
            && $service->status === ServiceStatus::Active
            && $service->parent_service_id === null
            && ! $service->auto_terminate
            && $service->client_visible
            && self::products($service)->isNotEmpty();
    }

    /**
     * Add-ons that fit its product, shown to clients, of a type that needs no server, priced for its cycle and not on
     * it already (pending, active or suspended).
     *
     * @return Collection<int, Product>
     */
    public static function products(Service $service): Collection
    {
        $taken = $service->addons()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->pluck('product_id')->filter();

        return self::offered($service->product, $service->billing_cycle, $service->currency)
            ->reject(fn (Product $product) => $taken->contains($product->id))
            ->values();
    }

    /**
     * Add-ons clients may take with a product (here, or with it on the Order page): those that fit it, shown to
     * clients, of a type that needs no server and priced for the cycle in the currency.
     *
     * @return Collection<int, Product>
     */
    public static function offered(?Product $product, BillingCycle $cycle, string $currency): Collection
    {
        return Product::query()->addonsFor($product)
            ->where('hidden', false)
            ->where('client_visible', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $addon) => ! $addon->type->needsServer() && $addon->type !== ServiceType::Domain
                && $addon->type->clientOrderable() && $addon->priceFor($cycle, $currency) !== null)
            ->values();
    }

    /** What adding it today costs: the days until the service's due date, and the setup fee. @return array{days: int, setup: int, total: int} */
    public static function quote(Service $service, Product $addon, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $price = (int) $addon->priceFor($service->billing_cycle, $service->currency);
        $due = $service->next_due_date;
        $periodDays = $due->subMonthsNoOverflow($service->billing_cycle->months())->diffInDays($due);
        $days = $due->gt($today) && $service->billing_cycle->isRecurring() ? (int) round($price * $today->diffInDays($due) / max(1, $periodDays)) : 0;
        $setup = (int) $addon->setupFeeFor($service->billing_cycle, $service->currency);

        return ['days' => $days, 'setup' => $setup, 'total' => $days + $setup];
    }

    /**
     * Adds it as a pending service and, when something is owed, the payment notice for it.
     *
     * @return array{addon: Service, invoice: ?Invoice}
     */
    public function order(Service $service, Product $addon): array
    {
        if (! self::canAdd($service) || ! self::products($service)->contains('id', $addon->id)) {
            throw ValidationException::withMessages(['product_id' => __('This add-on can\'t be added to this service.')]);
        }

        $today = CarbonImmutable::today();
        $quote = self::quote($service, $addon, $today);

        [$created, $invoice] = DB::transaction(function () use ($service, $addon, $quote, $today) {
            $created = Service::create([
                'client_id' => $service->client_id,
                'parent_service_id' => $service->id,
                'type' => $addon->type,
                'product_id' => $addon->id,
                'label' => $addon->name,
                'domain' => $service->domain,
                'price_cents' => (int) $addon->priceFor($service->billing_cycle, $service->currency),
                'currency' => $service->currency,
                'billing_cycle' => $service->billing_cycle,
                'start_date' => $today,
                'next_due_date' => $service->next_due_date,
                'status' => ServiceStatus::Pending,
                'auto_terminate' => false,
                'client_visible' => true,
            ]);

            $invoice = $quote['total'] > 0 ? $this->charge($created, $service, $quote['total'], $today) : null;

            ActivityLog::record('service.addon_ordered', sprintf('Client added the add-on %s to %s%s', $addon->name, $service->label,
                $invoice ? sprintf(' (payment notice %s, %s)', $invoice->number, Money::format($invoice->total_cents, $invoice->currency)) : ''),
                $created, properties: array_filter(['parent_service_id' => $service->id, 'invoice_id' => $invoice?->id]));

            return [$created, $invoice];
        });

        if ($invoice === null) {
            self::activate($created);
        } else {
            $this->emails->handle($today, only: [$invoice->id]);
        }

        return ['addon' => $created->refresh(), 'invoice' => $invoice];
    }

    /** A client's add-on waiting for its payment becomes active (its notice is paid). Safe to call again. */
    public static function activate(Service $addon): void
    {
        if ($addon->status !== ServiceStatus::Pending || $addon->parent_service_id === null || $addon->order_id !== null) {
            return;
        }

        $updated = Service::query()->whereKey($addon->id)->where('status', ServiceStatus::Pending)->whereNotNull('parent_service_id')->whereNull('order_id')
            ->update(['status' => ServiceStatus::Active]);

        if ($updated > 0) {
            $addon->refresh();
            ActivityLog::record('service.activated', "Add-on {$addon->label} activated", $addon);
        }
    }

    private function charge(Service $addon, Service $service, int $amount, CarbonImmutable $today): Invoice
    {
        $client = $service->client;
        $rule = Tax::ruleFor($client);
        $inclusive = Tax::inclusive();
        $tax = Tax::apply($amount, $rule['rate'] ?? null, $inclusive);

        $invoice = Invoice::create([
            'number' => Invoice::nextNumber($today),
            'client_id' => $client->id,
            'status' => InvoiceStatus::Unpaid,
            'date' => $today,
            'due_date' => $today,
            'currency' => $service->currency,
            'subtotal_cents' => $tax['total'] - $tax['tax'],
            'tax_name' => $rule['name'] ?? null,
            'tax_rate' => $rule['rate'] ?? null,
            'tax_inclusive' => $rule !== null && $inclusive,
            'tax_cents' => $tax['tax'],
            'total_cents' => $tax['total'],
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $addon->id,
            'fee' => InvoiceItem::UPGRADE,
            'description' => mb_substr(__('Add-on :addon for :service', ['addon' => $addon->label, 'service' => $service->domain ?: $service->label], $client->preferredLocale()), 0, 255),
            'due_date' => $today,
            'period_end' => $service->next_due_date,
            'amount_cents' => $amount,
            'tax_cents' => $tax['tax'],
            'total_cents' => $tax['total'],
        ]);

        ActivityLog::record('invoice.created', sprintf('Payment notice %s for %s: the add-on %s until %s',
            $invoice->number, Money::format($invoice->total_cents, $invoice->currency), $addon->label, $service->next_due_date->toDateString(),
        ), $addon, properties: ['invoice_id' => $invoice->id, 'client_addon' => true]);

        return $invoice;
    }
}
