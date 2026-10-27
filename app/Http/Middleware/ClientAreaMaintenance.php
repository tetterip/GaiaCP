<?php

namespace App\Http\Middleware;

use App\Support\ClientAreaMaintenance as Maintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client area maintenance mode (App\Support\ClientAreaMaintenance): every client area page, login and sign up
 * included, answers with the maintenance message (503) or redirects to the address set. Signed-in admins pass. Only
 * the client panel runs it: /admin, webhooks and the branding images keep working.
 */
class ClientAreaMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Maintenance::isOn() || Maintenance::lets()) {
            return $next($request);
        }

        $url = Maintenance::redirectUrl();

        // Livewire's own requests can't follow a redirect to another site: they get the page, shown in its dialog
        if ($url !== null && ! $request->hasHeader('X-Livewire') && ! Maintenance::pointsHere($url, $request)) {
            return redirect()->away($url);
        }

        return response()->view('client-area.maintenance', [
            'message' => Maintenance::message(),
        ], 503);
    }
}
