<?php

namespace App\Support\Health;

use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\Registrar;
use App\Models\Server;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\RegistrarFactory;
use App\Support\Branding;
use App\Support\SvgLogo;
use App\Support\Version;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Throwable;

/**
 * The checks behind Admin → System health and `gaiacp:health`, like WHMCS's System Health Status:
 * PHP and its extensions, the app's configuration, database, storage, cron and the daily jobs, mail,
 * and the panels and registrars. checks() never calls a server (only this site itself, to see that .env is not
 * served); serverChecks() does, on request.
 */
class SystemHealth
{
    public const MIN_PHP = '8.3.0';

    /** Extension => what needs it. Missing ones are problems. */
    public const REQUIRED_EXTENSIONS = [
        'ctype' => 'Laravel',
        'curl' => 'cPanel, Plesk and registrar API calls',
        'dom' => 'Plesk XML API',
        'fileinfo' => 'Laravel',
        'filter' => 'Laravel',
        'intl' => 'Filament (numbers, dates)',
        'mbstring' => 'Laravel, Greek text',
        'openssl' => 'encrypted credentials, HTTPS',
        'pdo' => 'the database',
        'session' => 'the admin login',
        'simplexml' => 'Plesk XML API',
        'tokenizer' => 'Laravel',
        'xml' => 'Plesk XML API',
    ];

    /** Extension => what it is for. Missing ones are warnings. */
    public const RECOMMENDED_EXTENSIONS = [
        'zip' => 'zipped backups',
        'gd' => 'images (logos)',
        'imagick' => 'SVG logos in emails (without it the browser makes the PNG copy on Settings → Company)',
        'opcache' => 'speed',
    ];

    /** Daily jobs older than this have missed a day. */
    private const DAILY_GRACE_HOURS = 26;

    public function __construct(private SchedulerMonitor $scheduler) {}

    /** @return Collection<int, Check> */
    public function checks(): Collection
    {
        return collect([
            ...$this->php(),
            ...$this->application(),
            ...$this->database(),
            ...$this->storage(),
            ...$this->automation(),
            ...$this->mail(),
            ...$this->integrations(),
        ]);
    }

    /**
     * Calls every active server's panel (version + packages), like its Test connection action.
     *
     * @return Collection<int, Check>
     */
    public function serverChecks(): Collection
    {
        return Server::query()->where('active', true)->orderBy('name')->get()->map(function (Server $server) {
            try {
                $provisioner = app(ProvisionerFactory::class)->forServer($server);
                $version = $provisioner->testConnection();
                $packages = count($provisioner->packages());

                return new Check('Connections', $server->name, Status::Ok, "{$version}, {$packages} package(s)");
            } catch (Throwable $e) { // ProvisioningException, or a bad server row
                return new Check('Connections', $server->name, Status::Error, 'Connection failed', $e->getMessage());
            }
        });
    }

    /** @return list<Check> */
    private function php(): array
    {
        $checks = [version_compare(PHP_VERSION, self::MIN_PHP, '>=')
            ? new Check('PHP', 'PHP version', Status::Ok, PHP_VERSION)
            : new Check('PHP', 'PHP version', Status::Error, PHP_VERSION, 'GaiaCP needs PHP '.self::MIN_PHP.' or newer: pick a newer PHP for this site.')];

        $missing = array_filter(array_keys(self::REQUIRED_EXTENSIONS), fn (string $ext) => ! extension_loaded($ext));
        $driver = 'pdo_'.DB::connection()->getDriverName();
        if (! extension_loaded($driver)) {
            $missing[] = $driver;
        }

        $checks[] = $missing
            ? new Check('PHP', 'Required extensions', Status::Error, 'Missing: '.implode(', ', $missing),
                collect($missing)->map(fn ($ext) => "{$ext}: ".(self::REQUIRED_EXTENSIONS[$ext] ?? 'the database'))->implode('; '))
            : new Check('PHP', 'Required extensions', Status::Ok, implode(', ', [...array_keys(self::REQUIRED_EXTENSIONS), $driver]));

        $optional = array_filter(array_keys(self::RECOMMENDED_EXTENSIONS), fn (string $ext) => ! extension_loaded($ext === 'opcache' ? 'Zend OPcache' : $ext));
        $checks[] = $optional
            ? new Check('PHP', 'Recommended extensions', Status::Warning, 'Missing: '.implode(', ', $optional),
                collect($optional)->map(fn ($ext) => "{$ext}: ".self::RECOMMENDED_EXTENSIONS[$ext])->implode('; '))
            : new Check('PHP', 'Recommended extensions', Status::Ok, implode(', ', array_keys(self::RECOMMENDED_EXTENSIONS)));

        $memory = (string) ini_get('memory_limit');
        $checks[] = $this->bytes($memory) !== -1 && $this->bytes($memory) < 128 * 1024 ** 2
            ? new Check('PHP', 'Memory limit', Status::Warning, $memory, 'Set memory_limit to at least 128M.')
            : new Check('PHP', 'Memory limit', Status::Info, $memory === '-1' ? 'Unlimited' : $memory);

        return $checks;
    }

    /** @return list<Check> */
    private function application(): array
    {
        $production = app()->environment('production');
        $url = (string) config('app.url');

        return [
            new Check('Application', 'GaiaCP version', Status::Info, Version::current()),
            new Check('Application', 'Environment', $production ? Status::Ok : Status::Info, (string) config('app.env')),
            config('app.debug')
                ? new Check('Application', 'Debug mode', $production ? Status::Error : Status::Warning, 'On', 'Set APP_DEBUG=false: error pages show code and settings.')
                : new Check('Application', 'Debug mode', Status::Ok, 'Off'),
            filled(config('app.key'))
                ? new Check('Application', 'Encryption key', Status::Ok, 'Set')
                : new Check('Application', 'Encryption key', Status::Error, 'Missing', 'Run php artisan key:generate once; never change it later, or stored passwords cannot be read.'),
            str_starts_with($url, 'https://')
                ? new Check('Application', 'App URL', Status::Ok, $url)
                : new Check('Application', 'App URL', $production ? Status::Warning : Status::Info, $url ?: '(empty)', 'Set APP_URL to the https:// address of the admin.'),
            new Check('Application', 'Timezone', Status::Ok, (string) config('app.timezone')), // Settings → General → Region
            new Check('Application', 'Cached config / routes', Status::Info,
                (app()->configurationIsCached() ? 'config cached' : 'config not cached').', '.(app()->routesAreCached() ? 'routes cached' : 'routes not cached')),
            ...$this->proxy(),
            $this->privateFiles(app()->runningInConsole() ? $url : request()->root()),
            ...$this->security($production),
        ];
    }

    /**
     * The site serves only public/: `.env` and `composer.json` can't be fetched through it, as they could when the
     * document root is GaiaCP's own folder without its .htaccess (nginx, or Apache without mod_rewrite). Asked of
     * the site itself, so it says nothing when the site can't reach its own address.
     */
    public function privateFiles(string $root): Check
    {
        $root = rtrim($root, '/');
        $client = fn (Pool $pool, string $name) => $pool->as($name)->timeout(4)->withoutRedirecting()->withOptions(['verify' => false]);

        try {
            $responses = Http::pool(fn (Pool $pool) => [
                $client($pool, 'env')->get("{$root}/.env"),
                $client($pool, 'composer')->get("{$root}/composer.json"),
            ]);
        } catch (Throwable) {
            $responses = [];
        }

        $answered = collect($responses)->filter(fn ($response) => $response instanceof Response);
        if ($answered->isEmpty()) {
            return new Check('Application', 'Private files', Status::Info, 'Not checked', "The site did not answer at {$root}, so it could not check that .env is not served.");
        }

        $readable = $answered->filter(fn (Response $response, string $name) => $response->successful()
            && str_contains($response->body(), $name === 'env' ? 'APP_KEY' : 'laravel/framework'))
            ->keys()->map(fn (string $name) => $name === 'env' ? '.env' : 'composer.json');

        return $readable->isEmpty()
            ? new Check('Application', 'Private files', Status::Ok, 'Not served')
            : new Check('Application', 'Private files', Status::Error, 'Served to anyone: '.$readable->join(', '),
                'The site shows GaiaCP\'s own folder. Point the document root at public/, or on Apache keep the .htaccess that comes with GaiaCP (mod_rewrite on, AllowOverride All). On nginx, see docs/installation.md → Document root.');
    }

    /**
     * Settings that are allowed but worth knowing about: backups (a full copy of the database) without a zip password,
     * and plugin uploads (a super admin can run any code with them).
     *
     * @return list<Check>
     */
    private function security(bool $production): array
    {
        $checks = [];

        if (config('gaiacp.backups.enabled')) {
            $checks[] = filled(config('gaiacp.backups.zip_password'))
                ? new Check('Application', 'Backup encryption', Status::Ok, 'Zip password set')
                : new Check('Application', 'Backup encryption', Status::Warning, 'Not encrypted',
                    'Backups hold the whole database (clients, notices, encrypted credentials): set a zip password under Settings → Backups, above all when they are sent by FTP or email.');
        }

        $checks[] = config('gaiacp.plugins.uploads')
            ? new Check('Application', 'Plugin uploads', $production ? Status::Info : Status::Ok, 'On',
                'Super admins can upload plugins, which run as GaiaCP itself. Set GAIACP_PLUGIN_UPLOADS=false when you do not need it; plugins copied into plugins/ still load.')
            : new Check('Application', 'Plugin uploads', Status::Ok, 'Off');

        return $checks;
    }

    /**
     * Cloudflare in front of the site: only the page can tell (its request carries CF-Connecting-IP), so
     * `gaiacp:health` shows the setting alone.
     *
     * @return list<Check>
     */
    private function proxy(): array
    {
        $trusted = (bool) config('gaiacp.proxies.cloudflare');
        $request = request();

        if (! $request->hasHeader('CF-Connecting-IP')) {
            return [new Check('Application', 'Cloudflare', Status::Info,
                $trusted ? 'Not in use; its addresses are trusted if you add it' : 'Not in use; its addresses are not trusted')];
        }

        // Right when the address GaiaCP uses is the one Cloudflare saw (trusted here, or set by the web server)
        $checks = [$request->ip() === $request->header('CF-Connecting-IP')
            ? new Check('Application', 'Cloudflare', Status::Ok, 'In use; visitor address '.$request->ip())
            : new Check('Application', 'Cloudflare', Status::Warning, 'In use, but visitor addresses are Cloudflare\'s',
                'Remove GAIACP_TRUST_CLOUDFLARE=false from .env so logins and the panel one-click login see the visitor, '
                .'or add the address the server sees ('.$request->server('REMOTE_ADDR').') to GAIACP_TRUSTED_PROXIES.')];

        // Flexible SSL: the visitor is on https but Cloudflare reaches the server over plain http
        if (str_contains((string) $request->header('CF-Visitor'), 'https') && ! in_array(strtolower((string) $request->server('HTTPS')), ['on', '1'], true)) {
            $checks[] = new Check('Application', 'Cloudflare SSL mode', Status::Warning, 'Flexible',
                'Set SSL/TLS to Full (strict) in Cloudflare: Flexible sends passwords from Cloudflare to this server unencrypted.');
        }

        return $checks;
    }

    /** @return list<Check> */
    private function database(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();
            $checks = [new Check('Database', 'Connection', Status::Ok, $connection->getDriverName().' '.$connection->getServerVersion())];
        } catch (Throwable $e) {
            return [new Check('Database', 'Connection', Status::Error, 'Cannot connect', $e->getMessage())];
        }

        /** @var Migrator $migrator */
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            $checks[] = new Check('Database', 'Migrations', Status::Error, 'Not installed', 'Run php artisan migrate.');

            return $checks;
        }

        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
        $checks[] = $pending
            ? new Check('Database', 'Migrations', Status::Error, count($pending).' pending', 'Run php artisan migrate: '.implode(', ', $pending))
            : new Check('Database', 'Migrations', Status::Ok, 'Up to date ('.count($files).')');

        return $checks;
    }

    /**
     * What GaiaCP needs to run at all: PHP, its extensions and the folders it writes to. The installer
     * (App\Install\Installer) checks these before it asks for anything.
     *
     * @return list<Check>
     */
    public function requirements(): array
    {
        return [...$this->php(), $this->writableFolders()];
    }

    private function writableFolders(): Check
    {
        $paths = [
            'storage/app' => storage_path('app'),
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];
        $notWritable = array_keys(array_filter($paths, fn (string $path) => ! is_writable($path)));

        return $notWritable
            ? new Check('Storage', 'Writable folders', Status::Error, 'Not writable: '.implode(', ', $notWritable), 'Give the web user write access to these folders.')
            : new Check('Storage', 'Writable folders', Status::Ok, implode(', ', array_keys($paths)));
    }

    /** @return list<Check> */
    private function storage(): array
    {
        $checks = [$this->writableFolders()];

        $free = function_exists('disk_free_space') ? @disk_free_space(storage_path()) : false;
        if ($free !== false) {
            $low = $free < 1024 * 1024 * 1024;
            $checks[] = new Check('Storage', 'Free disk space', $low ? Status::Warning : Status::Ok, Number::fileSize($free, 1), $low ? 'Less than 1 GB left.' : null);
        }

        return $checks;
    }

    /** @return list<Check> */
    private function automation(): array
    {
        $beat = $this->scheduler->lastHeartbeat();
        $cron = 'Cron, every minute: '.($beat['binary'] ?? 'php').' '.base_path('artisan').' schedule:run >> /dev/null 2>&1';

        if (! $beat) {
            $checks = [new Check('Automation', 'Cron (scheduler)', Status::Error, 'Never seen', $cron)];
        } else {
            $minutes = (int) $beat['at']->diffInMinutes(now(), true);
            $checks = [match (true) {
                $minutes <= 5 => new Check('Automation', 'Cron (scheduler)', Status::Ok, 'Last run '.$this->ago($beat['at'])),
                $minutes <= 60 => new Check('Automation', 'Cron (scheduler)', Status::Warning, 'Last run '.$this->ago($beat['at']), 'Cron should run every minute. '.$cron),
                default => new Check('Automation', 'Cron (scheduler)', Status::Error, 'Last run '.$this->ago($beat['at']), 'Cron has stopped. '.$cron),
            }];

            // Cron can use a different PHP from the website (e.g. Plesk's /opt/plesk/php/x.y/bin/php).
            $checks[] = version_compare($beat['php'], self::MIN_PHP, '>=')
                ? new Check('Automation', 'Cron PHP version', Status::Ok, "{$beat['php']} ({$beat['binary']})")
                : new Check('Automation', 'Cron PHP version', Status::Error, "{$beat['php']} ({$beat['binary']})", 'Point the cron job at PHP '.self::MIN_PHP.' or newer.');
        }

        foreach (SchedulerMonitor::DAILY_COMMANDS as $command) {
            $run = $this->scheduler->lastRun($command);
            $checks[] = match (true) {
                $run === null => new Check('Automation', $command, Status::Warning, 'No run recorded yet', 'Runs daily once cron is set up.'),
                $run['exit_code'] !== 0 => new Check('Automation', $command, Status::Error, 'Failed '.$this->ago($run['at'])." (exit code {$run['exit_code']})", 'See storage/logs/laravel.log.'),
                $run['at']->diffInHours(now(), true) > self::DAILY_GRACE_HOURS => new Check('Automation', $command, Status::Warning, 'Last run '.$this->ago($run['at']), 'It should run every day.'),
                default => new Check('Automation', $command, Status::Ok, 'Last run '.$this->ago($run['at'])),
            };
        }

        $queue = (string) config('queue.default');
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $checks[] = $failedJobs
            ? new Check('Automation', 'Queue', Status::Warning, "{$queue}, {$failedJobs} failed job(s)", 'php artisan queue:failed lists them.')
            : new Check('Automation', 'Queue', Status::Ok, "{$queue}, no failed jobs");

        return $checks;
    }

    /** @return list<Check> */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        $checks = [match (true) {
            in_array($transport, ['log', 'array'], true) => new Check('Mail', 'Mailer', Status::Warning, $mailer, 'Emails are written to the log, not sent. Set up SMTP to send them.'),
            $transport === 'smtp' => new Check('Mail', 'Mailer', Status::Ok, 'SMTP '.config("mail.mailers.{$mailer}.host").':'.config("mail.mailers.{$mailer}.port")),
            default => new Check('Mail', 'Mailer', Status::Ok, $mailer),
        }];

        $from = config('mail.from.address');
        $checks[] = filled($from)
            ? new Check('Mail', 'From address', Status::Ok, (string) $from)
            : new Check('Mail', 'From address', Status::Error, 'Not set', 'Fill in the company email under Settings → Company.');

        $logo = Branding::path('logo');
        $checks[] = match (true) {
            $logo === null => new Check('Mail', 'Logo in emails', Status::Info, 'No logo', 'Emails show the company name. Upload a logo under Settings → Company.'),
            ! SvgLogo::isSvg($logo) => new Check('Mail', 'Logo in emails', Status::Ok, 'Logo shown'),
            Branding::emailLogoPath() !== null => new Check('Mail', 'Logo in emails', Status::Ok, 'PNG copy of the SVG logo'),
            default => new Check('Mail', 'Logo in emails', Status::Warning, 'Company name instead of the SVG logo',
                'Email apps can\'t show SVG and '.match (true) {
                    SvgLogo::canConvert() => 'imagick could not convert this one',
                    extension_loaded('imagick') => 'this server\'s imagick has no SVG support',
                    default => 'this server has no imagick',
                }.' to make a PNG copy. Open Settings → Company once: your browser makes it.'),
        };

        return $checks;
    }

    /** @return list<Check> */
    private function integrations(): array
    {
        $week = now()->subDays(7);
        $checks = [];

        $servers = Server::query()->where('active', true)->get();
        $checks[] = new Check('Connections', 'Servers', $servers->isEmpty() ? Status::Info : Status::Ok,
            $servers->isEmpty() ? 'None active' : $servers->count().' active ('.$servers->pluck('name')->implode(', ').')',
            $servers->isEmpty() ? null : 'Use Test connections to call each panel.');

        foreach ($servers->where('verify_tls', false) as $server) {
            $checks[] = new Check('Connections', "{$server->name} TLS", Status::Warning, 'Certificate not verified', 'Turn Verify TLS back on once the panel has a valid certificate.');
        }

        foreach (Registrar::query()->where('active', true)->orderBy('name')->get() as $registrar) {
            $configured = rescue(fn () => app(RegistrarFactory::class)->forRegistrar($registrar)->configured(), false, false);
            $failures = ActivityLog::query()->where('event', 'provisioning.failed')->where('created_at', '>=', $week)
                ->where('properties->registrar_id', $registrar->id)->count();

            $checks[] = match (true) {
                ! $configured => new Check('Connections', $registrar->name, Status::Error, 'No login details', 'Fill in its credentials under Settings → Registrars.'),
                $failures > 0 => new Check('Connections', $registrar->name, Status::Warning, "{$failures} failed call(s) in 7 days", 'See the Activity log.'),
                default => new Check('Connections', $registrar->name, Status::Ok, 'No failed calls in 7 days'),
            };

            if (! $registrar->verify_tls) {
                $checks[] = new Check('Connections', "{$registrar->name} TLS", Status::Warning, 'Certificate not verified', 'Turn Verify TLS back on once the registrar renews its certificate.');
            }
        }

        $synced = Domain::query()->max('last_synced_at');
        if ($synced) {
            $at = CarbonImmutable::parse($synced);
            $checks[] = new Check('Connections', 'Domain sync', $at->diffInHours(now(), true) > self::DAILY_GRACE_HOURS ? Status::Warning : Status::Ok, 'Last domain synced '.$this->ago($at));
        }

        $failed = ActivityLog::query()->where('created_at', '>=', $week)
            ->whereIn('event', ['provisioning.failed', 'email.failed', 'reminder.failed'])
            ->selectRaw('event, count(*) as total')->groupBy('event')->pluck('total', 'event');
        $checks[] = $failed->isEmpty()
            ? new Check('Connections', 'Failures in the last 7 days', Status::Ok, 'None')
            : new Check('Connections', 'Failures in the last 7 days', Status::Warning,
                $failed->map(fn ($total, $event) => "{$event}: {$total}")->implode(', '), 'See the Activity log.');

        return $checks;
    }

    private function ago(CarbonImmutable $at): string
    {
        return $at->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat().' H:i').' ('.$at->diffForHumans().')';
    }

    private function bytes(string $value): int
    {
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
