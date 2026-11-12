<?php

namespace App\Actions;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Invoices\InvoiceOrder;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Tld;
use App\Support\Coupons;
use App\Support\DomainName;
use App\Support\ProductOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates an order and its items as pending services. Each item takes its product's
 * auto-setup choice: items set up "as soon as an order is placed" are set up right after
 * (CompleteOrder::setUp), the rest on their first payment or when the order is completed. A coupon lowers the items it
 * covers (App\Support\Coupons); when it leaves nothing to pay, the order's notice is recorded as paid. Each item takes one
 * of its product's Quantity available (Product::$stock), under a row lock; an order from a client (the client area, the
 * API) is refused when not enough are left, while an admin's goes ahead and leaves it at 0.
 */
class PlaceOrder
{
    /** @var array<int, string> service id => why an item to set up on order is still pending */
    public array $setupErrors = [];

    public function __construct(private ClientMailer $mailer, private CompleteOrder $complete, private InvoiceOrder $invoices, private RecordInvoicePayment $payments) {}

    /**
     * @param  list<array<string, mixed>>  $items  service fields (prices in cents, options included) plus the
     *                                             product options picked ({option id: pick}), for an add-on its
     *                                             item's index in parent (an earlier item), and for a domain
     *                                             registration_type, registrar_id, epp_code, nameservers, auto_renew
     * @param  array{terms_accepted_at?: mixed, terms_version?: string|null}  $terms  the client accepted the terms of service (TermsOfService::acceptance())
     * @param  Coupon|null  $coupon  lowers the items it covers (Coupons::apply()), checked again here so its limits hold
     * @param  bool  $inStockOnly  refuse the order when a product has fewer left than it orders (orders from clients)
     *
     * @throws ValidationException under `coupon` when the coupon can't be used, under `product` when out of stock
     */
    public function handle(Client $client, array $items, ?string $notes = null, array $terms = [], ?Coupon $coupon = null, bool $inStockOnly = false): Order
    {
        // A hosting item without a domain is for the order's domain, when there is exactly one.
        $domains = collect($items)->filter(fn (array $item) => self::type($item) === ServiceType::Domain)->map(fn (array $item) => DomainName::normalize($item['domain'] ?? null))->filter();

        // Its account on the panel is made for its domain (and its username from it): never one without.
        foreach ($items as $item) {
            if (self::type($item) === ServiceType::Hosting && DomainName::normalize($item['domain'] ?? null) === '' && $domains->count() !== 1) {
                throw ValidationException::withMessages(['domain' => __('Enter the hosting\'s domain: this order has no single domain it can take.')]);
            }
        }

        $order = DB::transaction(function () use ($client, $items, $notes, $domains, $terms, $coupon, $inStockOnly) {
            $discount = null;

            if ($inStockOnly) {
                self::claimDomains($domains->all());
            }

            self::takeStock($items, $inStockOnly, $client);

            if ($coupon !== null) {
                // Locked, so two orders at once can't both take a coupon's last use.
                $coupon = Coupon::query()->lockForUpdate()->find($coupon->id);
                Coupons::check($coupon, $client, $items);
                ['items' => $items, 'discount_cents' => $discount] = Coupons::apply($coupon, $items, $client->currencyCode());
            }

            $order = (new Order(['client_id' => $client->id, 'status' => OrderStatus::Pending, 'notes' => $notes]))->forceFill($terms);
            $order->forceFill(['coupon_id' => $coupon?->id, 'discount_cents' => $discount]);
            $order->save();
            $today = today();

            $created = [];

            foreach ($items as $index => $item) {
                $type = self::type($item);
                // An add-on goes on an earlier item of the order, with its domain.
                $parent = isset($item['parent']) ? ($created[$item['parent']] ?? null) : null;
                $domain = $parent?->domain ?? (DomainName::normalize($item['domain'] ?? null) ?: ($type === ServiceType::Hosting && $domains->count() === 1 ? $domains->first() : null));
                $product = filled($item['product_id'] ?? null) ? Product::find($item['product_id']) : null;
                $cycle = $item['billing_cycle'] ?? BillingCycle::Annually;

                $service = $order->services()->create([
                    ...Arr::only($item, ['product_id', 'label', 'price_cents', 'first_payment_cents', 'billing_cycle', 'package', 'coupon_id']),
                    'client_id' => $client->id,
                    'parent_service_id' => $parent?->id,
                    'type' => $type,
                    'domain' => $domain,
                    // A domain is called by its name.
                    'label' => $type === ServiceType::Domain ? $domain : ($item['label'] ?? null),
                    'server_id' => $type->needsServer() ? ($item['server_id'] ?? null) : null,
                    'billing_cycle' => $cycle,
                    // Only when the caller picked them: its price includes them.
                    'options' => isset($item['options']) ? (ProductOptions::select($product, (array) $item['options'], BillingCycle::from($cycle instanceof BillingCycle ? $cycle->value : $cycle), $client->currencyCode()) ?: null) : null,
                    'currency' => $client->currencyCode(),
                    'start_date' => $today,
                    'next_due_date' => $today,
                    'status' => ServiceStatus::Pending,
                    // A domain that does not auto renew ends at its due date (Service::autoRenew()).
                    'auto_terminate' => $type === ServiceType::Domain && ! ($item['auto_renew'] ?? config('gaiacp.domains.auto_renew', true)),
                    'auto_setup' => $product?->auto_setup ?? AutoSetup::OnAccept,
                ]);

                $created[$index] = $service;

                if ($type === ServiceType::Domain) {
                    $service->domainDetails()->create([
                        'registration_type' => $item['registration_type'] ?? DomainRegistrationType::Register,
                        'registrar_id' => $item['registrar_id'] ?? null,
                        'epp_code' => filled($item['epp_code'] ?? null) ? $item['epp_code'] : null,
                        'nameservers' => array_values(array_filter($item['nameservers'] ?? [])) ?: null,
                    ]);
                }
            }

            ActivityLog::record('order.placed', "Order #{$order->id} placed with {$order->services()->count()} item(s)".($coupon ? " and coupon {$coupon->code}" : ''), client: $client, properties: array_filter(['order_id' => $order->id, 'coupon_id' => $coupon?->id]));

            return $order;
        });

        $this->mailer->orderConfirmation($order);
        $notice = $this->invoices->handle($order); // its payment notice, so the order shows as owed until paid

        // A coupon may leave nothing to pay: the notice is paid at once, so the order goes ahead as if paid.
        if ($notice !== null && $order->coupon_id !== null && $notice->status === InvoiceStatus::Unpaid && $notice->total_cents === 0) {
            try {
                $this->payments->handle($notice, CarbonImmutable::today(), PaymentMethod::Other->value, 'Coupon');
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Outside the transaction: the order stands even if a panel or registrar fails.
        $onOrder = $order->services()->where('auto_setup', AutoSetup::OnOrder)->pluck('id')->flip();
        $this->setupErrors = $onOrder->isEmpty() ? [] : array_intersect_key(
            $this->complete->setUp($order, fn (Service $service) => $service->auto_setup === AutoSetup::OnOrder),
            $onOrder->all(),
        );

        return $order->refresh();
    }

    /**
     * Takes each item's product off its Quantity available, locked so two orders at once can't both take the last one.
     * For an order from the client ($inStockOnly) the client's row is locked too and each product's Client limit
     * checked again, so two checkouts at once can't both get the last one a client may have (a free trial). Inside the
     * order's transaction, before its services are made. Also used by ClientAddons for an add-on ordered alone.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ValidationException under `product` when $inStockOnly and a product has fewer left than the items want,
     *                             or the client would go over its Client limit
     */
    public static function takeStock(array $items, bool $inStockOnly, ?Client $client = null): void
    {
        $wanted = collect($items)->pluck('product_id')->filter()->countBy();

        if ($wanted->isEmpty()) {
            return;
        }

        if ($inStockOnly && $client !== null) {
            Client::query()->whereKey($client->id)->lockForUpdate()->first();

            foreach (Product::query()->whereKey($wanted->keys()->all())->whereNotNull('client_limit')->get() as $product) {
                if (($problem = $product->limitProblem($client, (int) $wanted[$product->id])) !== null) {
                    throw ValidationException::withMessages(['product' => $problem]);
                }
            }
        }

        $products = Product::query()->whereKey($wanted->keys()->all())->whereNotNull('stock')->lockForUpdate()->get();

        foreach ($products as $product) {
            $count = (int) $wanted[$product->id];

            if ($inStockOnly && ($problem = $product->stockProblem($count)) !== null) {
                throw ValidationException::withMessages(['product' => $problem]);
            }

            Product::query()->whereKey($product->id)->update(['stock' => max(0, $product->stock - $count)]);
        }
    }

    /**
     * For an order from the client: refuses a domain another order already has (not cancelled or terminated), checked
     * again inside the order's transaction under a lock on its TLD's row, so two checkouts of the same domain at the
     * same moment (ClientOrder checks it before, outside any lock) can't both get it.
     *
     * @param  array<int, string>  $domains
     *
     * @throws ValidationException under `domain`
     */
    private static function claimDomains(array $domains): void
    {
        foreach (array_unique($domains) as $domain) {
            if ($tld = Tld::match($domain)) {
                Tld::query()->whereKey($tld->id)->lockForUpdate()->first();
            }

            // A locking read: it sees an order committed a moment ago, whatever the transaction read before.
            if (Service::query()->where('type', ServiceType::Domain)->where('domain', $domain)
                ->whereNotIn('status', [ServiceStatus::Cancelled, ServiceStatus::Terminated])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['domain' => __('This domain is already with us. Contact us if it is yours.')]);
            }
        }
    }

    /** @param  array<string, mixed>  $item */
    private static function type(array $item): ServiceType
    {
        return $item['type'] instanceof ServiceType ? $item['type'] : ServiceType::from($item['type']);
    }
}
