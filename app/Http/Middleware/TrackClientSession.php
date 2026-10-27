<?php

namespace App\Http\Middleware;

use App\ClientSessions\ClientSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client area: keeps this browser's row of signed-in devices (ClientSessions::track()). Runs before the panel's
 * Authenticate, so a browser logged out from elsewhere is sent to the login page by it.
 */
class TrackClientSession
{
    public function __construct(private ClientSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->track($request);

        return $next($request);
    }
}
