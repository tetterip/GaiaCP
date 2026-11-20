<?php

namespace App\Support;

use GdImage;
use Throwable;

/**
 * A logo that stays readable when a mail client turns the email dark. Gmail's apps and Outlook invert the colours of
 * an email in dark mode but never its images, so a dark logo on a transparent background ends up dark on dark.
 * The copy made here has a thin white outline around everything that is drawn: on the light email it disappears
 * into the background, on a darkened one it keeps the logo's letters apart from it. Logos without transparency
 * are already on their own background and are used as they are.
 *
 * The copy is drawn once (GD) and kept under storage/framework/cache/mail-logos, named after the file and its
 * modification time, so a new upload gets a new copy. Without GD, or when the image can't be read, the logo is used
 * as it is.
 */
class MailLogo
{
    /** Height of the copy: twice the 80px emails show at most, for sharp screens. */
    public const HEIGHT = 160;

    /** Width of the outline in pixels of the copy (so about half that on screen). */
    public const OUTLINE = 3.0;

    /** Path of the outlined copy of the logo at $path, or $path itself when it needs none or none can be made. */
    public static function for(string $path): string
    {
        if (! is_file($path) || ! function_exists('imagecreatefromstring')) {
            return $path;
        }

        $copy = self::copyPath($path);

        if (is_file($copy)) {
            return $copy;
        }

        try {
            $outlined = self::outline((string) file_get_contents($path));
        } catch (Throwable) {
            return $path;
        }

        if ($outlined === null) {
            return $path;
        }

        if (! is_dir(dirname($copy))) {
            @mkdir(dirname($copy), 0755, true);
        }

        return @file_put_contents($copy, $outlined) !== false ? $copy : $path;
    }

    public static function copyPath(string $path): string
    {
        return storage_path('framework/cache/mail-logos/'.sha1($path.'|'.filemtime($path).'|'.filesize($path)).'.png');
    }

    /** PNG bytes of the image with a white outline, or null when it has no transparent part to outline. */
    public static function outline(string $bytes): ?string
    {
        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            return null;
        }

        [$image, $margin] = self::toHeight($source);
        $width = imagesx($image);
        $height = imagesy($image);

        // Opacity of each pixel, 0..1
        $opacity = [];
        $transparent = false;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
                $opacity[$y][$x] = (127 - $alpha) / 127;
                $inside = $x >= $margin && $y >= $margin && $x < $width - $margin && $y < $height - $margin;
                $transparent = $transparent || ($inside && $alpha > 0);
            }
        }

        if (! $transparent) {
            return null; // on its own background already
        }

        // The pixels within OUTLINE of the drawing, softened at the edge
        $reach = (int) ceil(self::OUTLINE + 0.5);
        $kernel = [];

        for ($dy = -$reach; $dy <= $reach; $dy++) {
            for ($dx = -$reach; $dx <= $reach; $dx++) {
                $weight = min(1.0, max(0.0, self::OUTLINE + 0.5 - sqrt($dx * $dx + $dy * $dy)));

                if ($weight > 0) {
                    $kernel[] = [$dx, $dy, $weight];
                }
            }
        }

        $out = imagecreatetruecolor($width, $height);
        imagealphablending($out, false);
        imagesavealpha($out, true);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $halo = 0.0;

                foreach ($kernel as [$dx, $dy, $weight]) {
                    $near = ($opacity[$y + $dy][$x + $dx] ?? 0) * $weight;

                    if ($near > $halo) {
                        $halo = $near;

                        if ($halo >= 1.0) {
                            break;
                        }
                    }
                }

                // The logo's pixel over the white outline
                $rgba = imagecolorat($image, $x, $y);
                $a = $opacity[$y][$x];
                $total = $a + $halo * (1 - $a);

                if ($total <= 0) {
                    imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, 127));

                    continue;
                }

                $channel = fn (int $value): int => (int) round(($value * $a + 255 * $halo * (1 - $a)) / $total);
                imagesetpixel($out, $x, $y, imagecolorallocatealpha(
                    $out,
                    $channel(($rgba >> 16) & 0xFF),
                    $channel(($rgba >> 8) & 0xFF),
                    $channel($rgba & 0xFF),
                    (int) round(127 - $total * 127),
                ));
            }
        }

        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * A true-colour copy at HEIGHT pixels high (never enlarged), with a transparent margin for the outline.
     *
     * @return array{GdImage, int} the copy and its margin
     */
    private static function toHeight(GdImage $source): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $margin = (int) ceil(self::OUTLINE) + 1;
        $height = min(self::HEIGHT, $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $height / $sourceHeight));

        $image = imagecreatetruecolor($width + 2 * $margin, $height + 2 * $margin);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);
        imagecopyresampled($image, $source, $margin, $margin, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        return [$image, $margin];
    }
}
