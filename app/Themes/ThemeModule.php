<?php

namespace App\Themes;

use Filament\Enums\UserMenuPosition;

/**
 * A look for the admin panel, from a plugin (Plugin::themeModules()): its colours, fonts and a stylesheet laid over
 * Filament's own. Each admin picks one in Profile; Settings → General sets the default (App\Themes\Themes). The
 * stylesheet is plain CSS in a Blade view, so nothing has to be built on the server.
 */
interface ThemeModule
{
    /** Stable key, stored in `users.theme` and `gaiacp.theme.default`. */
    public function themeKey(): string;

    public function themeLabel(): string;

    /** @return array<string, string|array<int, string>> Filament colours (primary, gray, ...) */
    public function themeColors(): array;

    /** Body font from Bunny Fonts, or null for Filament's own Inter. */
    public function themeFont(): ?string;

    /** Headings font from Bunny Fonts (`--serif-font-family`), or null for none. */
    public function themeSerifFont(): ?string;

    /** Where the admin's menu sits: their avatar in the top bar, or a card at the foot of the sidebar. */
    public function themeUserMenuPosition(): UserMenuPosition;

    /** View with its stylesheet (a <style> block), or null when Filament's look is enough. */
    public function themeStyles(): ?string;

    /**
     * Colours for the pages outside Filament (the client area's maintenance and ban pages, themes/standalone): bg,
     * card, line, text, muted, accent (CSS colours) for each scheme, and the cards' radius.
     *
     * @return array{light: array<string, string>, dark: array<string, string>, radius: string}
     */
    public function themeStandaloneColors(): array;
}
