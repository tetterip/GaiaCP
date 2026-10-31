<?php

namespace Tests\Feature;

use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Tests\TestCase;

class ThemeModeTest extends TestCase
{
    public function test_both_panels_start_in_light_mode(): void
    {
        foreach (['admin', 'client'] as $panel) {
            $this->assertSame(ThemeMode::Light, Filament::getPanel($panel)->getDefaultThemeMode(), $panel);
            $this->assertTrue(Filament::getPanel($panel)->hasDarkMode(), $panel.' still offers dark mode');
        }
    }
}
