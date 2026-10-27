<?php

namespace App\Provisioning;

use App\Models\Service;
use App\Plugins\PluginManager;

/**
 * cPanel-safe usernames from a domain: lowercase letters and digits, starts
 * with a letter, at most 16 characters (fewer where the server's panel says so,
 * LimitsUsernameLength), never starting with "test", and not already used by
 * another service on the same server.
 */
class UsernameGenerator
{
    public const MAX_LENGTH = 16;

    public function forService(Service $service): string
    {
        $max = self::maxLength($service->server?->type);
        $base = substr($this->base((string) $service->domain), 0, $max);

        for ($i = 0; ; $i++) {
            $suffix = $i === 0 ? '' : (string) $i;
            $candidate = substr($base, 0, $max - strlen($suffix)).$suffix;

            $taken = Service::query()
                ->where('server_id', $service->server_id)
                ->where('username', $candidate)
                ->whereKeyNot($service->getKey())
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }
    }

    /** The longest username a server of this type takes. */
    public static function maxLength(?string $serverType): int
    {
        $module = $serverType ? app(PluginManager::class)->serverModule($serverType) : null;

        return $module instanceof LimitsUsernameLength ? min(self::MAX_LENGTH, $module->maxUsernameLength()) : self::MAX_LENGTH;
    }

    public function base(string $domain): string
    {
        $label = strtolower(explode('.', preg_replace('/^www\./i', '', trim($domain)))[0]);
        $clean = preg_replace('/[^a-z0-9]/', '', $label);
        $clean = ltrim($clean, '0123456789');

        if ($clean === '' || str_starts_with($clean, 'test')) {
            $clean = 'u'.$clean;
        }

        return substr($clean, 0, self::MAX_LENGTH);
    }
}
