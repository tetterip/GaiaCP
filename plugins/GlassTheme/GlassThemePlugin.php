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
            'primary' => Color::hex('#2B8486'),
            'gray' => Color::Slate,
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
