<?php

namespace Tests\Feature;

use App\Themes\Themes;
use Filament\Support\Colors\Color;
use Tests\TestCase;

/**
 * WCAG 1.4.3 (4.5:1 for text) on the colours every theme gives Filament: links, tabs and white text on filled buttons
 * use primary 600, secondary text the grey 500 (dark mode reads 500 as 400: filament/contrast.blade.php).
 */
class ThemeContrastTest extends TestCase
{
    public function test_every_theme_s_text_colours_pass_wcag_aa(): void
    {
        $this->assertNotEmpty(Themes::all());

        foreach (Themes::all() as $key => $theme) {
            $colors = $theme->themeColors();
            $primary = $colors['primary'] ?? Color::Amber;
            $gray = $colors['gray'] ?? Color::Zinc;
            $page = $theme->themeStandaloneColors()['light']['bg'];
            $darkCard = $theme->themeStandaloneColors()['dark']['card'];

            $this->assertGreaterThanOrEqual(4.5, $this->ratio($primary[600], '#ffffff'), "{$key}: primary 600 on white");
            $this->assertGreaterThanOrEqual(4.5, $this->ratio($gray[500], $page), "{$key}: grey 500 on the page");
            $this->assertGreaterThanOrEqual(4.5, $this->ratio($gray[500], '#ffffff'), "{$key}: grey 500 on a card");
            $this->assertGreaterThanOrEqual(4.5, $this->ratio($gray[400], $darkCard), "{$key}: grey 400 on a dark card");
            $this->assertGreaterThanOrEqual(4.5, $this->ratio($primary[400], $darkCard), "{$key}: primary 400 on a dark card");
        }
    }

    private function ratio(string $a, string $b): float
    {
        [$x, $y] = [$this->luminance($a), $this->luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** Relative luminance of #rrggbb or oklch(L C h). */
    private function luminance(string $color): float
    {
        if (preg_match('/^oklch\(([\d.]+)\s+([\d.]+)\s+([\d.]+)\)$/', trim($color), $m)) {
            [$l, $c, $h] = [(float) $m[1], (float) $m[2], deg2rad((float) $m[3])];
            [$a, $b] = [$c * cos($h), $c * sin($h)];
            $lms = [
                ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3,
                ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3,
                ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3,
            ];
            $rgb = [
                4.0767416621 * $lms[0] - 3.3077115913 * $lms[1] + 0.2309699292 * $lms[2],
                -1.2684380046 * $lms[0] + 2.6097574011 * $lms[1] - 0.3413193965 * $lms[2],
                -0.0041960863 * $lms[0] - 0.7034186147 * $lms[1] + 1.7076147010 * $lms[2],
            ];
            $rgb = array_map(fn (float $v): float => min(1, max(0, $v)), $rgb);
        } else {
            $rgb = array_map(function (string $hex): float {
                $v = hexdec($hex) / 255;

                return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($color, '#'), 2));
        }

        return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    }
}
