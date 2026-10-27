<?php

namespace Plugins\Proxmox;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * An IP address of a Proxmox server's pool (Settings → Proxmox → IP addresses): a new VPS takes the first free one
 * (claim()) as its cloud-init ipconfig0, and gives it back when it is removed (release()).
 *
 * @property int $id
 * @property int $server_id
 * @property string $address
 * @property int $prefix
 * @property string|null $gateway
 * @property int|null $service_id
 */
class ProxmoxAddress extends Model
{
    protected $table = 'proxmox_addresses';

    protected $fillable = ['server_id', 'address', 'prefix', 'gateway', 'service_id'];

    protected function casts(): array
    {
        return ['prefix' => 'integer'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The service's address on its server: the one it holds already (a retry), else the first free one, else null
     * when the server has no free address left. Null too when the server has no pool at all (claim() can't tell
     * those apart, so callers check hasPool()).
     */
    public static function claim(Service $service): ?self
    {
        return DB::transaction(function () use ($service) {
            $held = self::query()->where('server_id', $service->server_id)->where('service_id', $service->id)->first();

            if ($held) {
                return $held;
            }

            $free = self::query()->where('server_id', $service->server_id)->whereNull('service_id')->orderBy('id')->lockForUpdate()->first();
            $free?->update(['service_id' => $service->id]);

            return $free;
        });
    }

    public static function hasPool(int $serverId): bool
    {
        return self::query()->where('server_id', $serverId)->exists();
    }

    public static function release(Service $service): void
    {
        self::query()->where('service_id', $service->id)->update(['service_id' => null]);
    }

    /** cloud-init's ipconfig0: "ip=203.0.113.10/24,gw=203.0.113.1" (ip6= for an IPv6 address). */
    public function ipConfig(): string
    {
        $v6 = str_contains($this->address, ':');
        $config = ($v6 ? 'ip6=' : 'ip=')."{$this->address}/{$this->prefix}";

        return $config.(filled($this->gateway) ? ($v6 ? ',gw6=' : ',gw=').$this->gateway : '');
    }

    /**
     * Addresses from text, one per line: "203.0.113.10/24 203.0.113.1" (address/prefix, then the gateway), or a range
     * "203.0.113.10-203.0.113.20/24 203.0.113.1" of IPv4 addresses. Blank lines and lines starting with # are skipped.
     *
     * @return array{0: list<array{address: string, prefix: int, gateway: ?string}>, 1: list<string>} the addresses and the lines that could not be read
     */
    public static function parse(string $text): array
    {
        $addresses = [];
        $invalid = [];

        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/[\s,]+/', $line);
            $gateway = $parts[1] ?? null;

            if (! preg_match('#^([^/]+)/(\d{1,3})$#', $parts[0], $m) || ($gateway !== null && ! filter_var($gateway, FILTER_VALIDATE_IP))) {
                $invalid[] = $line;

                continue;
            }

            [$first, $last] = array_pad(explode('-', $m[1], 2), 2, null);
            $prefix = (int) $m[2];
            $v4 = filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

            if (! filter_var($first, FILTER_VALIDATE_IP) || $prefix > ($v4 ? 32 : 128)) {
                $invalid[] = $line;

                continue;
            }

            if ($last === null) {
                $addresses[] = ['address' => $first, 'prefix' => $prefix, 'gateway' => $gateway];

                continue;
            }

            // A range: IPv4 only, at most 1024 addresses
            if (! $v4 || ! filter_var($last, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ip2long($last) < ip2long($first) || ip2long($last) - ip2long($first) >= 1024) {
                $invalid[] = $line;

                continue;
            }

            for ($ip = ip2long($first); $ip <= ip2long($last); $ip++) {
                $addresses[] = ['address' => long2ip($ip), 'prefix' => $prefix, 'gateway' => $gateway];
            }
        }

        return [$addresses, $invalid];
    }
}
