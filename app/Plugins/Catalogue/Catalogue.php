<?php

namespace App\Plugins\Catalogue;

use App\Support\ModuleLogger;
use App\Support\Version;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SodiumException;
use Throwable;

/**
 * The plugin catalogue (`gaiacp.plugins.catalogue.url`, the official one on plugins.gaiacp.digital): catalogue.json,
 * the plugins and versions on offer, and catalogue.json.sig, an Ed25519 signature of its exact bytes by one of
 * `gaiacp.plugins.catalogue.keys`. Nothing in it is used until the signature checks out; each zip's SHA-256 and size
 * are in the signed file, so a zip is checked against them before PluginInstaller sees it. The last verified copy is
 * kept in storage, and a fetched catalogue older than it is refused, so an old one (with an old, fixed version)
 * can't be served again. The format is in docs/plugin-catalogue.md; the catalogue itself is built by the
 * gaiacp-plugins repository.
 */
class Catalogue
{
    public const FORMAT = 1;

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.plugins.catalogue.enabled') && filled(config('gaiacp.plugins.catalogue.url')) && self::keys() !== [];
    }

    /** @return list<string> the trusted public keys, raw bytes */
    public static function keys(): array
    {
        return collect(config('gaiacp.plugins.catalogue.keys', []))
            ->map(fn ($key) => base64_decode(trim((string) $key), true))
            ->filter(fn ($key) => is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
            ->values()->all();
    }

    /**
     * Reads the catalogue from its server, checks it and keeps it as the stored copy.
     *
     * @throws CatalogueException
     */
    public function fetch(): CatalogueData
    {
        if (! self::enabled()) {
            throw new CatalogueException(__('The plugin catalogue is turned off on this installation.'));
        }

        $json = $this->get('catalogue.json', 'catalogue');
        $signature = $this->get('catalogue.json.sig', 'signature');

        $catalogue = self::verify($json, $signature);

        $stored = $this->stored();
        if ($stored !== null && $catalogue->generatedAt->lt($stored->generatedAt)) {
            throw new CatalogueException(__('The catalogue served is older than the one already read (:date), so it was not used.', ['date' => $stored->generatedAt->toDateTimeString()]));
        }

        File::ensureDirectoryExists(self::storagePath());
        File::put(self::storagePath('catalogue.json'), $json);
        File::put(self::storagePath('catalogue.json.sig'), $signature);

        return $catalogue;
    }

    /** When the stored copy was read, or null when there is none. */
    public function readAt(): ?CarbonImmutable
    {
        $file = self::storagePath('catalogue.json');

        return is_file($file) ? CarbonImmutable::createFromTimestamp((int) filemtime($file)) : null;
    }

    /** The last catalogue read and verified, or null when there is none (or it no longer verifies). */
    public function stored(): ?CatalogueData
    {
        $json = self::storagePath('catalogue.json');
        $signature = self::storagePath('catalogue.json.sig');

        if (! is_file($json) || ! is_file($signature)) {
            return null;
        }

        try {
            return self::verify((string) file_get_contents($json), (string) file_get_contents($signature));
        } catch (CatalogueException) {
            return null; // a key was taken out of config since, or the file was changed
        }
    }

    /**
     * Downloads a version's zip to storage and checks its size and SHA-256 against the catalogue.
     *
     * @return string the zip's path; the caller deletes it
     *
     * @throws CatalogueException
     */
    public function download(ListingVersion $version): string
    {
        $maxBytes = (int) config('gaiacp.plugins.max_upload_kb', 10240) * 1024;
        if ($version->size > $maxBytes) {
            throw new CatalogueException(__('This plugin\'s zip is larger than :size MB.', ['size' => round($maxBytes / 1024 / 1024, 1)]));
        }

        File::ensureDirectoryExists(self::storagePath('downloads'));
        $path = self::storagePath('downloads/'.Str::random(16).'.zip');

        try {
            $response = Http::withHeaders(['User-Agent' => self::userAgent()])->connectTimeout(10)->timeout(120)->sink($path)->get($version->url);
            if (! $response->successful()) {
                throw new CatalogueException(__('The plugin catalogue answered :status for the zip.', ['status' => $response->status()]));
            }

            if (filesize($path) !== $version->size || ! hash_equals($version->sha256, hash_file('sha256', $path))) {
                throw new CatalogueException(__('The downloaded zip is not the one in the catalogue (its checksum differs), so it was not installed.'));
            }
        } catch (Throwable $e) {
            File::delete($path);

            throw $e instanceof CatalogueException ? $e : new CatalogueException(__('The zip could not be downloaded: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        return $path;
    }

    /**
     * Checks a catalogue's signature with the trusted keys, then reads it.
     *
     * @throws CatalogueException
     */
    public static function verify(string $json, string $signature): CatalogueData
    {
        $raw = base64_decode(trim($signature), true);
        $keys = self::keys();

        $signed = is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES && collect($keys)->contains(function (string $key) use ($raw, $json) {
            try {
                return sodium_crypto_sign_verify_detached($raw, $json, $key);
            } catch (SodiumException) {
                return false;
            }
        });

        if (! $signed) {
            throw new CatalogueException(__('The plugin catalogue\'s signature does not match a trusted key, so it was not used.'));
        }

        return self::parse($json);
    }

    /** @throws CatalogueException */
    private static function parse(string $json): CatalogueData
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['plugins'] ?? null)) {
            throw new CatalogueException(__('The plugin catalogue is in a format this GaiaCP does not read. Update GaiaCP first.'));
        }

        $base = rtrim((string) config('gaiacp.plugins.catalogue.url'), '/').'/';

        try {
            $plugins = collect($data['plugins'])->map(fn (array $plugin) => self::listing($plugin, $base))->values()->all();
            $generatedAt = CarbonImmutable::parse((string) $data['generated_at']);
        } catch (Throwable $e) {
            throw new CatalogueException(__('The plugin catalogue could not be read: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        return new CatalogueData($generatedAt, $plugins);
    }

    /** @param  array<string, mixed>  $plugin */
    private static function listing(array $plugin, string $base): Listing
    {
        $folder = (string) ($plugin['folder'] ?? '');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $folder)) {
            throw new CatalogueException("Bad folder name \"{$folder}\".");
        }

        $description = array_filter(array_map('strval', (array) ($plugin['description'] ?? [])), 'filled');
        if (! isset($description['en'])) {
            throw new CatalogueException("{$folder} has no English description.");
        }

        $versions = collect($plugin['versions'] ?? [])->map(fn (array $version) => self::version($version, $base, $folder))
            ->sort(fn (ListingVersion $a, ListingVersion $b) => version_compare($b->version, $a->version))->values()->all();

        if ($versions === []) {
            throw new CatalogueException("{$folder} has no versions.");
        }

        return new Listing(
            folder: $folder,
            class: ltrim((string) ($plugin['class'] ?? "Plugins\\{$folder}\\{$folder}Plugin"), '\\'),
            name: (string) ($plugin['name'] ?? $folder),
            description: $description,
            category: (string) ($plugin['category'] ?? 'Integrations'),
            author: (string) ($plugin['author']['name'] ?? ''),
            authorUrl: self::link($plugin['author']['url'] ?? null),
            homepage: self::link($plugin['homepage'] ?? null),
            licence: filled($plugin['licence'] ?? null) ? (string) $plugin['licence'] : null,
            official: (bool) ($plugin['official'] ?? false),
            versions: $versions,
        );
    }

    /** @param  array<string, mixed>  $version */
    private static function version(array $version, string $base, string $folder): ListingVersion
    {
        $number = (string) ($version['version'] ?? '');
        if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $number)) {
            throw new CatalogueException("{$folder} has a bad version \"{$number}\".");
        }

        $sha256 = strtolower((string) ($version['sha256'] ?? ''));
        if (! preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new CatalogueException("{$folder} {$number} has no SHA-256.");
        }

        $url = (string) ($version['url'] ?? '');
        $url = preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) ? $url : $base.ltrim($url, '/');
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, $base)) {
            throw new CatalogueException("{$folder} {$number} is not served over https.");
        }

        return new ListingVersion(
            version: $number,
            requires: filled($version['requires'] ?? null) ? (string) $version['requires'] : null,
            php: filled($version['php'] ?? null) ? (string) $version['php'] : null,
            url: $url,
            sha256: $sha256,
            size: (int) ($version['size'] ?? 0),
            releasedAt: filled($version['released_at'] ?? null) ? CarbonImmutable::parse((string) $version['released_at']) : null,
            changelog: (string) ($version['changelog'] ?? ''),
        );
    }

    private static function link(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    /** @throws CatalogueException */
    private function get(string $file, string $action): string
    {
        $url = rtrim((string) config('gaiacp.plugins.catalogue.url'), '/').'/'.$file;

        try {
            $response = Http::withHeaders(['User-Agent' => self::userAgent()])
                ->withMiddleware(ModuleLogger::middleware('plugin_catalogue', $action))
                ->connectTimeout(10)->timeout(30)->get($url);
        } catch (ConnectionException $e) {
            throw new CatalogueException(__('The plugin catalogue could not be reached: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if (! $response->successful()) {
            throw new CatalogueException(__('The plugin catalogue answered :status for :file.', ['status' => $response->status(), 'file' => $file]));
        }

        return $response->body();
    }

    /** Only GaiaCP's version goes with a request: nothing about the install or its clients. */
    private static function userAgent(): string
    {
        return 'GaiaCP/'.Version::current();
    }

    private static function storagePath(string $file = ''): string
    {
        return storage_path('app/private/plugins/catalogue'.($file !== '' ? '/'.$file : ''));
    }
}
