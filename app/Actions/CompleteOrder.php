<?php

namespace App\Actions;

use App\Enums\AutoSetup;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ProvisioningException;

/**
 * Sets up every pending item of an order (SetUpService): domains first, then hosting, then
 * everything else. Items whose product says "do not set up automatically" are left for the
 * Account / Registrar menu.
 *
 * One failing item does not stop the others; it stays pending with the error in the activity
 * log, and running this again retries only what is still pending. The order is completed once
 * no item is pending.
 */
class CompleteOrder
{
    private const ORDER = [ServiceType::Domain, ServiceType::Hosting, ServiceType::Maintenance, ServiceType::Other];

    public function __construct(private SetUpService $setUp) {}

    /** @return array<int, string> service id => why it is still pending; empty when every item is set up */
    public function handle(Order $order): array
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw new ProvisioningException('This order is cancelled.');
        }

        return $this->setUp($order, fn (Service $service) => $service->auto_setup !== AutoSetup::Never);
    }

    /**
     * Store the registrar of pending domains and the server / package of pending hosting picked on
     * the Complete order screen, before anything is set up. Only this order's pending items change;
     * a registrar or server must be active unless it is the one the item already has.
     *
     * @param  array<int|string, array{registrar_id?: int|string|null, server_id?: int|string|null, package?: ?string}>  $choices  service id => choice
     */
    public function assign(Order $order, array $choices): void
    {
        $pending = $order->services()->with('domainDetails')->where('status', ServiceStatus::Pending)
            ->whereKey(array_keys($choices))->get();

        foreach ($pending as $service) {
            $choice = $choices[$service->id];

            if ($service->type === ServiceType::Domain && array_key_exists('registrar_id', $choice)) {
                $details = $service->registration();
                $registrarId = filled($choice['registrar_id']) ? (int) $choice['registrar_id'] : null;

                if ($registrarId === $details->registrar_id) {
                    continue;
                }

                if ($registrarId !== null && ! Registrar::query()->whereKey($registrarId)->where('active', true)->exists()) {
                    throw new ProvisioningException("Choose an active registrar for {$service->domain}.");
                }

                $details->update(['registrar_id' => $registrarId]);
                ActivityLog::record('order.item_changed', "{$service->domain}: registrar set to ".(Registrar::find($registrarId)->name ?? "the TLD's preferred one"), $service,
                    properties: ['order_id' => $order->id, 'registrar_id' => $registrarId]);
            }

            if ($service->type->needsServer() && filled($choice['server_id'] ?? null)) {
                $changes = array_filter([
                    'server_id' => (int) $choice['server_id'],
                    'package' => filled($choice['package'] ?? null) ? trim($choice['package']) : $service->package,
                ], fn ($value, string $key) => $value !== $service->{$key}, ARRAY_FILTER_USE_BOTH);

                if ($changes === []) {
                    continue;
                }

                if (isset($changes['server_id']) && ! Server::query()->whereKey($changes['server_id'])->where('active', true)->exists()) {
                    throw new ProvisioningException("Choose an active server for {$service->label}.");
                }

                $service->update($changes);
                ActivityLog::record('order.item_changed', "{$service->label}: server {$service->server->name}, package {$service->package}", $service,
                    properties: ['order_id' => $order->id, ...$changes]);
            }
        }
    }

    /**
     * Set up the order's pending items that $which accepts, in order.
     *
     * @param  callable(Service): bool  $which
     * @return array<int, string>
     */
    public function setUp(Order $order, callable $which): array
    {
        $failures = [];

        $pending = $order->services()->with('client', 'server', 'domainDetails', 'order')->where('status', ServiceStatus::Pending)->get()
            ->sortBy(fn (Service $service) => array_search($service->type, self::ORDER, true));

        foreach ($pending as $service) {
            if (! $which($service)) {
                $failures[$service->id] = 'Not set up automatically: use the Account or Registrar menu on the service.';

                continue;
            }

            try {
                $this->setUp->handle($service);
            } catch (ProvisioningException $e) {
                $failures[$service->id] = $e->getMessage(); // already in the activity log
            }
        }

        $this->setUp->completeIfDone($order->refresh());

        return $failures;
    }
}
