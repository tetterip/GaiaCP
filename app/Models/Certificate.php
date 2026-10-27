<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider details of an SSL certificate service (App\Actions\CertificateLifecycle). The domain is the service's
 * `domain` (a wildcard certificate covers *.domain too); price, cycle, due date and payments stay on the service.
 * `expires_at` is the certificate's end, `ends_at` the end of what was bought at the provider: a certificate lasts at
 * most 200 days, so a longer order is a subscription that is reissued before each certificate ends.
 *
 * @property array<string, mixed>|null $validation
 * @property list<string>|null $previous_orders
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $ends_at
 */
#[Fillable([
    'service_id', 'provider', 'product_code', 'remote_id', 'status', 'wildcard', 'dcv_method', 'approver_email', 'validation',
    'csr', 'private_key', 'certificate', 'ca_bundle', 'issued_at', 'expires_at', 'ends_at', 'previous_orders', 'error', 'last_checked_at',
])]
#[Hidden(['private_key'])]
class Certificate extends Model
{
    protected $attributes = [
        'status' => 'not_ordered',
    ];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'wildcard' => 'boolean',
            'validation' => 'array',
            'private_key' => 'encrypted',
            'previous_orders' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_date',
            'ends_at' => 'immutable_date',
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** The name it is issued for: the service's domain, with "*." in front for a wildcard. */
    public function commonName(): string
    {
        return ($this->wildcard ? '*.' : '').$this->service->domain;
    }

    /** Ordered at the provider and still in its hands (not cancelled, rejected or expired). */
    public function ordered(): bool
    {
        return filled($this->remote_id) && in_array($this->status, [CertificateStatus::Pending, CertificateStatus::Issued], true);
    }
}
