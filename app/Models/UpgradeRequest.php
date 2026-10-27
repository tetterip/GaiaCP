<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client asking to move a service to another product (upgrade / downgrade), applied only when an admin approves
 * (App\Actions\UpgradeRequests, which runs App\Actions\ChangeProduct).
 *
 * @property CarbonImmutable|null $decided_at
 */
#[Fillable(['service_id', 'client_id', 'product_id', 'price_cents', 'notes', 'status', 'decided_by', 'decided_at'])]
class UpgradeRequest extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'status' => ChangeRequestStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Product, $this> the product asked for */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** "Starter (example.gr)": the service as the admin and the client know it. */
    public function serviceName(): string
    {
        $service = $this->service;

        return filled($service->domain) && $service->domain !== $service->label ? "{$service->label} ({$service->domain})" : (string) $service->label;
    }

    /** @param Builder<UpgradeRequest> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ChangeRequestStatus::Pending);
    }
}
