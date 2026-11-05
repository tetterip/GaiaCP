<?php

namespace Plugins\GaiaTheme;

use App\Plugins\Plugin;
use App\Themes\ThemeModule;
use Filament\Enums\UserMenuPosition;
use Filament\Support\Colors\Color;

/**
 * GaiaCP's own look (the redesign): neutral greys, the logo's teal, rounded cards on a grey inset, Commissioner for
 * text and Literata for headings, and quiet motion that `prefers-reduced-motion` turns off. Light and dark.
 */
class GaiaThemePlugin extends Plugin implements ThemeModule
{
    public function id(): string
    {
        return 'gaia_theme';
    }

    public function name(): string
    {
        return 'Gaia';
    }

    public function description(): string
    {
        return __('GaiaCP\'s own look: soft greys, the logo\'s teal, rounded cards and quiet motion, in light and dark.');
    }

    public function category(): string
    {
        return 'Themes';
    }

    public function themeKey(): string
    {
        return 'gaia';
    }

    public function themeLabel(): string
    {
        return 'Gaia';
    }

    public function themeColors(): array
    {
        return [
            // The logo's teal (#2C7A7C) by hand: Color::hex() made a bright 600 (#009ba3) that links, tabs and white button
            // text sit on at 3.4:1; this 600 is the logo's own shade and passes WCAG AA (4.5:1) on white and on the page
            'primary' => [
                50 => 'oklch(0.977 0.014 197.5)',
                100 => 'oklch(0.950 0.033 197.5)',
                200 => 'oklch(0.905 0.060 197.5)',
                300 => 'oklch(0.840 0.090 197.5)',
                400 => 'oklch(0.755 0.120 197.5)',
                500 => 'oklch(0.640 0.125 197.5)',
                600 => 'oklch(0.520 0.105 197.5)',
                700 => 'oklch(0.460 0.090 197.5)',
                800 => 'oklch(0.405 0.076 197.5)',
                900 => 'oklch(0.360 0.062 197.5)',
                950 => 'oklch(0.260 0.045 197.5)',
            ],
            // Zinc with a darker 500, the grey of secondary text (breadcrumbs, tabs, hints), for 4.5:1 on the page
            'gray' => array_replace(Color::Zinc, [500 => 'oklch(0.510 0.016 285.938)']),
            // The mockup's leaf green, softer than Filament's, for paid / live / active
            'success' => Color::hex('#2F7D52'),
        ];
    }

    public function themeFont(): ?string
    {
        return 'Commissioner';
    }

    public function themeSerifFont(): ?string
    {
        return 'Literata';
    }

    public function themeUserMenuPosition(): UserMenuPosition
    {
        return UserMenuPosition::Sidebar;
    }

    public function themeStyles(): ?string
    {
        return 'gaia_theme::styles';
    }

    /** The --gaia-* values of views/styles.blade.php. */
    public function themeStandaloneColors(): array
    {
        return [
            'light' => ['bg' => '#f4f4f5', 'card' => '#ffffff', 'line' => '#e4e4e7', 'text' => '#18181b', 'muted' => '#5b5b63', 'accent' => '#23696b'],
            'dark' => ['bg' => '#0f0f10', 'card' => '#1a1a1c', 'line' => '#2a2a2e', 'text' => '#ededef', 'muted' => '#a1a1a8', 'accent' => '#72cbb8'],
            'radius' => '22px',
        ];
    }
}
