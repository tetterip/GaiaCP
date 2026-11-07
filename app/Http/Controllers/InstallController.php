<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstallAdminRequest;
use App\Http\Requests\InstallDatabaseRequest;
use App\Install\Installer;
use App\Support\Currencies;
use App\Support\Locales;
use App\Support\Region;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\Intl\Countries;

/**
 * The web installer (/install): requirements, database, business and first admin, done. Open only while
 * nothing is installed (App\Http\Middleware\PrepareInstaller); the work is App\Install\Installer's. Past the first
 * page it needs the setup code from storage/app/private/install-code.txt, so only whoever can reach the server's
 * files can install (anyone can open the site until then).
 */
class InstallController extends Controller implements HasMiddleware
{
    public function __construct(private Installer $installer) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next) {
                return $request->session()->get('install.unlocked') === true ? $next($request) : redirect()->route('install');
            }, except: ['requirements', 'unlock']),
        ];
    }

    public function requirements(Request $request): View
    {
        $this->localize($request);
        $ready = $this->installer->meetsRequirements($request);
        $unlocked = $request->session()->get('install.unlocked') === true;
        $codeError = null;

        if ($ready && ! $unlocked) {
            try {
                $this->installer->setupCode(); // the file the visitor is about to look for
            } catch (RuntimeException $e) {
                $codeError = $e->getMessage();
            }
        }

        return view('install.requirements', [
            'checks' => $this->installer->requirements($request),
            'ready' => $ready,
            'unlocked' => $unlocked,
            'codeError' => $codeError,
        ]);
    }

    /** The setup code typed on the first page: right, and the rest of the installer opens for this browser. */
    public function unlock(Request $request): RedirectResponse
    {
        $this->localize($request);
        $request->validate(['code' => ['required', 'string', 'max:64']]);

        try {
            $valid = $this->installer->checkSetupCode((string) $request->input('code'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        if (! $valid) {
            return back()->withErrors(['code' => __('This is not the setup code. Copy it from :file on the server.', ['file' => 'storage/app/private/install-code.txt'])]);
        }

        $request->session()->regenerate();
        $request->session()->put('install.unlocked', true);

        return redirect()->route('install.database');
    }

    public function database(Request $request): View|RedirectResponse
    {
        $this->localize($request);

        if (! $this->installer->meetsRequirements($request)) {
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
