<?php

namespace App\Install;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Currencies;
use App\Support\Health\Check;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth;
use App\Support\Region;
use App\Support\Settings;
use App\Support\Version;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Intl\Currencies as IntlCurrencies;
use Throwable;

/**
 * Sets up a new GaiaCP: checks the server, writes .env (app key, database), runs the migrations, saves the
 * company and creates the first admin, then locks itself. Shared by the web installer (/install,
 * InstallController) and `gaiacp:install`.
 *
 * The web installer is open only while nothing is installed: no lock file and either no .env yet or one the
 * installer started itself (GAIACP_INSTALLER=open). An install made before the installer existed has its own
 * .env without that line, so it stays closed there.
 */
class Installer
{
    /** The .env line the web installer writes while it runs; finish() removes it. */
    public const MARKER = 'GAIACP_INSTALLER';

    public const DRIVERS = ['mysql' => 'MySQL / MariaDB', 'sqlite' => 'SQLite'];

    public function __construct(private SystemHealth $health) {}

    public function lockFile(): string
    {
        return config('gaiacp.install_lock') ?? storage_path('app/private/installed');
    }

    public function envFile(): string
    {
        return app()->environmentFilePath();
    }

    public function isLocked(): bool
    {
        return is_file($this->lockFile());
    }

    /** Whether /install may run: nothing installed yet, or the web installer's own install in progress. */
    public function webInstallerOpen(): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        return ! is_file($this->envFile()) || ($this->readEnv()[self::MARKER] ?? null) === 'open';
    }

    /**
     * What the server must have before installing. The database's own PHP driver is checked when it is chosen.
     *
     * @return list<Check>
     */
    public function requirements(): array
    {
        $root = base_path();
        $envWritable = is_file($this->envFile()) ? is_writable($this->envFile()) : is_writable($root);
        $missing = array_values(array_filter(array_keys(SystemHealth::REQUIRED_EXTENSIONS), fn (string $ext) => ! extension_loaded($ext)));

        // System health's own extension check also wants the driver of the database in use, not chosen yet here
        $checks = array_values(array_filter($this->health->requirements(), fn (Check $check) => $check->name !== 'Required extensions'));

        array_splice($checks, 1, 0, [
            $missing
                ? new Check('PHP', 'Required extensions', Status::Error, 'Missing: '.implode(', ', $missing),
                    collect($missing)->map(fn ($ext) => "{$ext}: ".SystemHealth::REQUIRED_EXTENSIONS[$ext])->implode('; '))
                : new Check('PHP', 'Required extensions', Status::Ok, implode(', ', array_keys(SystemHealth::REQUIRED_EXTENSIONS))),
            $this->availableDrivers()
                ? new Check('PHP', 'Database driver', Status::Ok, implode(', ', array_map(fn ($driver) => "pdo_{$driver}", array_keys($this->availableDrivers()))))
                : new Check('PHP', 'Database driver', Status::Error, 'None', 'Enable pdo_mysql (MySQL / MariaDB) or pdo_sqlite.'),
        ]);

        // Through the site itself: the settings about to be saved in .env must not be readable by anyone
        $checks[] = $this->health->privateFiles(request()->root());

        $checks[] = $envWritable
            ? new Check('Storage', '.env file', Status::Ok, 'Writable')
            : new Check('Storage', '.env file', Status::Error, 'Not writable', "Give the web user write access to {$root} (or create an empty, writable .env there), so the installer can save the settings.");

        return $checks;
    }

    public function meetsRequirements(): bool
    {
        return collect($this->requirements())->every(fn (Check $check) => $check->status !== Status::Error);
    }

    /** @return array<string, string> driver => label, for the drivers this PHP has */
    public function availableDrivers(): array
    {
        return array_filter(self::DRIVERS, fn (string $label, string $driver) => extension_loaded('pdo_'.$driver), ARRAY_FILTER_USE_BOTH);
    }

    /** Creates .env from .env.example (production defaults, a new app key) when there is none; returns the key. */
    public function prepareEnvironment(bool $marker = false): string
    {
        $env = is_file($this->envFile()) ? $this->readEnv() : [];
        $key = ($env['APP_KEY'] ?? '') !== '' ? $env['APP_KEY'] : 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));

        if (! is_file($this->envFile())) {
            $example = base_path('.env.example');
            if (@file_put_contents($this->envFile(), is_file($example) ? (string) file_get_contents($example) : '') === false) {
                throw new RuntimeException("Could not write {$this->envFile()}.");
            }
            $this->writeEnv(['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_LEVEL' => 'warning']);
        }

        $this->writeEnv(['APP_KEY' => $key] + ($marker ? [self::MARKER => 'open'] : []));
        config(['app.key' => $key]);

        return $key;
    }

    /**
     * Connects with these details and, when that works, saves them to .env and makes them this request's database.
     *
     * @param  array{driver: string, host?: ?string, port?: ?string, database: string, username?: ?string, password?: ?string}  $db
     *
     * @throws RuntimeException with the reason it can't connect
     */
    public function useDatabase(array $db): void
    {
        $driver = $db['driver'];
        $connection = match ($driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $this->sqlitePath($db['database']),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'mysql' => array_merge((array) config('database.connections.mysql'), [
                'host' => $db['host'] ?: '127.0.0.1',
                'port' => $db['port'] ?: '3306',
                'database' => $db['database'],
                'username' => $db['username'] ?? '',
                'password' => $db['password'] ?? '',
            ]),
            default => throw new RuntimeException("Unknown database type {$driver}."),
        };

        if ($driver === 'sqlite' && ! is_file($connection['database'])) {
            if (! is_dir(dirname($connection['database'])) || @touch($connection['database']) === false) {
                throw new RuntimeException("Could not create the SQLite file {$connection['database']}.");
            }
        }

        config(["database.connections.{$driver}" => $connection, 'database.default' => $driver]);
        DB::purge($driver);

        try {
            DB::connection($driver)->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getPrevious()?->getMessage() ?: $e->getMessage(), previous: $e);
        }

        $this->writeEnv(match ($driver) {
            'sqlite' => ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $connection['database']],
            default => [
                'DB_CONNECTION' => $driver,
                'DB_HOST' => $connection['host'],
                'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => $connection['database'],
                'DB_USERNAME' => $connection['username'],
                'DB_PASSWORD' => $connection['password'],
            ],
        });
    }

    private function sqlitePath(string $database): string
    {
        $database = trim($database) === '' ? 'database.sqlite' : trim($database);

        return str_starts_with($database, '/') ? $database : database_path($database);
    }

    /** Creates or updates the tables; returns what `migrate` printed. */
    public function migrate(): string
    {
        Artisan::call('migrate', ['--force' => true]);

        return Artisan::output();
    }

    public function migrated(): bool
    {
        try {
            return Schema::hasTable('users') && Schema::hasTable('settings');
        } catch (Throwable) {
            return false;
        }
    }

    public function hasAdmins(): bool
    {
        return $this->migrated() && User::admins()->exists();
    }

    /** Saves the company name and email (Settings → Company) and the panel's address. */
    public function saveCompany(string $name, ?string $email, ?string $url): void
    {
        Settings::save(['company.name' => $name, 'company.email' => $email ?: null]);
        $this->writeEnv(array_filter(['APP_NAME' => $name, 'APP_URL' => $url ? rtrim($url, '/') : null]));
    }

    /**
     * The region (Settings → General → Region) and the base currency. The base currency changes only while nothing
     * is priced in it yet: it goes to .env (GAIACP_CURRENCY) and replaces the one the migrations started with.
     */
    public function saveRegion(string $timezone, string $dateFormat, ?string $country, ?string $currency = null): void
    {
        Settings::save([
            'region.timezone' => Region::validTimezone($timezone) ? $timezone : 'UTC',
            'region.date_format' => in_array($dateFormat, Region::DATE_FORMATS, true) ? $dateFormat : 'd/m/Y',
            'region.country' => $country ? strtoupper($country) : null,
        ]);

        $currency = strtoupper((string) $currency);
        if ($currency === '' || $currency === Currencies::base() || ! IntlCurrencies::exists($currency)) {
            return;
        }
        if (DB::table('products')->exists() || DB::table('services')->exists() || DB::table('clients')->exists()) {
            return; // prices and clients are in the base currency already
        }

        $this->writeEnv(['GAIACP_CURRENCY' => $currency]);
        $old = Currencies::base();
        config(['gaiacp.currency' => $currency]);
        DB::table('currencies')->where('code', $currency)->delete();
        DB::table('currencies')->where('code', $old)->update([
            'code' => $currency,
            'prefix' => ['EUR' => '€', 'USD' => '$', 'GBP' => '£'][$currency] ?? null,
            'suffix' => $currency,
            'rate' => 1,
        ]);
        Currencies::flush();
    }

    public function createAdmin(string $name, string $email, string $password, ?string $locale = null): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'locale' => $locale,
            'password' => $password,
            'role' => UserRole::Admin,
            'super_admin' => true,
            'active' => true,
        ]);

        ActivityLog::record('install.completed', 'GaiaCP '.Version::current()." installed; first admin {$email}");

        return $user;
    }

    /** Locks the installer: the lock file, and the web installer's .env line goes. */
    public function finish(): void
    {
        $this->lock();

        if (is_file($this->envFile())) {
            $this->writeEnv([self::MARKER => null]);
        }
    }

    /** Writes the lock file (also for an install made before the installer existed, the first time it is seen). */
    public function lock(): void
    {
        $lock = $this->lockFile();
        if (! is_dir(dirname($lock))) {
            mkdir(dirname($lock), 0755, true);
        }
        file_put_contents($lock, json_encode(['version' => Version::current(), 'at' => now()->toIso8601String()]).PHP_EOL);
    }

    /** The cron line that runs the daily automation (every minute, as `schedule:run` expects). */
    public function cronLine(): string
    {
        $php = PHP_BINDIR.'/php';

        return '* * * * * cd '.base_path()." && {$php} artisan schedule:run >> /dev/null 2>&1";
    }

    /** @return array<string, string> KEY => value of the uncommented lines of .env */
    public function readEnv(): array
    {
        $values = [];
        foreach (file(is_file($this->envFile()) ? $this->envFile() : 'php://memory', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                $values[$m[1]] = $this->unquote(trim($m[2]));
            }
        }

        return $values;
    }

    /**
     * Sets keys in .env, in place where the line (or its commented-out example) is, else at the end.
     *
     * @param  array<string, ?string>  $values  null removes the key
     */
    public function writeEnv(array $values): void
    {
        $lines = is_file($this->envFile()) ? (file($this->envFile(), FILE_IGNORE_NEW_LINES) ?: []) : [];

        foreach ($values as $key => $value) {
            $line = $value === null ? null : $key.'='.$this->quote($value);
            $at = $this->findLine($lines, $key, commented: false) ?? ($value === null ? null : $this->findLine($lines, $key, commented: true));

            if ($at === null) {
                if ($line !== null) {
                    $lines[] = $line;
                }
            } elseif ($line === null) {
                unset($lines[$at]);
            } else {
                $lines[$at] = $line;
            }
        }

        if (@file_put_contents($this->envFile(), implode("\n", $lines)."\n") === false) {
            throw new RuntimeException("Could not write {$this->envFile()}.");
        }
    }

    /** @param  list<string>  $lines */
    private function findLine(array $lines, string $key, bool $commented): ?int
    {
        $pattern = $commented ? '/^\s*#\s*'.preg_quote($key, '/').'\s*=/' : '/^\s*'.preg_quote($key, '/').'\s*=/';

        foreach ($lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                return $i;
            }
        }

        return null;
    }

    /** Plain values stay bare; others are single-quoted (taken literally, so a password's $ is kept), or double-quoted with escapes when they hold a '. */
    private function quote(string $value): string
    {
        if (preg_match('#^[A-Za-z0-9_.:/@+,=-]*$#', $value)) {
            return $value;
        }
        if (! str_contains($value, "'") && ! str_contains($value, "\n")) {
            return "'{$value}'";
        }

        return '"'.str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\n'], $value).'"';
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace(['\\n', '\\$', '\\"', '\\\\'], ["\n", '$', '"', '\\'], substr($value, 1, -1));
        }

        return preg_replace('/\s+#.*$/', '', $value);
    }
}
