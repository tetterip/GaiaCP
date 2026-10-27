<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Monitoring\MonitorStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'public_name', 'on_status_page', 'type', 'hostname', 'port', 'shared_ip', 'monthly_cost_cents', 'max_accounts', 'ip_address', 'status_url', 'monitor_module', 'monitor_ref', 'username', 'api_token', 'verify_tls', 'active', 'notes'])]
#[Hidden(['api_token'])]
/**
 * A hosting panel. `type` is a ServerModule key from a plugin (Settings → Plugins), e.g. cpanel or plesk. It can be linked
 * to a monitor of a monitoring plugin (`monitor_module` / `monitor_ref`), whose status App\Monitoring\ServerMonitoring copies.
 */
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Another monitor (or none): its status is read afresh by the next check.
        static::saving(function (self $server) {
            if ($server->isDirty(['monitor_module', 'monitor_ref'])) {
                $server->monitor_status = null;
                $server->monitor_status_since = null;
                $server->monitor_checked_at = null;
                $server->monitor_uptime = null;
            }

            if (blank($server->monitor_module)) {
                $server->monitor_ref = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'monthly_cost_cents' => 'integer',
            'max_accounts' => 'integer',
            'api_token' => 'encrypted',
            'verify_tls' => 'boolean',
            'active' => 'boolean',
            'on_status_page' => 'boolean',
            'monitor_status' => MonitorStatus::class,
            'monitor_status_since' => 'immutable_datetime',
            'monitor_checked_at' => 'immutable_datetime',
            'monitor_uptime' => 'array',
            'ignored_accounts' => 'array',
            'compare_alerted' => 'array',
        ];
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** Hosting accounts on the panel: active and suspended hosting services. */
    public function accounts(): HasMany
    {
        return $this->services()->where('type', ServiceType::Hosting)->whereIn('status', ServiceStatus::billable());
    }

    /** Adds `accounts_count`, read by accountsUsed(). */
    public function scopeWithAccountsCount(Builder $query): void
    {
        $query->withCount('accounts');
    }

    public function accountsUsed(): int
    {
        return (int) ($this->accounts_count ?? $this->accounts()->count());
    }

    /** Share of max_accounts in use (0-1, can pass 1), or null without a maximum. */
    public function accountsShare(): ?float
    {
        return $this->max_accounts ? $this->accountsUsed() / $this->max_accounts : null;
    }

    /** "33/120 (28%)", or "33" without a maximum. */
    public function accountsText(): string
    {
        $used = $this->accountsUsed();

        return $this->max_accounts
            ? sprintf('%d/%d (%d%%)', $used, $this->max_accounts, round($this->accountsShare() * 100))
            : (string) $used;
    }

    /** The server in a picker: its name with its accounts ("Server 1 · accounts: 33/120 (28%)"), and "(inactive)" when it is. */
    public function optionLabel(): string
    {
        $label = $this->name.' · '.__('accounts: :count', ['count' => $this->accountsText()]);

        return $this->active ? $label : __(':server (inactive)', ['server' => $label]);
    }
}
