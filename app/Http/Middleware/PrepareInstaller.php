<?php

namespace App\Http\Middleware;

use App\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * First in the web group, before cookies and the session. While GaiaCP isn't installed every page goes to
 * /install, which gets a .env with an app key on its first visit and keeps its session in files (the database
 * isn't there yet). Once installed /install is a 404. An install made before the installer existed is
 * recognised by its own .env and gets its lock file here.
 */
class PrepareInstaller
{
    public function __construct(private Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $installing = $this->installer->webInstallerOpen();

        if (! $installing && ! $this->installer->isLocked()) {
            try {
                $this->installer->lock();
            } catch (Throwable) {
                // storage not writable: System health says so; try again next time
            }
        }

        if (! $installing) {
            abort_if($request->is('install', 'install/*'), 404);

            return $next($request);
        }

        // An app key before anything encrypts (the cookie middleware runs even on the redirect)
        config(['session.driver' => 'file', 'cache.default' => 'file']);
        $this->installer->prepareEnvironment(marker: true);

        return $request->is('install', 'install/*') ? $next($request) : redirect('/install');
    }
}
