<?php

namespace App\Actions;

use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Invoices\RepriceIssuedNotice;
use App\Invoices\SendInvoiceEmails;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Support\Money;
use App\Support\ProductOptions;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Upgrade / downgrade: moves an active or suspended service to another product of the same type. The new package is
 * set on the panel at once and the service takes the new product, price and options (its picks carried over where
 * the new product has them). By default the new price is owed from the next due date: an unpaid notice already
 * issued for that date is repriced. With "charge the difference now", the days left until the next due date are
 * also settled at the new price: a payment notice for the difference (due today) on an upgrade, the difference into
 * the client's credit balance on a downgrade (when Settings → Client credit is on).
 */
class ChangeProduct
{
    public function __construct(private ServiceLifecycle $lifecycle, private ClientCredit $credit, private SendInvoiceEmails $emails, private RepriceIssuedNotice $reprice) {}

    public static function changeable(Service $service): bool
    {
        return in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)
            && $service->type !== ServiceType::Domain
            && self::products($service)->exists();
    }

    /** @return Builder<Product> the products it can move to: same type, add-on to add-on, not retired */
    public static function products(Service $service): Builder
    {
        return Product::query()->selectable()
            ->where('type', $service->type)
            ->where('addon', $service->parent_service_id !== null)
            ->when($service->product_id, fn (Builder $q) => $q->whereKeyNot($service->product_id));
    }

    /** The picks it keeps on the new product: the same choices where it has the option, the defaults otherwise. */
    public static function options(Service $service, Product $to): array
    {
        return ProductOptions::select($to, ProductOptions::values($service->options), $service->billing_cycle, $service->currency);
    }

    /** The new product's price for its cycle and currency with the options; null when the product has no such price. */
    public static function price(Service $service, Product $to): ?int
    {
        $price = $to->priceFor($service->billing_cycle, $service->currency);

        return $price === null ? null : $price + ProductOptions::total(self::options($service, $to));
    }

    /**
     * What the days left until the next due date cost at the new price, less what they cost at the current one:
     * positive is owed, negative is the client's. Nothing for a service already due.
     */
    public static function difference(Service $service, int $newPriceCents, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $due = $service->next_due_date;

        // A one-time service has no period to share out.
        if (! $due->gt($today) || ! $service->billing_cycle->isRecurring()) {
            return 0;
        }

        $periodDays = $due->subMonthsNoOverflow($service->billing_cycle->months())->diffInDays($due);
        $daysLeft = $today->diffInDays($due);

        return (int) round(($newPriceCents - $service->price_cents) * $daysLeft / max(1, $periodDays));
    }

    /**
     * @return array{difference: int, invoice: ?Invoice, credited: int}
     *
     * @throws InvalidArgumentException when the service can't move to that product
     */
    public function handle(Service $service, Product $to, int $priceCents, bool $chargeNow = false, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true) || ! self::products($service)->whereKey($to->id)->exists()) {
            throw new InvalidArgumentException("{$service->label} can't move to {$to->name}.");
        }

        $from = $service->product;
        $oldPrice = $service->price_cents;
        $difference = $chargeNow ? self::difference($service, $priceCents, $today) : 0;
        $options = self::options($service, $to);

        // The panel first: when it fails (ProvisioningException), nothing else changes.
        if (filled($to->package) && $to->package !== $service->package) {
            $this->lifecycle->changePackage($service, $to->package);
        }

        $result = DB::transaction(function () use ($service, $from, $to, $priceCents, $oldPrice, $options, $difference, $today) {
            $service->update([
                'product_id' => $to->id,
                'label' => $from !== null && $service->label === $from->name ? $to->name : $service->label,
                'price_cents' => $priceCents,
                'options' => $options ?: null,
                'new_price_cents' => null,
                'new_price_from' => null,
            ]);

            ActivityLog::record('service.product_changed', sprintf('Moved from %s to %s; price %s → %s',
                $from?->name ?? 'no product', $to->name, Money::format($oldPrice, $service->currency), Money::format($priceCents, $service->currency),
            ), $service, properties: ['from_product_id' => $from?->id, 'to_product_id' => $to->id, 'from_cents' => $oldPrice, 'to_cents' => $priceCents, 'difference_cents' => $difference]);

            // An unpaid notice already issued for the next due date bills the new price (and options).
            $this->reprice->handle($service);

            return [
                'difference' => $difference,
                'invoice' => $difference > 0 ? $this->charge($service, $difference, $from, $to, $today) : null,
                'credited' => $difference < 0 ? $this->refund($service, -$difference, $to) : 0,
            ];
        });

        if ($result['invoice'] !== null) {
            $this->emails->handle($today, only: [$result['invoice']->id]);
        }

        return $result;
    }

    private function charge(Service $service, int $amount, ?Product $from, Product $to, CarbonImmutable $today): Invoice
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
            'service_id' => $service->id,
            'fee' => InvoiceItem::UPGRADE,
            'description' => mb_substr(__('Change from :from to :to', ['from' => $from?->name ?? $service->label, 'to' => $to->name], $client->preferredLocale()).' · '.$service->label, 0, 255),
            'due_date' => $today,
            'period_end' => $service->next_due_date,
            'amount_cents' => $amount,
            'tax_cents' => $tax['tax'],
            'total_cents' => $tax['total'],
        ]);

        ActivityLog::record('invoice.created', sprintf('Payment notice %s for %s: the change to %s until %s',
            $invoice->number, Money::format($invoice->total_cents, $invoice->currency), $to->name, $service->next_due_date->toDateString(),
        ), $service, properties: ['invoice_id' => $invoice->id]);

        return $invoice;
    }

    /** @return int the cents credited: none when client credit is off or the service is in another currency */
    private function refund(Service $service, int $amount, Product $to): int
    {
        $client = $service->client;

        if (! ClientCredit::enabled() || strtoupper($service->currency) !== $client->currencyCode()) {
            return 0;
        }

        $this->credit->record($client, CreditType::Adjustment, $amount, "Change of {$service->label} to {$to->name}: the days left until {$service->next_due_date->toDateString()}");

        return $amount;
    }
}
