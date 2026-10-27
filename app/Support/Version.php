<?php

namespace App\Support;

/**
 * GaiaCP's version: the `VERSION` file at the app root, which a release tag (`v` + the same number) must match.
 * A release zip carries it; a Git checkout shows the version it is working towards.
 */
class Version
{
    /**
     * How far along GaiaCP is (alpha, beta, or null once it is stable), shown next to the version in the admin footer,
     * the installer and `php artisan about`. Kept apart from `VERSION` so plugins' `requires` and updates still compare
     * plain numbers.
     */
    public const STAGE = 'alpha';

    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= self::read();
    }

    /** The stage as people read it ("Alpha"), or null once GaiaCP is stable. */
    public static function stageLabel(): ?string
    {
        return match (self::STAGE) {
            'alpha' => __('Alpha'),
            'beta' => __('Beta'),
            default => null,
        };
    }

    /** For tests. */
    public static function forget(): void
    {
        self::$current = null;
    }

    private static function read(): string
    {
        $file = base_path('VERSION');
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : 'unknown';
    }
}
