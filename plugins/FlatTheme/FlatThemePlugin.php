<?php

namespace Plugins\FlatTheme;

use App\Plugins\Plugin;
use App\Themes\ThemeModule;
use Filament\Enums\UserMenuPosition;
use Filament\Support\Colors\Color;

/** The admin panel's original colours (Filament's emerald, grey and Inter) in the shared layout, flat: no shadows or motion. */
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
        return __('The admin panel\'s original colours, emerald green on white, in the new layout without shadows or motion.');
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
        return ['primary' => Color::Emerald];
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

    /** Filament's zinc greys and emerald, as views/styles.blade.php uses them. */
    public function themeStandaloneColors(): array
    {
        return [
            'light' => ['bg' => '#fafafa', 'card' => '#ffffff', 'line' => '#e4e4e7', 'text' => '#09090b', 'muted' => '#71717a', 'accent' => '#059669'],
            'dark' => ['bg' => '#09090b', 'card' => '#18181b', 'line' => '#27272a', 'text' => '#ffffff', 'muted' => '#a1a1aa', 'accent' => '#34d399'],
            'radius' => '12px',
        ];
    }
}
