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
            'primary' => Color::hex('#2C7A7C'),
            'gray' => Color::Zinc,
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
