<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The company's logo, dark mode logo and favicon (Settings → Company): files on the public disk, named in
 * `gaiacp.company.*`. The client area (not /admin, which stays GaiaCP), the payment notice and every client email show them; without a logo the
 * company name is shown. Served by `BrandingController` (route `branding`), so they work without `storage:link`.
 */
class Branding
{
    /** Asset (as in the URL) => its setting under `gaiacp.company`. */
    public const ASSETS = [
        'logo' => 'logo',
        'logo-dark' => 'logo_dark',
        'favicon' => 'favicon',
    ];

    /** Absolute path of the uploaded file, or null when there is none (or the file went missing). */
    public static function path(string $asset): ?string
    {
        $file = self::file($asset);

        if ($file === null || ! Storage::disk('public')->exists($file)) {
            return null;
        }

        return Storage::disk('public')->path($file);
    }

    /** Its URL, changing with each upload so browsers never keep an old one, or null without a file. */
    public static function url(string $asset): ?string
    {
        if (self::path($asset) === null) {
            return null;
        }

        return route('branding', ['asset' => $asset, 'v' => substr(md5((string) self::file($asset)), 0, 8)]);
    }

    /** The logo for emails: the upload, or its PNG copy when it is an SVG (null when none can be made). */
    public static function emailLogoPath(): ?string
    {
        $path = self::path('logo');

        return $path !== null && SvgLogo::isSvg($path) ? SvgLogo::pngFor($path) : $path;
    }

    /** Delete a replaced upload, with an SVG's PNG copy for emails. */
    public static function delete(string $file): void
    {
        $disk = Storage::disk('public');

        if (SvgLogo::isSvg($file)) {
            $disk->delete(SvgLogo::pngPath($file));
        }

        $disk->delete($file);
    }

    private static function file(string $asset): ?string
    {
        $key = self::ASSETS[$asset] ?? null;
        $file = $key ? config("gaiacp.company.{$key}") : null;

        return is_string($file) && $file !== '' ? $file : null;
    }
}
