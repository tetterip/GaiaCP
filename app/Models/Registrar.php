<?php

namespace App\Models;

use App\Plugins\PluginManager;
use App\Provisioning\ManualRegistrar;
use Database\Factories\RegistrarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registrar account. `module` is a RegistrarModule key from a plugin (Settings → Plugins), e.g. easygr, or email
 * for the built-in Email Notifications account; credentials holds the module's login fields (e.g. username /
 * password for easy.gr), encrypted. Prices are per TLD (Tld), each naming its preferred registrar.
 *
 * @property array<string, mixed>|null $credentials
 */
#[Fillable(['name', 'module', 'credentials', 'verify_tls', 'active', 'is_default'])]
#[Hidden(['credentials'])]
class Registrar extends Model
{
    /** @use HasFactory<RegistrarFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'verify_tls' => 'boolean',
            'active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Only one default registrar.
        static::saved(function (Registrar $registrar) {
            if ($registrar->is_default) {
                static::query()->whereKeyNot($registrar->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    /** @return HasMany<Tld, $this> the TLDs this is the preferred registrar of */
    public function tlds(): HasMany
    {
        return $this->hasMany(Tld::class)->orderBy('tld');
    }

    /** Whether this account books nothing at a registrar but emails the admins (Email Notifications, ManualRegistrar). */
    public function isManual(): bool
    {
        return app(PluginManager::class)->registrarModule($this->module) instanceof ManualRegistrar;
    }

    /** @param  Builder<Registrar>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * The registrar that handles this domain's TLD: the preferred registrar under Settings → Domain pricing (the
     * longest TLD match, so "x.com.gr" picks ".com.gr" over ".gr"), while it is active.
     */
    public static function forDomain(string $domain): ?self
    {
        $registrar = Tld::match($domain, activeOnly: false)?->registrar;

        return $registrar?->active ? $registrar : null;
    }
}
