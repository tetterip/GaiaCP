<?php

namespace App\Support;

use App\Filament\Clusters\Settings\Pages\BackupSettings;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Clusters\Settings\Pages\MailSettings;
use App\Filament\Clusters\Settings\Pages\PaymentMethodsSettings;
use App\Filament\Clusters\Settings\Pages\PublicAreaSettings;
use App\Filament\Clusters\Settings\Pages\TaxSettings;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Tlds\TldResource;
use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Tld;
use App\Models\User;
use App\Support\Health\SchedulerMonitor;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * The setup guide (/admin/setup, super admins): the steps a new install goes through after /install, each on a page
 * that already exists. A step is done when what it sets up is there (a server, a product, the company's address…), or
 * when the admin marks it done; any step can be skipped. What was marked and whether the guide was hidden from the
 * dashboard live in one `settings` row (not an editable setting). Installs from before the guide start hidden.
 */
class SetupGuide
{
    public const SETTING = 'setup_guide';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    /**
     * Every step in order. `check` says whether it is done by itself (null = only the admin can say), `page` is the
     * class whose URL the step opens.
     *
     * @return list<array{key: string, title: string, description: string, icon: mixed, page: class-string, check: (callable(): bool)|null}>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'company',
                'title' => __('Your business'),
                'description' => __('Name, email, address and logo: they go on payment notices, client emails and the client area.'),
                'icon' => Heroicon::OutlinedBuildingOffice2,
                'page' => CompanySettings::class,
                'check' => fn () => filled(config('gaiacp.company.name')) && filled(config('gaiacp.company.email')) && filled(config('gaiacp.company.address')),
            ],
            [
                'key' => 'region',
                'title' => __('Region and languages'),
                'description' => __('Timezone, date format, the country of new clients, and the languages of the client area.'),
                'icon' => Heroicon::OutlinedGlobeEuropeAfrica,
                'page' => GeneralSettings::class,
                'check' => fn () => self::stored('region.timezone'),
            ],
            [
                'key' => 'mail',
                'title' => __('Outgoing mail'),
                'description' => __('The SMTP server that sends payment notices, reminders and welcome emails. Send a test email from there.'),
                'icon' => Heroicon::OutlinedEnvelope,
                'page' => MailSettings::class,
                'check' => fn () => (config('gaiacp.mail.smtp') && filled(config('gaiacp.mail.host'))) || ! in_array(config('mail.default'), ['log', 'array'], true),
            ],
            [
                'key' => 'payments',
                'title' => __('Payment methods'),
                'description' => __('How clients pay you: bank transfer details, Stripe, or other payment plugins.'),
                'icon' => Heroicon::OutlinedCreditCard,
                'page' => PaymentMethodsSettings::class,
                'check' => null,
            ],
            [
                'key' => 'tax',
                'title' => __('Tax'),
                'description' => __('Whether you charge tax, the rate per country and whether prices include it. Skip it if you don\'t charge tax.'),
                'icon' => Heroicon::OutlinedReceiptPercent,
                'page' => TaxSettings::class,
                'check' => fn () => self::stored('tax.enabled'),
            ],
            [
                'key' => 'server',
                'title' => __('Your first server'),
                'description' => __('A cPanel, Plesk, DirectAdmin or HestiaCP server, so hosting accounts are created by themselves. Skip it if you only sell domains or other services.'),
                'icon' => Heroicon::OutlinedServerStack,
                'page' => ServerResource::class,
                'check' => fn () => Server::query()->exists(),
            ],
            [
                'key' => 'domains',
                'title' => __('Domain prices'),
                'description' => __('The TLDs you sell, their prices and the registrar of each. Skip it if you don\'t sell domains.'),
                'icon' => Heroicon::OutlinedGlobeAlt,
                'page' => TldResource::class,
                'check' => fn () => Tld::query()->exists(),
            ],
            [
                'key' => 'products',
                'title' => __('Your first product'),
                'description' => __('A hosting plan or another service with its prices per billing cycle; new services and orders start from it.'),
                'icon' => Heroicon::OutlinedCube,
                'page' => ProductResource::class,
                'check' => fn () => Product::query()->exists(),
            ],
            [
                'key' => 'client_area',
                'title' => __('Client area and terms'),
                'description' => __('Your terms of service, what clients may do themselves, and whether they can order and sign up.'),
                'icon' => Heroicon::OutlinedUserGroup,
                'page' => ClientAreaSettings::class,
                'check' => fn () => TermsOfService::required(),
            ],
            [
                'key' => 'public_area',
                'title' => __('Public area'),
                'description' => __('What visitors see before they log in: a home page, the domain search, domain prices and a contact form. The public order page and what search engines see are under Client area. Skip it if your site root should stay the login.'),
                'icon' => Heroicon::OutlinedHome,
                'page' => PublicAreaSettings::class,
                // saved once, even with every page left off: the admin has decided what visitors see
                'check' => fn () => self::stored('public_area.home'),
            ],
            [
                'key' => 'backups',
                'title' => __('Backups'),
                'description' => __('A copy of the database and files on a schedule, by email or FTP, with a password.'),
                'icon' => Heroicon::OutlinedArchiveBox,
                'page' => BackupSettings::class,
                'check' => fn () => (bool) config('gaiacp.backups.enabled'),
            ],
            [
                'key' => 'cron',
                'title' => __('Cron job'),
                'description' => __('One cron line runs reminders, suspensions and every other automatic job. System health shows it and whether it runs.'),
                'icon' => Heroicon::OutlinedClock,
                'page' => SystemHealth::class,
                'check' => fn () => app(SchedulerMonitor::class)->lastHeartbeat() !== null,
            ],
            [
                'key' => 'two_factor',
                'title' => __('Two-factor sign-in'),
                'description' => __('A code from an app on your phone each time you sign in to the admin, set up in your profile.'),
                'icon' => Heroicon::OutlinedShieldCheck,
                'page' => EditProfile::class,
                'check' => fn () => ($user = auth()->user()) instanceof User && $user->hasTwoFactor(),
            ],
        ];
    }

    /** @return Collection<int, array{key: string, title: string, description: string, icon: mixed, url: string, status: ?string, auto: bool}> */
    public static function steps(): Collection
    {
        $marked = self::state()['marked'];

        return collect(self::definitions())->map(function (array $step) use ($marked) {
            $auto = $step['check'] !== null && ($step['check'])();

            return [
                'key' => $step['key'],
                'title' => $step['title'],
                'description' => $step['description'],
                'icon' => $step['icon'],
                'url' => $step['page']::getUrl(),
                // what is there wins over a skip: a server added after skipping the step counts as done
                'status' => $auto ? self::DONE : ($marked[$step['key']] ?? null),
                'auto' => $auto,
            ];
        });
    }

    /** @return array{done: int, total: int} skipped steps count as done */
    public static function progress(): array
    {
        $steps = self::steps();

        return ['done' => $steps->whereNotNull('status')->count(), 'total' => $steps->count()];
    }

    public static function finished(): bool
    {
        ['done' => $done, 'total' => $total] = self::progress();

        return $done === $total;
    }

    /** Whether the dashboard shows the guide's card: until every step is done or skipped, or the admin hides it. */
    public static function showOnDashboard(): bool
    {
        return ! self::state()['hidden'] && ! self::finished();
    }

    /** @param  self::DONE|self::SKIPPED|null  $status  null = to do again */
    public static function mark(string $key, ?string $status): void
    {
        abort_unless(in_array($key, array_column(self::definitions(), 'key'), true), 404);
        abort_unless(in_array($status, [self::DONE, self::SKIPPED, null], true), 422);

        $state = self::state();
        $state['marked'][$key] = $status;
        $state['marked'] = array_filter($state['marked']);
        self::store($state);
    }

    public static function hide(bool $hidden = true): void
    {
        self::store(['hidden' => $hidden] + self::state());
    }

    /** @return array{marked: array<string, string>, hidden: bool} */
    public static function state(): array
    {
        $value = (array) Setting::query()->whereKey(self::SETTING)->value('value');

        return ['marked' => (array) ($value['marked'] ?? []), 'hidden' => (bool) ($value['hidden'] ?? false)];
    }

    /** @param  array{marked: array<string, string>, hidden: bool}  $state */
    private static function store(array $state): void
    {
        Setting::query()->updateOrCreate(['key' => self::SETTING], ['value' => $state]);
    }

    /** Saved on its Settings page (or by the installer), not only the default from config. */
    private static function stored(string $key): bool
    {
        return Setting::query()->whereKey($key)->exists();
    }
}
