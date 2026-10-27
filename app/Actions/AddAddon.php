<?php

namespace App\Actions;

use App\Enums\AutoSetup;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adds an add-on (a product marked addon) to a service. The add-on is a service of its own, pointing at that one
 * (parent_service_id), on the same client, cycle and due date, so both are on the same payment notice:
 * - on a pending service it is pending in the same order, owed with the order's first payment (setup fee included);
 * - on an active or suspended one it starts today and is first owed on that service's next due date, with its setup
 *   fee then: the days until then are not charged (no proration).
 * After that it follows the service (follow()): suspended, unsuspended, set to end and ended with it.
 */
class AddAddon
{
    public function handle(Service $parent, Product $addon, ?int $priceCents = null, ?string $label = null): Service
    {
        if (! $addon->addon) {
            throw new InvalidArgumentException('Only an add-on product can be added to a service.');
        }

        if (! self::canHaveAddons($parent)) {
            throw new InvalidArgumentException('Add-ons go on a pending, active or suspended service that is not an add-on itself.');
        }

        $price = $priceCents ?? $addon->priceFor($parent->billing_cycle, $parent->currency)
            ?? throw new InvalidArgumentException("{$addon->name} has no {$parent->billing_cycle->value} price.");
        $setup = $addon->setupFeeFor($parent->billing_cycle, $parent->currency);
        $pending = $parent->status === ServiceStatus::Pending;

        return DB::transaction(function () use ($parent, $addon, $price, $setup, $pending, $label) {
            $service = Service::create([
                'client_id' => $parent->client_id,
                'order_id' => $pending ? $parent->order_id : null,
                'parent_service_id' => $parent->id,
                'type' => $addon->type,
                'product_id' => $addon->id,
                'label' => filled($label) ? $label : $addon->name,
                'domain' => $parent->domain,
                'price_cents' => $price,
                'first_payment_cents' => $setup > 0 ? $price + $setup : null,
                'currency' => $parent->currency,
                'billing_cycle' => $parent->billing_cycle,
                'start_date' => today(),
                'next_due_date' => $parent->next_due_date,
                'status' => $pending ? ServiceStatus::Pending : $parent->status,
                'suspension_reason' => $pending ? null : $parent->suspension_reason,
                'auto_setup' => $pending ? ($addon->auto_setup ?? AutoSetup::OnAccept) : AutoSetup::OnAccept,
                'auto_terminate' => (bool) $parent->auto_terminate,
                'client_visible' => $parent->client_visible && $addon->client_visible,
            ]);

            ActivityLog::record('service.addon_added', "Add-on {$service->label} added to {$parent->label}", $parent, properties: ['addon_service_id' => $service->id]);

            return $service;
        });
    }

    /** A service that is not over and not an add-on itself. */
    public static function canHaveAddons(Service $service): bool
    {
        return $service->parent_service_id === null
            && in_array($service->status, [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended], true);
    }

    /** Its add-ons that are not over take the service's new status (ended or suspended) and its Auto-terminate. */
    public static function follow(Service $parent): void
    {
        $addons = $parent->addons()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->get();

        foreach ($addons as $addon) {
            $changes = [];

            if ($parent->wasChanged('auto_terminate') && (bool) $addon->auto_terminate !== (bool) $parent->auto_terminate) {
                $changes += ['auto_terminate' => (bool) $parent->auto_terminate, 'auto_terminate_reason' => $parent->auto_terminate_reason];
            }

            if ($parent->wasChanged('status')) {
                $status = match (true) {
                    in_array($parent->status, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true) => $parent->status,
                    $parent->status === ServiceStatus::Suspended && $addon->status === ServiceStatus::Active => ServiceStatus::Suspended,
                    $parent->status === ServiceStatus::Active && $addon->status === ServiceStatus::Suspended => ServiceStatus::Active,
                    default => null,
                };

                if ($status !== null) {
                    $changes += ['status' => $status, 'suspension_reason' => $status === ServiceStatus::Suspended ? $parent->suspension_reason : null];
                }
            }

            if ($changes === []) {
                continue;
            }

            $addon->update($changes);
            ActivityLog::record('service.addon_followed', isset($changes['status'])
                ? "Add-on {$changes['status']->value} with {$parent->label}"
                : 'Add-on Auto-terminate '.($parent->auto_terminate ? 'on' : 'off')." with {$parent->label}", $addon);
        }
    }
}
