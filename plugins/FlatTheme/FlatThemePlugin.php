<?php

namespace Plugins\FlatTheme;

use App\Plugins\Plugin;
use App\Themes\ThemeModule;
use Filament\Enums\UserMenuPosition;

/** The logo's green with Filament's grey and Inter in the shared layout, flat: no shadows or motion. */
class FlatThemePlugin extends Plugin implements ThemeModule
{
    public function id(): string
    {
        return 'flat_theme';
    }

    public function name(): string
    {
        return 'Flat';
    }

    public function description(): string
    {
        return __('The logo\'s green on white, in the new layout without shadows or motion.');
    }

    public function category(): string
    {
        return 'Themes';
    }

    public function themeKey(): string
    {
        return 'flat';
    }

    public function themeLabel(): string
    {
        return 'Flat';
    }

    public function themeColors(): array
    {
        // The logo's greens (#3D986B, deep #277259), softer than Filament's emerald; 600 is dark enough for white
        // button text (WCAG AA), so buttons are filled green like the logo rather than pale.
        return ['primary' => [
            50 => 'oklch(0.975 0.015 161)',
            100 => 'oklch(0.950 0.032 161)',
            200 => 'oklch(0.905 0.058 161)',
            300 => 'oklch(0.840 0.085 161)',
            400 => 'oklch(0.760 0.108 161)',
            500 => 'oklch(0.660 0.114 161)',
            600 => 'oklch(0.550 0.103 161)',
            700 => 'oklch(0.485 0.092 161)',
            800 => 'oklch(0.430 0.078 161)',
            900 => 'oklch(0.380 0.064 161)',
            950 => 'oklch(0.270 0.045 161)',
        ]];
    }

    public function themeFont(): ?string
    {
        return null;
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
        return 'flat_theme::styles';
    }

    /** Filament's zinc greys and the logo's green, as views/styles.blade.php uses them. */
    public function themeStandaloneColors(): array
    {
        return [
            'light' => ['bg' => '#fafafa', 'card' => '#ffffff', 'line' => '#e4e4e7', 'text' => '#09090b', 'muted' => '#71717a', 'accent' => '#2c845e'],
            'dark' => ['bg' => '#09090b', 'card' => '#18181b', 'line' => '#27272a', 'text' => '#ffffff', 'muted' => '#a1a1aa', 'accent' => '#6dc69b'],
            'radius' => '12px',
        ];
    }
}
