<?php

namespace App\Themes;

use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Enums\UserMenuPosition;
use Filament\FontProviders\BunnyFontProvider;
use Filament\FontProviders\LocalFontProvider;
use Filament\Support\Colors\Color;

/**
 * The panels' themes. The admin panel's: the signed-in admin's own (`users.theme`, Profile) while its plugin is on,
 * else Settings → General's default (`gaiacp.theme.default`), else the first theme that is on. The client panel's
 * (and the client area's standalone pages): Settings → Client area's (`gaiacp.client_area.theme`), else the same
 * fallbacks. With every theme plugin off a panel keeps Filament's look in emerald.
 */
class Themes
{
    /** @return array<string, ThemeModule> key => theme, of the enabled plugins */
    public static function all(): array
    {
        return collect(app(PluginManager::class)->themeModules())
            ->keyBy(fn (ThemeModule $theme) => $theme->themeKey())
            ->all();
    }

    /** @return array<string, string> key => label */
    public static function options(): array
    {
        return array_map(fn (ThemeModule $theme) => $theme->themeLabel(), self::all());
    }

    public static function find(?string $key): ?ThemeModule
    {
        return blank($key) ? null : (self::all()[$key] ?? null);
    }

    /** Settings → General's default while its plugin is on, else the first one on. */
    public static function default(): ?ThemeModule
    {
        return self::find(config('gaiacp.theme.default')) ?? (array_values(self::all())[0] ?? null);
    }

    public static function for(?User $user): ?ThemeModule
    {
        return self::find($user?->theme) ?? self::default();
    }

    /** Settings → Client area's theme while its plugin is on, else the default. */
    public static function forClientArea(): ?ThemeModule
    {
        return self::find(config('gaiacp.client_area.theme')) ?? self::default();
    }

    /** In the client panel the client area's theme, else that of the admin signed in (the default on the login pages). */
    public static function current(): ?ThemeModule
    {
        if (filament()->getCurrentPanel()?->getId() === 'client') {
            return self::forClientArea();
        }

        $user = filament()->auth()->user();

        return self::for($user instanceof User ? $user : null);
    }

    /** @return array<string, string|array<int, string>> the theme's colours; in the client panel Settings → Client area's brand colour as primary */
    public static function colors(?ThemeModule $theme): array
    {
        $colors = $theme?->themeColors() ?? ['primary' => Color::Emerald];

        if (filament()->getCurrentPanel()?->getId() === 'client' && $brand = self::brandColor()) {
            $colors['primary'] = Color::hex($brand);
        }

        return $colors;
    }

    /** Settings → Client area → Brand colour (#rrggbb), or null for the theme's own. */
    public static function brandColor(): ?string
    {
        $color = config('gaiacp.client_area.brand_color');

        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : null;
    }

    /** @return class-string the Filament font provider for a font, or the local one (Inter) without */
    public static function fontProvider(?string $font): string
    {
        return filled($font) ? BunnyFontProvider::class : LocalFontProvider::class;
    }

    public static function userMenuPosition(): UserMenuPosition
    {
        return self::current()?->themeUserMenuPosition() ?? UserMenuPosition::Topbar;
    }

    /** The current theme's stylesheet, or nothing. */
    public static function styles(): string
    {
        $view = self::current()?->themeStyles();

        return $view ? view($view)->render() : '';
    }
}
