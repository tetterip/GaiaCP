<?php

namespace App\Support;

use Composer\InstalledVersions;
use NumberFormatter;
use Throwable;

/**
 * The admin's About window (resources/views/filament/about.blade.php): opened from the version in the footer or the
 * user menu. It also holds a small surprise for whoever knows the old cheat code.
 */
final class About
{
    /** The keys that start the surprise, as `KeyboardEvent.key` names them (letters in lower case). */
    public const SECRET = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];

    /** Taps on the hexagon in the About window that start it too, for phones without arrow keys. */
    public const TAPS = 7;

    /** @return array<string, string> what GaiaCP runs on, label => version */
    public static function stack(): array
    {
        return [
            'PHP' => PHP_VERSION,
            'Laravel' => app()->version(),
            'Filament' => self::package('filament/filament'),
        ];
    }

    /** The base currency's symbol (€ for EUR), for the coins of the surprise; the code when there is none. */
    public static function coin(): string
    {
        $code = Currencies::base();

        try {
            $symbol = class_exists(NumberFormatter::class)
                ? (new NumberFormatter('en@currency='.$code, NumberFormatter::CURRENCY))->getSymbol(NumberFormatter::CURRENCY_SYMBOL)
                : '';
        } catch (Throwable) {
            $symbol = '';
        }

        return $symbol !== '' && mb_strlen($symbol) <= 3 ? $symbol : $code;
    }

    private static function package(string $name): string
    {
        try {
            return ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') ?: '?';
        } catch (Throwable) {
            return '?';
        }
    }
}
