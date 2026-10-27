<?php

namespace App\Actions;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Invoices\InvoiceOrder;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Support\DomainName;
use App\Support\ProductOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an order and its items as pending services. Each item takes its product's
 * auto-setup choice: items set up "as soon as an order is placed" are set up right after
 * (CompleteOrder::setUp), the rest on their first payment or when the order is completed.
 */
class PlaceOrder
{
    /** @var array<int, string> service id => why an item to set up on order is still pending */
    public array $setupErrors = [];

    public function __construct(private ClientMailer $mailer, private CompleteOrder $complete, private InvoiceOrder $invoices) {}

    /**
     * @param  list<array<string, mixed>>  $items  service fields (prices in cents, options included) plus the
     *                                             product options picked ({option id: pick}), for an add-on its
     *                                             item's index in parent (an earlier item), and for a domain
     *                                             registration_type, registrar_id, epp_code, nameservers, auto_renew
     * @param  array{terms_accepted_at?: mixed, terms_version?: string|null}  $terms  the client accepted the terms of service (TermsOfService::acceptance())
     */
    public function handle(Client $client, array $items, ?string $notes = null, array $terms = []): Order
    {
        // A hosting item without a domain is for the order's domain, when there is exactly one.
        $domains = collect($items)->filter(fn (array $item) => self::type($item) === ServiceType::Domain)->map(fn (array $item) => DomainName::normalize($item['domain'] ?? null))->filter();

        // Its account on the panel is made for its domain (and its username from it): never one without.
        foreach ($items as $item) {
            if (self::type($item) === ServiceType::Hosting && DomainName::normalize($item['domain'] ?? null) === '' && $domains->count() !== 1) {
                throw ValidationException::withMessages(['domain' => __('Enter the hosting\'s domain: this order has no single domain it can take.')]);
            }
        }

        $order = DB::transaction(function () use ($client, $items, $notes, $domains, $terms) {
            $order = (new Order(['client_id' => $client->id, 'status' => OrderStatus::Pending, 'notes' => $notes]))->forceFill($terms);
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
                    ...Arr::only($item, ['product_id', 'label', 'price_cents', 'first_payment_cents', 'billing_cycle', 'package']),
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

            ActivityLog::record('order.placed', "Order #{$order->id} placed with {$order->services()->count()} item(s)", client: $client, properties: ['order_id' => $order->id]);

            return $order;
        });

        $this->mailer->orderConfirmation($order);
        $this->invoices->handle($order); // its payment notice, so the order shows as owed until paid

        // Outside the transaction: the order stands even if a panel or registrar fails.
        $onOrder = $order->services()->where('auto_setup', AutoSetup::OnOrder)->pluck('id')->flip();
        $this->setupErrors = $onOrder->isEmpty() ? [] : array_intersect_key(
            $this->complete->setUp($order, fn (Service $service) => $service->auto_setup === AutoSetup::OnOrder),
            $onOrder->all(),
        );

        return $order->refresh();
    }

    /** @param  array<string, mixed>  $item */
    private static function type(array $item): ServiceType
    {
        return $item['type'] instanceof ServiceType ? $item['type'] : ServiceType::from($item['type']);
    }
}
