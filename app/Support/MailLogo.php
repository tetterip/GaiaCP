<?php

namespace App\Support;

use GdImage;
use Throwable;

/**
 * A logo that stays readable when a mail client turns the email dark. Gmail's apps and Outlook invert the colours of
 * an email in dark mode but never its images, so a dark logo on a transparent background ends up dark on dark (and a
 * background given with CSS would be darkened too). The copy made here puts the logo on a white rounded background
 * inside the image itself: a quiet white frame on the light email, a white badge that keeps the logo readable on a
 * darkened one. Logos without transparency are already on their own background and are used as they are.
 *
 * The copy is drawn once (GD) and kept under storage/framework/cache/mail-logos, named after the file and its
 * modification time, so a new upload gets a new copy. Without GD, or when the image can't be read, the logo is used
 * as it is. The GaiaCP logo for emails (public/images/gaiacp/logo-email.png) is drawn the same way from logo.svg.
 */
class MailLogo
{
    /** Height of the logo in the copy: twice the 80px emails show at most, for sharp screens. */
    public const HEIGHT = 160;

    /** Space around the logo, above and below / left and right, and the corner radius, as parts of its height. */
    public const PAD_Y = 0.09;

    public const PAD_X = 0.15;

    public const RADIUS = 0.18;

    /** Path of the copy on a white background of the logo at $path, or $path itself when it needs none or none can be made. */
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
            $png = self::onBackground((string) file_get_contents($path));
        } catch (Throwable) {
            return $path;
        }

        if ($png === null) {
            return $path;
        }

        if (! is_dir(dirname($copy))) {
            @mkdir(dirname($copy), 0755, true);
        }

        return @file_put_contents($copy, $png) !== false ? $copy : $path;
    }

    public static function copyPath(string $path): string
    {
        return storage_path('framework/cache/mail-logos/'.sha1($path.'|'.filemtime($path).'|'.filesize($path)).'.png');
    }

    /** PNG bytes of the logo on a white rounded background, or null when it has no transparent part (or isn't an image). */
    public static function onBackground(string $bytes): ?string
    {
        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage || ! self::hasTransparency($source)) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $height = min(self::HEIGHT, $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $height / $sourceHeight));
        $padX = (int) round($height * self::PAD_X);
        $padY = (int) round($height * self::PAD_Y);
        $outWidth = $width + 2 * $padX;
        $outHeight = $height + 2 * $padY;

        $out = imagecreatetruecolor($outWidth, $outHeight);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        self::roundedBackground($out, $outHeight * self::RADIUS);

        imagealphablending($out, true);
        imagecopyresampled($out, $source, $padX, $padY, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }

    private static function hasTransparency(GdImage $image): bool
    {
        if (! imageistruecolor($image)) {
            return imagecolortransparent($image) >= 0;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 200)); // every pixel of a logo-sized image, a sample of a huge one

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Fill the image with white, its corners rounded to $radius with smooth (antialiased) edges. */
    private static function roundedBackground(GdImage $image, float $radius): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $radius = min($radius, $width / 2, $height / 2);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 0));
        $corner = (int) ceil($radius);

        for ($y = 0; $y < $corner; $y++) {
            for ($x = 0; $x < $corner; $x++) {
                // Distance of the pixel's centre from the corner circle's centre
                $distance = sqrt(($radius - $x - 0.5) ** 2 + ($radius - $y - 0.5) ** 2);
                $cover = max(0.0, min(1.0, $radius - $distance + 0.5));

                if ($cover >= 1.0) {
                    continue;
                }

                $color = imagecolorallocatealpha($image, 255, 255, 255, (int) round(127 * (1 - $cover)));

                foreach ([[$x, $y], [$width - 1 - $x, $y], [$x, $height - 1 - $y], [$width - 1 - $x, $height - 1 - $y]] as [$px, $py]) {
                    imagesetpixel($image, $px, $py, $color);
                }
            }
        }
    }
}
