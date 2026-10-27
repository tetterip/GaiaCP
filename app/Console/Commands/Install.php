<?php

namespace App\Console\Commands;

use App\Install\Installer;
use App\Support\Currencies;
use App\Support\Health\Status;
use App\Support\Region;
use App\Support\Version;
use DateTimeZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\Intl\Countries;

#[Signature('gaiacp:install
    {--db-connection= : mysql or sqlite}
    {--db-host=} {--db-port=} {--db-database= : The database name (MySQL) or file (SQLite, default database/database.sqlite)}
    {--db-username=} {--db-password=}
    {--company= : Your business name} {--company-email=} {--url= : The address of the panel, e.g. https://billing.example.com}
    {--timezone= : e.g. Europe/Athens (default UTC)} {--date-format= : d/m/Y, d.m.Y, d-m-Y, m/d/Y or Y-m-d}
    {--country= : The country new clients start in (ISO code, e.g. GR)} {--currency= : The base currency (default EUR)}
    {--admin-name=} {--admin-email=} {--admin-password=}')]
#[Description('Set up a new GaiaCP: check the server, write .env, create the tables and the first admin (the same as /install)')]
class Install extends Command
{
    public function handle(Installer $installer): int
    {
        if ($installer->isLocked()) {
            $this->info('GaiaCP is already installed (storage/app/private/installed). Nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Installing GaiaCP '.Version::current());

        foreach ($installer->requirements() as $check) {
            if ($check->status === Status::Error) {
                $this->error("{$check->name}: {$check->value}".($check->hint ? " ({$check->hint})" : ''));
            }
        }
        if (! $installer->meetsRequirements()) {
            return self::FAILURE;
        }

        $installer->prepareEnvironment();
        $env = $installer->readEnv();

        $drivers = $installer->availableDrivers();
        $default = array_key_exists($env['DB_CONNECTION'] ?? '', $drivers) ? $env['DB_CONNECTION'] : array_key_first($drivers);
        $driver = $this->option('db-connection') ?: ($this->input->isInteractive() ? $this->choice('Database', array_keys($drivers), $default) : $default);
        if (! array_key_exists($driver, $drivers)) {
            $this->error("This PHP can't use {$driver}: pick one of ".implode(', ', array_keys($drivers)).'.');

            return self::FAILURE;
        }

        $db = ['driver' => $driver] + ($driver === 'sqlite'
            ? ['database' => $this->value('db-database', 'SQLite file', $env['DB_DATABASE'] ?? 'database.sqlite')]
            : [
                'host' => $this->value('db-host', 'Database host', $env['DB_HOST'] ?? '127.0.0.1'),
                'port' => $this->value('db-port', 'Database port', $env['DB_PORT'] ?? '3306'),
                'database' => $this->value('db-database', 'Database name', $env['DB_DATABASE'] ?? 'gaiacp'),
                'username' => $this->value('db-username', 'Database user', $env['DB_USERNAME'] ?? null),
                'password' => $this->value('db-password', 'Database password', '', secret: true),
            ]);

        try {
            $installer->useDatabase($db);
        } catch (RuntimeException $e) {
            $this->error('Cannot connect to the database: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Creating the tables...');
        $installer->migrate();

        if ($installer->hasAdmins()) {
            $this->info('This database already has admins, so none is created.');
        } else {
            $company = $this->value('company', 'Business name', 'GaiaCP');
            $companyEmail = $this->value('company-email', 'Business email (the sender of client emails)');
            $url = $this->value('url', 'Panel address', $env['APP_URL'] ?? 'http://localhost');
            $timezone = $this->value('timezone', 'Timezone', 'UTC');
            $dateFormat = $this->value('date-format', 'Date format (d/m/Y, d.m.Y, d-m-Y, m/d/Y or Y-m-d)', 'd/m/Y');
            $country = $this->value('country', 'Country of new clients (ISO code, empty for none)');
            $currency = $this->value('currency', 'Base currency', Currencies::base());
            $name = $this->value('admin-name', 'Your name');
            $email = $this->value('admin-email', 'Your email (to log in)');
            $password = $this->value('admin-password', 'Password (at least 8 characters)', secret: true);

            $validator = Validator::make(
                ['company' => $company, 'company_email' => $companyEmail, 'url' => $url, 'name' => $name, 'email' => $email, 'password' => $password,
                    'timezone' => $timezone, 'date_format' => $dateFormat, 'country' => $country ? strtoupper($country) : null, 'currency' => strtoupper((string) $currency)],
                ['company' => 'required|string|max:255', 'company_email' => 'nullable|email', 'url' => 'nullable|url', 'name' => 'required|string|max:255',
                    'email' => 'required|email|max:255|unique:users,email', 'password' => 'required|string|min:8',
                    'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())], 'date_format' => ['required', Rule::in(Region::DATE_FORMATS)],
                    'country' => ['nullable', Rule::in(Countries::getCountryCodes())], 'currency' => ['required', Rule::in(array_keys(Region::currencyOptions()))]],
            );
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $installer->saveCompany($company, $companyEmail, $url);
            $installer->saveRegion($timezone, $dateFormat, $country, $currency);
            $installer->createAdmin($name, $email, $password);
        }

        $installer->finish();

        $this->newLine();
        $this->info('GaiaCP is installed. Log in at '.rtrim($installer->readEnv()['APP_URL'] ?? '', '/').'/admin');
        $this->line('Add this cron job so the daily automation runs:');
        $this->line('  '.$installer->cronLine());

        return self::SUCCESS;
    }

    /** The option when given, else the answer (or, with --no-interaction, the default). */
    private function value(string $option, string $question, ?string $default = null, bool $secret = false): ?string
    {
        if (filled($this->option($option))) {
            return $this->option($option);
        }
        if (! $this->input->isInteractive()) {
            return $default;
        }

        return $secret ? ($this->secret($question) ?? $default) : $this->ask($question, $default);
    }
}
