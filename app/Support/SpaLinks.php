<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Livewire\Component;

/**
 * SPA mode of the two panels (->spa()): a link to another page of the same panel swaps only the page (wire:navigate,
 * fetched while the pointer rests on it), so a click does not load and start every script and style again. Everything
 * else opens as a normal page: the other panel, and every address that is not a page of this panel (a download, a
 * ticket file, Log in to control panel, a language switch, a payment return, a plugin's own route), which must not be
 * fetched ahead either. Read from the routes, so a plugin's routes are covered without a list to keep.
 */
final class SpaLinks
{
    /** @var array<string, list<string>> */
    private static array $exceptions = [];

    /** @return list<string> URL patterns (Str::is) that open as a normal page from this panel */
    public static function exceptions(string $panel): array
    {
        return self::$exceptions[$panel] ??= self::build($panel);
    }

    /** @return list<string> */
    private static function build(string $panel): array
    {
        $pages = [];  // first path segments of this panel's pages
        $others = []; // first segment => the other addresses under it

        // Left out: the site root (the client area's home) and the client area's product group pages at /{group},
        // whose pattern would match every address; the admin opens those in a new tab anyway.

        foreach (Router::getRoutes()->getRoutes() as $route) {
            foreach (self::uris($route) as $uri) {
                $first = explode('/', $uri)[0];

                if (self::isPageOf($route, $panel)) {
                    $pages[$first] = true;
                } elseif ($uri !== '' && $first !== '*' && in_array('GET', $route->methods(), true)) {
                    $others[$first][] = $uri;
                }
            }
        }

        $patterns = [];

        foreach ($others as $first => $uris) {
            // A segment with no page of this panel (admin/, api/, livewire's own) is one pattern, else each address
            foreach (isset($pages[$first]) ? array_unique($uris) : [$first] as $uri) {
                array_push($patterns, url($uri), url($uri).'?*', url($uri).'/*');
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * The route's address as patterns: a parameter is "*", or each of its words when its where() is a list of words
     * (the old /admin/{area} bookmarks: products|servers|…, which must not cover every admin page); an optional one
     * also left out.
     *
     * @return list<string>
     */
    private static function uris(Route $route): array
    {
        $uris = [trim($route->uri(), '/')];

        while (preg_match('/\{(\w+)\??\}/', $uris[0], $match) === 1) {
            $where = (string) ($route->wheres[$match[1]] ?? '');
            $values = preg_match('/^[\w.-]+(\|[\w.-]+)*$/', $where) === 1 ? explode('|', $where) : ['*'];
            if (str_ends_with($match[0], '?}')) {
                $values[] = ''; // an optional one may be left out
            }
            $uris = array_merge(...array_map(
                fn (string $uri) => array_map(fn (string $value) => (string) preg_replace('/\{'.$match[1].'\??\}/', $value, $uri, 1), $values),
                $uris,
            ));
        }

        return array_map(fn (string $uri) => trim((string) preg_replace('#/+#', '/', $uri), '/'), $uris);
    }

    /** A Livewire page (Filament page, resource page) of this panel, which wire:navigate can open. */
    private static function isPageOf(Route $route, string $panel): bool
    {
        if (! str_starts_with((string) $route->getName(), "filament.{$panel}.")) {
            return false;
        }

        $class = $route->getControllerClass();

        return $class !== null && is_subclass_of($class, Component::class);
    }

    public static function flush(): void
    {
        self::$exceptions = [];
    }
}
