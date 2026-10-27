<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Service;
use App\Provisioning\ProvisioningException;

/**
 * Sets up one pending service: a domain is registered / transferred / tracked, hosting is
 * created on its panel, an SSL certificate is ordered, anything else just becomes active. Its order is completed once no
 * item is pending. Failures are logged and throw ProvisioningException, leaving it pending.
 */
class SetUpService
{
    public function __construct(private DomainLifecycle $domains, private ServiceLifecycle $lifecycle, private CertificateLifecycle $certificates) {}

    public function handle(Service $service): void
    {
        if ($service->status !== ServiceStatus::Pending) {
            return;
        }

        // No server yet (every one was full when it was ordered): try the product's servers again.
        if ($service->type->needsServer() && ! $service->server_id && ($server = $service->product?->pickServer())) {
            $service->update(['server_id' => $server->id]);
            ActivityLog::record('service.server_picked', "{$service->label} goes on {$server->name}", $service, properties: ['server_id' => $server->id]);
        }

        match (true) {
            $service->type === ServiceType::Domain => $this->domains->activate($service),
            $service->type === ServiceType::Ssl => $this->certificates->order($service),
            $service->type->needsServer() => $service->server_id
                ? $this->lifecycle->create($service)
                : $this->fail($service, $service->product?->servers()->exists()
                    ? 'Every server of this product is full: choose a server for this hosting account.'
                    : 'Choose a server for this hosting account first.'),
            default => $this->markActive($service),
        };

        if ($service->order) {
            $this->completeIfDone($service->order);
        }
    }

    /** Mark the order completed when none of its items is pending any more. */
    public function completeIfDone(Order $order): void
    {
        if ($order->status !== OrderStatus::Pending || $order->services()->where('status', ServiceStatus::Pending)->exists()) {
            return;
        }

        $order->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);
        ActivityLog::record('order.completed', "Order #{$order->id} completed", client: $order->client, properties: ['order_id' => $order->id]);
    }

    private function markActive(Service $service): void
    {
        $service->update(['status' => ServiceStatus::Active]);
        ActivityLog::record('service.activated', "{$service->label} activated", $service);
        $service->completeIfPaidOnce();
    }

    private function fail(Service $service, string $message): never
    {
        ActivityLog::record('provisioning.failed', "account.created failed: {$message}", $service, properties: ['event' => 'account.created']);

        throw new ProvisioningException($message);
    }
}
