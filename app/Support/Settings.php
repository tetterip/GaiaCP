<?php

namespace App\Support;

use App\Models\Setting;
use App\Plugins\PluginManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings edited in the admin (the pages under Settings). Each key is a path under
 * config('gaiacp'); a stored value wins over the .env default, so the rest of the app
 * keeps reading config() and never needs to know where a value came from.
 */
class Settings
{
    /** The keys the core Settings pages may store (plugins add theirs, see keys()). Anything else stays in .env / config. */
    public const KEYS = [
        'company.name',
        'company.email',
        'company.phone',
        'company.website',
        'company.address',
        'company.vat_number',
        'company.tax_office',
        'company.logo',
        'company.logo_dark',
        'company.favicon',
        'languages.enabled',
        'languages.default',
        'region.timezone',
        'region.date_format',
        'region.country',
        'invoices.days_before',
        'credit.enabled',
        'credit.min_deposit',
        'credit.max_deposit',
        'credit.max_balance',
        'credit.auto_apply',
        'tax.enabled',
        'tax.inclusive',
        'tax.rules',
        'reminders.send_at',
        'reminders.before_days',
        'reminders.overdue_days',
        'reminders.bcc',
        'automation.suspend_after_days',
        'automation.cancel_unpaid_orders_days',
        'automation.compare_servers',
        'automation.price_change_notice_days',
        'automation.usage_warning_percent',
        'automation.client_messages_per_minute',
        'automation.daily_summary',
        'automation.change_request_email',
        'automation.cancellation_request_email',
        'automation.upgrade_request_email',
        'automation.new_order_email',
        'automation.new_client_email',
        'automation.exchange_rates',
        'domains.nameservers',
        'domains.auto_renew',
        'domains.renew_on_payment',
        'domains.free_renew_requires_product',
        'domains.idn',
        'domains.late_fees',
        'domains.ssl_product',
        'domains.ssl_discount',
        'domains.ssl_own_hosting',
        'client_area.domain_nameservers',
        'client_area.domain_epp',
        'client_area.domain_auto_renew',
        'client_area.domain_contacts',
        'client_area.domain_child_nameservers',
        'client_area.domain_lock',
        'client_area.hosting_password',
        'client_area.hosting_login',
        'client_area.hosting_usage',
        'client_area.hosting_uptime',
        'client_area.orders',
        'client_area.monthly_prices',
        'client_area.notifications',
        'client_area.email_history',
        'client_area.public_orders',
        'client_area.theme',
        'client_area.brand_color',
        'cookies.banner',
        'cookies.text',
        'cookies.policy_url',
        'cookies.scripts',
        'cookies.google_analytics',
        'seo.index',
        'public_area.home',
        'public_area.welcome_title',
        'public_area.welcome_text',
        'public_area.domain_search',
        'public_area.domain_prices',
        'public_area.knowledge_base',
        'public_area.contact',
        'public_area.contact_details',
        'public_area.contact_fields',
        'public_area.contact_hours',
        'public_area.contact_text',
        'public_area.contact_map',
        'public_area.announcements',
        'public_area.status',
        'seo.description',
        'client_area.email_links',
        'client_area.signup',
        'client_area.terms',
        'client_area.terms_url',
        'client_area.terms_text',
        'client_area.terms_version',
        'client_area.maintenance',
        'client_area.maintenance_message',
        'client_area.maintenance_redirect',
        'client_area.incident_notice',
        'client_area.incident_message',
        'captcha.provider',
        'captcha.forms',
        'theme.default',
        'login_security.enabled',
        'login_security.max_attempts',
        'login_security.ban_minutes',
        'login_security.allowed_ips',
        'backups.enabled',
        'backups.frequency',
        'backups.weekday',
        'backups.time',
        'backups.keep',
        'backups.zip_password',
        'backups.email',
        'backups.include_logs',
        'backups.ftp.enabled',
        'backups.ftp.protocol',
        'backups.ftp.host',
        'backups.ftp.port',
        'backups.ftp.username',
        'backups.ftp.password',
        'backups.ftp.path',
        'backups.ftp.passive',
        'mail.smtp',
        'mail.host',
        'mail.port',
        'mail.encryption',
        'mail.username',
        'mail.password',
        'mail.header',
        'mail.footer',
        'debug.module_log',
        'debug.module_log_days',
        'debug.email_log_days',
        'debug.activity_log_days',
        'debug.notification_days',
        'support.client_tickets',
        'support.client_priority',
        'support.admin_emails',
        'support.auto_close_days',
        'support.attachments_max_kb',
        'support.attachment_types',
        'support.reply_to',
        'support.knowledge_base',
        'sales.lead_sources',
        'sales.quote_valid_days',
        'sales.quote_footer',
        'ai.provider',
        'ai.features.improve',
        'ai.features.translate',
        'ai.features.domains',
        'ai.features.products',
        'ai.features.outage',
        'ai.features.questions',
        'ai.features.help',
        'ai.features.ticket_reply',
        'ai.features.ticket_summary',
        'ai.features.kb_article',
        'ai.features.reminders',
        'ai.features.clients',
        'ai.features.language_files',
        'ai.monthly_tokens',
    ];

    /** Secrets: stored encrypted with the app key, never sent back to the form (plus the plugins', see encrypted()). */
    public const ENCRYPTED = [
        'backups.zip_password',
        'backups.ftp.password',
        'mail.password',
        'aade.password',
    ];

    /** @return list<string> every key a Settings page may store: the core's and the plugins' */
    public static function keys(): array
    {
        return [...self::KEYS, ...app(PluginManager::class)->settingKeys()];
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return array_values(array_unique([...self::ENCRYPTED, ...app(PluginManager::class)->encryptedSettingKeys()]));
    }

    /** Load stored values into config. Called on every boot; harmless before the first migration. */
    public static function apply(): void
    {
        try {
            $stored = Setting::query()->whereIn('key', self::keys())->pluck('value', 'key')->all();
        } catch (QueryException|SQLiteDatabaseDoesNotExistException) {
            return; // no settings table yet (fresh install, test bootstrap)
        }

        foreach (array_intersect_key($stored, array_flip(self::encrypted())) as $key => $value) {
            try {
                $stored[$key] = $value === null ? null : Crypt::decryptString($value);
            } catch (DecryptException) {
                $stored[$key] = null; // APP_KEY changed: the secret has to be entered again
            }
        }

        self::put($stored);
    }

    /** @param  array<string, mixed>  $values  dotted keys from self::keys() */
    public static function save(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::keys()));

        foreach ($values as $key => $value) {
            $stored = in_array($key, self::encrypted(), true) && $value !== null ? Crypt::encryptString((string) $value) : $value;
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        }

        self::put($values);
    }

    /** @return array<string, mixed> current value of every key, for filling the form */
    public static function all(): array
    {
        return collect(self::keys())->mapWithKeys(fn (string $key) => [$key => config("gaiacp.{$key}")])->all();
    }

    /** @param  array<string, mixed>  $values */
    private static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            config(["gaiacp.{$key}" => $value]);
        }

        // Reminder emails are sent as the company.
        config(array_filter([
            'mail.from.name' => config('gaiacp.company.name'),
            'mail.from.address' => config('gaiacp.company.email'),
        ]));

        self::applyMailer((array) config('gaiacp.mail'));

        if (array_key_exists('region.timezone', $values)) {
            Region::applyTimezone();
        }
    }

    /**
     * Point Laravel's mailer at the SMTP server from the Mail tab. Also used by the test email
     * with the unsaved form values; config only lives for the current request.
     *
     * @param  array<string, mixed>  $mail  the gaiacp.mail settings
     */
    public static function applyMailer(array $mail): void
    {
        // The demo's email only goes to the log (Demo::boot())
        if (Demo::enabled()) {
            return;
        }

        if (! ($mail['smtp'] ?? false) || blank($mail['host'] ?? null)) {
            return;
        }

        $encryption = $mail['encryption'] ?? 'tls';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => array_merge((array) config('mail.mailers.smtp'), [
                'transport' => 'smtp',
                'url' => null,
                // smtps = TLS from the first byte (465); smtp upgrades with STARTTLS when offered.
                'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'auto_tls' => $encryption !== 'none',
                'host' => $mail['host'],
                'port' => (int) ($mail['port'] ?? ($encryption === 'ssl' ? 465 : 587)),
                'username' => $mail['username'] ?? null,
                'password' => $mail['password'] ?? null,
            ]),
        ]);

        // A mailer built earlier in this request keeps its old transport otherwise.
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }
}
