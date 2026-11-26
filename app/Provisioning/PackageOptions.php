<?php

namespace App\Provisioning;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Package names on a server, for form suggestions, with each one's size where the panel gives it (DescribesPackages).
 * Cached for ten minutes as plain arrays; an unreachable or unsupported server simply offers no suggestions.
 */
class PackageOptions
{
    /** @return list<string> */
    public static function forServer(int|string|null $serverId): array
    {
        // A name like "100" comes back from the array keys as a number
        return array_map('strval', array_keys(self::read($serverId)));
    }

    /** @return array<string, ?PackageLimits> each package's limits by name, null where the panel gives none */
    public static function limits(int|string|null $serverId): array
    {
        return array_map(fn (?array $limits) => $limits === null ? null : PackageLimits::fromArray($limits), self::read($serverId));
    }

    /** @return array<string, string> name => "name · 5 GB disk · 50 GB bandwidth" (the bare name when the size is not known) */
    public static function labels(int|string|null $serverId): array
    {
        $labels = [];
        foreach (self::limits($serverId) as $name => $limits) {
            $labels[(string) $name] = ($label = $limits?->label()) === null ? (string) $name : "{$name} · {$label}";
        }

        return $labels;
    }

    /** The label of one package, or its bare name when the server does not list it. */
    public static function label(int|string|null $serverId, ?string $package): ?string
    {
        return blank($package) ? null : (self::labels($serverId)[$package] ?? $package);
    }

    /** @return array<string, ?array<string, mixed>> */
    private static function read(int|string|null $serverId): array
    {
        $server = $serverId ? Server::find($serverId) : null;

        if (! $server) {
            return [];
        }

        return Cache::remember("gaiacp.packages.v2.{$server->id}.{$server->updated_at?->timestamp}", now()->addMinutes(10), function () use ($server) {
            try {
                $panel = app(ProvisionerFactory::class)->forServer($server);

                if ($panel instanceof DescribesPackages) {
                    return array_map(fn (PackageLimits $limits) => $limits->toArray(), $panel->packageLimits());
                }

                return array_fill_keys($panel->packages(), null);
            } catch (ProvisioningException) {
                return [];
            }
        });
    }
}
