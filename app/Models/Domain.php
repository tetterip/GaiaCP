<?php

namespace App\Models;

use App\Enums\DomainRegistrationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registrar details of a domain service. The domain name itself is the
 * service's `domain`; price, cycle, due date and payments stay on the service.
 *
 * @property list<string>|null $nameservers
 * @property list<array{host: string, ip: string}>|null $child_nameservers
 * @property CarbonImmutable|null $expires_at
 */
#[Fillable(['service_id', 'registrar_id', 'registration_type', 'epp_code', 'nameservers', 'child_nameservers', 'registered_at', 'expires_at', 'last_synced_at'])]
#[Hidden(['epp_code'])]
class Domain extends Model
{
    protected $attributes = [
        'registration_type' => 'register',
    ];

    protected function casts(): array
    {
        return [
            'registration_type' => DomainRegistrationType::class,
            'epp_code' => 'encrypted',
            'nameservers' => 'array',
            'child_nameservers' => 'array',
            'registered_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Registrar, $this> */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(Registrar::class);
    }

    /** The chosen registrar account, else the TLD's preferred one (Settings → Domain pricing). */
    public function registrarAccount(): ?Registrar
    {
        return $this->registrar ?? Registrar::forDomain((string) $this->service->domain);
    }
}
