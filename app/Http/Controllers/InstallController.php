<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstallAdminRequest;
use App\Http\Requests\InstallDatabaseRequest;
use App\Install\Installer;
use App\Support\Currencies;
use App\Support\Locales;
use App\Support\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\Intl\Countries;

/**
 * The web installer (/install): requirements, database, business and first admin, done. Open only while
 * nothing is installed (App\Http\Middleware\PrepareInstaller); the work is App\Install\Installer's.
 */
class InstallController extends Controller
{
    public function __construct(private Installer $installer) {}

    public function requirements(Request $request): View
    {
        $this->localize($request);

        return view('install.requirements', [
            'checks' => $this->installer->requirements(),
            'ready' => $this->installer->meetsRequirements(),
        ]);
    }

    public function database(Request $request): View|RedirectResponse
    {
        $this->localize($request);

        if (! $this->installer->meetsRequirements()) {
            return redirect()->route('install');
        }

        $env = $this->installer->readEnv();

        return view('install.database', [
            'drivers' => $this->installer->availableDrivers(),
            'env' => $env,
        ]);
    }

    public function saveDatabase(InstallDatabaseRequest $request): RedirectResponse
    {
        $this->localize($request);

        try {
            $db = $request->validated();
            // SQLite: the default file (the name field belongs to MySQL)
            $this->installer->useDatabase($db['driver'] === 'sqlite' ? ['driver' => 'sqlite', 'database' => ''] : $db);
            $this->installer->migrate();
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('password'))
                ->withErrors(['database' => __('Cannot connect to the database: :reason', ['reason' => $e->getMessage()])]);
        }

        return redirect()->route('install.admin');
    }

    public function admin(Request $request): View|RedirectResponse
    {
        $this->localize($request);

        if (! $this->installer->migrated()) {
            return redirect()->route('install.database');
        }

        return view('install.admin', [
            'hasAdmins' => $this->installer->hasAdmins(),
            'url' => $request->root(),
            'timezones' => Region::timezoneOptions(),
            'dateFormats' => Region::dateFormatOptions(),
            'countries' => Countries::getNames(app()->getLocale()),
            'currencies' => Region::currencyOptions(),
            'currency' => Currencies::base(),
        ]);
    }

    public function finish(InstallAdminRequest $request): View|RedirectResponse
    {
        $this->localize($request);

        if (! $this->installer->migrated()) {
            return redirect()->route('install.database');
        }

        $data = $request->validated();
        $this->installer->saveCompany($data['company'], $data['company_email'] ?? null, $data['url'] ?? null);
        $this->installer->saveRegion($data['timezone'] ?? 'UTC', $data['date_format'] ?? 'd/m/Y', $data['country'] ?? null, $data['currency'] ?? null);

        if (! $this->installer->hasAdmins()) {
            $this->installer->createAdmin($data['name'], $data['email'], $data['password'], app()->getLocale());
        }

        $this->installer->finish();
        $request->session()->forget('install');

        return view('install.done', [
            'login' => rtrim($data['url'] ?? $request->root(), '/').'/admin',
            'cron' => $this->installer->cronLine(),
        ]);
    }

    /** The installer's language: ?lang= picks it, the session keeps it; the default language otherwise. */
    private function localize(Request $request): void
    {
        if (array_key_exists((string) $request->query('lang'), Locales::available())) {
            $request->session()->put('install.locale', $request->query('lang'));
        }

        app()->setLocale(Locales::normalize($request->session()->get('install.locale')));
    }
}
