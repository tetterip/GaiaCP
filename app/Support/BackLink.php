<?php

namespace App\Support;

use Filament\Pages\Page;
use Livewire\Livewire;

/**
 * The "‹ Payment notices" line over a page title on a phone: Filament hides its breadcrumbs on narrow screens, so
 * this links to the nearest breadcrumb that is not the page itself (none on a list page or the dashboard).
 */
class BackLink
{
    /** @return array{url: string, label: string}|null */
    public static function for(mixed $page): ?array
    {
        if (! $page instanceof Page || ! filament()->hasBreadcrumbs()) {
            return null;
        }

        $here = self::path(request()->hasHeader('X-Livewire') ? (string) Livewire::originalUrl() : request()->url());

        $back = null;

        foreach ($page->getBreadcrumbs() as $url => $label) {
            if (is_string($url) && self::path($url) !== $here) {
                $back = ['url' => $url, 'label' => strip_tags((string) $label)];
            }
        }

        return $back;
    }

    private static function path(string $url): string
    {
        return rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
