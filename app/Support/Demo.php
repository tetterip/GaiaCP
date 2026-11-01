<?php

namespace App\Support;

use App\Enums\ServiceType;
use App\Filament\Clusters\Settings\Pages\ApiKeySettings;
use App\Filament\Clusters\Settings\Pages\BackupSettings;
use App\Filament\Clusters\Settings\Pages\CookieSettings;
use App\Filament\Clusters\Settings\Pages\LanguageSettings;
use App\Filament\Clusters\Settings\Pages\LoginSecuritySettings;
use App\Filament\Clusters\Settings\Pages\MailSettings;
use App\Filament\Pages\ErrorLog;
use App\Filament\Pages\PluginCatalogue;
use App\Filament\Pages\SystemHealth;
use App\Filament\Pages\Updates;
use App\Filament\Resources\IpBans\IpBanResource;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * The public demo (GAIACP_DEMO=true, docs/demo-site.md). Its made-up data (Database\Seeders\DemoSeeder) is put back
 * every reset_minutes by gaiacp:demo-reset, the two logins below are filled in on the login pages, and nothing leaves
 * the server: email goes to the log, every HTTP call fails as "cannot connect", the WHOIS lookup answers from the
 * demo's own domains (whois()), plugin uploads and updates are off,
 * the pages that reach the server itself (backups, error log, mail, login security, …) are closed, and the plugins
 * that open their own connections can't be switched on. The demo accounts keep their email, password and 2FA.
 * Servers and registrars are the Demo plugin's (plugins/Demo), which act on the stored services only.
 */
class Demo
{
    public const ADMIN_EMAIL = 'admin@example.com';

    public const CLIENT_EMAIL = 'client@example.com';

    public const PASSWORD = 'demo';

    /** Names the demo's registrar and WHOIS lookup treat as taken in every ending, besides the domains it has. */
    public const FAMOUS = ['google', 'facebook', 'amazon', 'wikipedia', 'youtube', 'example', 'microsoft', 'apple'];

    /** @var list<class-string> pages and resources closed in the demo (LimitedToAdminArea) */
    public const CLOSED = [
        Updates::class,
        PluginCatalogue::class,
        ErrorLog::class,
        SystemHealth::class,
        BackupSettings::class,
        CookieSettings::class, // its scripts would run for every visitor of the demo
        ApiKeySettings::class,
        MailSettings::class,
        LoginSecuritySettings::class,
        LanguageSettings::class,
        IpBanResource::class,
    ];

    /** @var array<string, string> plugin id => folder, of the plugins that stay off in the demo (pages closed too): they open connections of their own or charge cards */
    public const PLUGINS_OFF = ['ticket-mail' => 'TicketMail', 'whmcs-import' => 'WhmcsImport', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'site-checks' => 'SiteChecks', 'blacklists' => 'Blacklists'];

    /** @var list<string> the columns the demo accounts keep whatever is saved */
    private const LOCKED_COLUMNS = ['email', 'password', 'app_authentication_secret', 'app_authentication_recovery_codes', 'role', 'client_id', 'active'];

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.demo.enabled');
    }

    /** Minutes between resets: 1 to 60. */
    public static function minutes(): int
    {
        return max(1, min(60, (int) config('gaiacp.demo.reset_minutes', 10) ?: 10));
    }

    /** When gaiacp:demo-reset runs (every minutes() from the hour). */
    public static function cron(): string
    {
        return self::minutes() === 60 ? '0 * * * *' : '*/'.self::minutes().' * * * *';
    }

    public static function nextReset(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        return CarbonImmutable::instance((new CronExpression(self::cron()))->getNextRunDate($now->toDateTimeImmutable()));
    }

    /** Whether this page or resource is closed here. */
    public static function closes(string $class): bool
    {
        return self::enabled() && (in_array($class, self::CLOSED, true)
            || collect(self::PLUGINS_OFF)->contains(fn (string $folder) => str_starts_with($class, 'Plugins\\'.$folder.'\\')));
    }

    public static function keepsPluginOff(string $id): bool
    {
        return self::enabled() && isset(self::PLUGINS_OFF[$id]);
    }

    public static function isDemoAccount(User $user): bool
    {
        return in_array(strtolower((string) $user->getOriginal('email')), [self::ADMIN_EMAIL, self::CLIENT_EMAIL], true);
    }

    /** Called once at boot (AppServiceProvider) when the demo is on: no email or HTTP leaves the server. */
    public static function boot(): void
    {
        config(['mail.default' => 'log']);

        Http::globalMiddleware(fn (callable $handler) => fn (RequestInterface $request, array $options) => Create::rejectionFor(
            new ConnectException('Outgoing connections are off in the demo.', $request),
        ));

        // Someone trying the demo can't lock the others out of its two accounts, nor delete them.
        User::updating(function (User $user) {
            if (self::isDemoAccount($user)) {
                $user->setRawAttributes([...$user->getAttributes(), ...Arr::only($user->getRawOriginal(), self::LOCKED_COLUMNS)]);
            }
        });
        User::deleting(fn (User $user) => self::isDemoAccount($user) ? false : null);

        // RDAP fails like every HTTP call, so the lookup falls back to WHOIS, answered here instead of on port 43
        Whois::queryUsing(fn (string $server, string $query) => self::whois($query));
    }

    /**
     * The demo's WHOIS answer: the registry's server for an ending (the lookup asks IANA first), then a domain's record,
     * registered when the demo has it (its own dates and nameservers) or it is a famous name, else "No match".
     */
    public static function whois(string $query): string
    {
        $query = strtolower(trim($query));

        if (! str_contains($query, '.')) {
            return "refer: whois.demo-registry.example\nwhois: whois.demo-registry.example\n";
        }

        $service = Service::query()->where('type', ServiceType::Domain)->where('domain', $query)->with('domainDetails')->latest('id')->first();
        $famous = in_array(explode('.', $query)[0], self::FAMOUS, true);

        if (! $service && ! $famous) {
            return "No match for \"{$query}\".\n";
        }

        $created = $service ? CarbonImmutable::parse($service->start_date) : CarbonImmutable::parse('1997-09-15');
        $expires = $service ? ($service->domainDetails?->expires_at ?? CarbonImmutable::parse($service->next_due_date)) : CarbonImmutable::create(today()->year + 3, 9, 14);
        $nameservers = $service?->domainDetails?->nameservers ?: ['ns1.demo-hosting.example', 'ns2.demo-hosting.example'];

        return implode("\n", [
            'Domain Name: '.strtoupper($query),
            'Registrar: '.($service ? 'Demo registrar' : 'Example Registrar, Inc.'),
            'Creation Date: '.$created->format('Y-m-d').'T00:00:00Z',
            'Registry Expiry Date: '.CarbonImmutable::parse($expires)->format('Y-m-d').'T00:00:00Z',
            'Domain Status: clientTransferProhibited',
            ...array_map(fn (string $nameserver) => 'Name Server: '.strtoupper($nameserver), $nameservers),
            'DNSSEC: unsigned',
            '',
            '>>> This is the GaiaCP demo: a made-up answer. <<<',
        ])."\n";
    }
}
