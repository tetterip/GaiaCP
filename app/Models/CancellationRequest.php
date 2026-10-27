<?php

namespace App\Models;

use App\Enums\CancellationType;
use App\Enums\ChangeRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client asking to cancel a service, immediately or at the end of its billing period, applied only when an admin
 * approves (App\Actions\CancellationRequests).
 *
 * @property CarbonImmutable|null $decided_at
 */
#[Fillable(['service_id', 'client_id', 'type', 'reason', 'domain_service_id', 'status', 'decided_by', 'decided_at'])]
class CancellationRequest extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'type' => CancellationType::class,
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

    /** @return BelongsTo<Service, $this> the domain the client also asked not to renew */
    public function domainService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'domain_service_id');
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

    /** @param Builder<CancellationRequest> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ChangeRequestStatus::Pending);
    }
}
