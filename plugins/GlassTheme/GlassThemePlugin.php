<?php

namespace Plugins\GlassTheme;

use App\Plugins\Plugin;
use App\Themes\ThemeModule;
use Filament\Enums\UserMenuPosition;
use Filament\Support\Colors\Color;

/**
 * Frosted glass: see-through, blurred cards, sidebar, top bar, menus, windows and pill buttons over a soft background
 * in the logo's teal and greens, Manrope for text. Light and dark; solid where the browser can't blur or the system
 * asks for less transparency.
 */
class GlassThemePlugin extends Plugin implements ThemeModule
{
    public function id(): string
    {
        return 'glass_theme';
    }

    public function name(): string
    {
        return 'Glass';
    }

    public function description(): string
    {
        return __('Frosted glass cards, menus and buttons over a soft background in the logo\'s teal and greens, in light and dark.');
    }

    public function category(): string
    {
        return 'Themes';
    }

    public function themeKey(): string
    {
        return 'glass';
    }

    public function themeLabel(): string
    {
        return 'Glass';
    }

    public function themeColors(): array
    {
        return [
            // The logo's blue-teal
            // The logo's teal (#2B8486) by hand: Color::hex() made a bright 600 (#009ba3) that links, tabs and white button
            // text sit on at 3.4:1; this 600 is the logo's own shade and passes WCAG AA (4.5:1) on white and on the page
            'primary' => [
                50 => 'oklch(0.977 0.014 197.2)',
                100 => 'oklch(0.950 0.033 197.2)',
                200 => 'oklch(0.905 0.060 197.2)',
                300 => 'oklch(0.840 0.090 197.2)',
                400 => 'oklch(0.755 0.120 197.2)',
                500 => 'oklch(0.640 0.125 197.2)',
                600 => 'oklch(0.520 0.105 197.2)',
                700 => 'oklch(0.460 0.090 197.2)',
                800 => 'oklch(0.405 0.076 197.2)',
                900 => 'oklch(0.360 0.062 197.2)',
                950 => 'oklch(0.260 0.045 197.2)',
            ],
            // Slate with a darker 500, the grey of secondary text (breadcrumbs, tabs, hints), for 4.5:1 on the page
            'gray' => array_replace(Color::Slate, [500 => 'oklch(0.500 0.040 257.417)']),
            'success' => Color::hex('#2F8A5C'),
        ];
    }

    public function themeFont(): ?string
    {
        return 'Manrope';
    }

    public function themeSerifFont(): ?string
    {
        return null;
    }

    public function themeUserMenuPosition(): UserMenuPosition
    {
        return UserMenuPosition::Sidebar;
    }

    public function themeStyles(): ?string
    {
        return 'glass_theme::styles';
    }

    /** Solid versions of the --glass-* colours of views/styles.blade.php (the standalone pages have no background art). */
    public function themeStandaloneColors(): array
    {
        return [
            'light' => ['bg' => '#e9f1f1', 'card' => '#ffffff', 'line' => '#d8e3e4', 'text' => '#0f1c1f', 'muted' => '#56666a', 'accent' => '#1f6e70'],
            'dark' => ['bg' => '#0a0f11', 'card' => '#151c1f', 'line' => '#26302f', 'text' => '#e8eff0', 'muted' => '#9aabad', 'accent' => '#7cc9c4'],
            'radius' => '24px',
        ];
    }
}
