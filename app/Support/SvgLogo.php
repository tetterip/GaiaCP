<?php

namespace App\Support;

use DOMDocument;
use enshrined\svgSanitize\Sanitizer;
use Imagick;
use ImagickPixel;
use Throwable;

/**
 * An SVG logo or tab icon (Settings → Company), made safe and given a size before it is stored:
 *  - Security: scripts, event handlers, <foreignObject>, external references and the like are removed
 *    (enshrined/svg-sanitize allowlist), and BrandingController serves it with a sandboxing CSP on top.
 *  - Dimensions: it must say its proportions (a viewBox, or a width and height to make one from); the root
 *    width and height are then set from the viewBox, so it scales like a picture of that shape wherever a
 *    height is set (the panel, the payment notice) instead of filling the page or collapsing to nothing.
 * Emails can't show SVG (Gmail, Outlook), so they get a PNG copy: made here where the server has Imagick (pngFor()),
 * else drawn by the admin's browser on the Company page and stored by storePng().
 */
class SvgLogo
{
    /** Height of the PNG copy for emails: twice the 80px the email shows, for sharp screens. */
    public const EMAIL_HEIGHT = 160;

    /** Widest PNG copy accepted from the browser (a very wide logo is drawn lower instead). */
    public const EMAIL_MAX_WIDTH = 1600;

    public static function isSvg(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    /** The cleaned SVG, or null when it isn't an SVG or its proportions can't be known. */
    public static function clean(string $svg): ?string
    {
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(true);

        try {
            $clean = $sanitizer->sanitize($svg);
        } catch (Throwable) {
            return null; // not an SVG document
        }

        if (! is_string($clean) || trim($clean) === '') {
            return null;
        }

        $doc = new DOMDocument;

        if (! @$doc->loadXML($clean, LIBXML_NONET) || $doc->documentElement?->localName !== 'svg') {
            return null;
        }

        $root = $doc->documentElement;
        $box = self::viewBox($root->getAttribute('viewBox'));

        if ($box === null) {
            $width = self::length($root->getAttribute('width'));
            $height = self::length($root->getAttribute('height'));

            if ($width === null || $height === null) {
                return null;
            }

            $box = [0.0, 0.0, $width, $height];
            $root->setAttribute('viewBox', '0 0 '.self::number($width).' '.self::number($height));
        }

        $root->setAttribute('width', self::number($box[2]));
        $root->setAttribute('height', self::number($box[3]));
        $root->removeAttribute('style'); // e.g. width: 100%, which would undo the size above

        return $doc->saveXML($root);
    }

    /** Whether emails can get a PNG copy of an SVG logo on this server. */
    public static function canConvert(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class) && Imagick::queryFormats('SVG') !== [];
    }

    /**
     * Path of the PNG copy of an SVG (made next to it the first time), or null when this server can't make one.
     * Removed with the SVG when the logo is replaced (Branding::delete()).
     */
    public static function pngFor(string $svgPath): ?string
    {
        $png = self::pngPath($svgPath);

        if (is_file($png)) {
            return $png;
        }

        if (! self::canConvert()) {
            return null;
        }

        $svg = (string) file_get_contents($svgPath);
        // Drawn at the size it is shown, not drawn small and blown up (clean() set the root height)
        $height = preg_match('/<svg\b[^>]*\sheight="([\d.]+)"/', $svg, $match) ? (float) $match[1] : 0;

        try {
            $image = new Imagick;
            $image->setBackgroundColor(new ImagickPixel('transparent'));

            if ($height > 0) {
                $density = 72 * self::EMAIL_HEIGHT / $height;
                $image->setResolution($density, $density);
            }

            $image->readImageBlob($svg);
            $image->setImageFormat('png32');
            $image->thumbnailImage(0, self::EMAIL_HEIGHT);
            file_put_contents($png, $image->getImageBlob());
            $image->clear();
        } catch (Throwable) {
            return null;
        }

        return $png;
    }

    /**
     * Store the PNG copy the admin's browser drew of an SVG logo (a `data:image/png;base64,` URL), for servers
     * without Imagick. Only a real PNG no bigger than the email copy is kept; false otherwise.
     */
    public static function storePng(string $svgPath, string $dataUrl): bool
    {
        $prefix = 'data:image/png;base64,';

        if (! self::isSvg($svgPath) || ! str_starts_with($dataUrl, $prefix) || strlen($dataUrl) > 3_000_000) {
            return false;
        }

        $png = base64_decode(substr($dataUrl, strlen($prefix)), true);
        $size = $png === false ? false : @getimagesizefromstring($png);

        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] > self::EMAIL_MAX_WIDTH || $size[1] > self::EMAIL_HEIGHT) {
            return false;
        }

        return file_put_contents(self::pngPath($svgPath), $png) !== false;
    }

    public static function pngPath(string $svgPath): string
    {
        return preg_replace('/\.svg$/i', '', $svgPath).'.email.png';
    }

    /** @return array{float, float, float, float}|null */
    private static function viewBox(string $value): ?array
    {
        $parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        if (count($parts) !== 4 || array_filter($parts, fn ($part) => ! is_numeric($part)) !== []) {
            return null;
        }

        $box = array_map('floatval', $parts);

        return $box[2] > 0 && $box[3] > 0 ? $box : null;
    }

    /** A width or height in user units or px; percentages, em and the like say nothing about the shape. */
    private static function length(string $value): ?float
    {
        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/i', $value, $match)) {
            return null;
        }

        return (float) $match[1] > 0 ? (float) $match[1] : null;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
