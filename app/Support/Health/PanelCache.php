<?php

namespace App\Support\Health;

use Throwable;

/**
 * Filament's component cache (`bootstrap/cache/filament/panels/<panel>.php`, written by `optimize`) lists every page,
 * plugin pages included. A plugin folder that went away after it was written (moved to the plugin catalogue and
 * removed by a deploy, or deleted by hand) left a page class in it that no longer exists, and every admin page then
 * failed with "Class not found" while building the menu. A cached list whose plugin pages differ from the plugins
 * there now is dropped before the panel reads it, so Filament finds its pages again (and the scheduler caches them
 * again, SpeedCaches).
 */
class PanelCache
{
    /**
     * @param  list<class-string>  $pluginPages  the pages the plugins add now (PluginManager::pages())
     * @return bool whether a stale cache was removed
     */
    public static function forgetIfStale(string $panel, array $pluginPages): bool
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false; // Filament never reads the cache there
        }

        $path = (config('filament.cache_path') ?? base_path('bootstrap/cache/filament')).DIRECTORY_SEPARATOR.'panels'.DIRECTORY_SEPARATOR."{$panel}.php";

        if (! is_file($path)) {
            return false;
        }

        try {
            $cache = require $path;
            $cached = array_values(array_filter((array) ($cache['pages'] ?? []), fn ($page) => is_string($page) && str_starts_with($page, 'Plugins\\')));
        } catch (Throwable) {
            $cached = null; // unreadable: drop it too
        }

        if ($cached !== null) {
            $cached = array_values(array_unique($cached));
            $pluginPages = array_values(array_unique($pluginPages));
            sort($cached);
            sort($pluginPages);

            if ($cached === $pluginPages) {
                return false;
            }
        }

        @unlink($path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }
}
