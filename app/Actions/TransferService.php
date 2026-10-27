<?php

namespace App\Actions;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves a set-up service (with its add-ons and, when asked, the domain service of the same name) to another client: the one way to
 * change a service's client once it left Pending, since the field is locked then.
 *
 * Its unpaid lines come off the old client's notices (overdue ones too: what is owed follows the service, and the
 * next daily run bills the new client). Payments already made stay with the client who made them (`payments.client_id`),
 * and so do the old order and notices. Nothing changes on the server panel or at the registrar: the account and the
 * registrant are moved there by hand.
 */
class TransferService
{
    /** @return list<Service> the services moved */
    public function handle(Service $service, Client $to, bool $withDomain = false): array
    {
        if ($service->client_id === $to->id) {
            throw new InvalidArgumentException('The service already belongs to this client.');
        }

        if (! self::transferable($service)) {
            throw new InvalidArgumentException('Only active or suspended services can be moved to another client.');
        }

        $services = [$service];

        if ($withDomain && ($domain = self::domainOf($service))) {
            $services[] = $domain;
        }

        // Add-ons go where their service goes.
        foreach ($services as $moved) {
            array_push($services, ...$moved->addons()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->get()->all());
        }

        return DB::transaction(function () use ($services, $to) {
            foreach ($services as $service) {
                $from = $service->client;

                Invoice::withdraw($service, overdueToo: true, reason: "it moved to client {$to->name}");
                $service->update(['client_id' => $to->id]);

                ActivityLog::record('service.transferred', "Moved from client {$from->name} to {$to->name}", $service, properties: ['from_client_id' => $from->id]);
                ActivityLog::record('service.transferred', "{$service->label} moved to client {$to->name}", client: $from, properties: ['service_id' => $service->id]);
            }

            return $services;
        });
    }

    public static function transferable(Service $service): bool
    {
        return in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);
    }

    /** For a hosting account: the same client's domain service of the same name, if it is still running. */
    public static function domainOf(Service $service): ?Service
    {
        if ($service->type === ServiceType::Domain || blank($service->domain)) {
            return null;
        }

        return Service::query()
            ->where('client_id', $service->client_id)
            ->where('type', ServiceType::Domain)
            ->where('domain', $service->domain)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->first();
    }
}
