<?php

namespace App\Provisioning;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Package names on a server, for form suggestions. Cached for ten minutes;
 * an unreachable or unsupported server simply offers no suggestions.
 */
class PackageOptions
{
    /** @return list<string> */
    public static function forServer(int|string|null $serverId): array
    {
        $server = $serverId ? Server::find($serverId) : null;

        if (! $server) {
            return [];
        }

        return Cache::remember("gaiacp.packages.{$server->id}.{$server->updated_at?->timestamp}", now()->addMinutes(10), function () use ($server) {
            try {
                return app(ProvisionerFactory::class)->forServer($server)->packages();
            } catch (ProvisioningException) {
                return [];
            }
        });
    }
}
