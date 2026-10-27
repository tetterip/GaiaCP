<?php

namespace Plugins\SiteChecks;

use App\Models\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The last DNS and HTTPS check of an active hosting account's domain (SiteCheckRunner). */
#[Fillable(['service_id', 'domain', 'dns_status', 'addresses', 'ssl_status', 'ssl_valid_to', 'ssl_issuer', 'ssl_error', 'alerted', 'checked_at'])]
class SiteCheck extends Model
{
    protected function casts(): array
    {
        return [
            'dns_status' => DnsStatus::class,
            'addresses' => 'array',
            'ssl_status' => SslStatus::class,
            'ssl_valid_to' => 'immutable_datetime',
            'alerted' => 'array',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * What is wrong now, as keys that stay the same from one check to the next ("dns:elsewhere", "ssl:expiring").
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return array_values(array_filter([
            $this->dns_status->isProblem() ? 'dns:'.$this->dns_status->value : null,
            $this->ssl_status->isProblem() ? 'ssl:'.$this->ssl_status->value : null,
        ]));
    }
}
