<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\IdempotentApiRequest;
use App\Http\Middleware\PrepareInstaller;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Support\MissingRecord;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as LaravelTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Called by outside services (payment webhooks), not browsers: outside the web group, so no session or CSRF
        then: fn () => Route::group([], base_path('routes/webhooks.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // REST API: the key (and with :<area>.<action> its permission), routes/api.php
        $middleware->alias(['api.key' => AuthenticateApiKey::class, 'api.idempotent' => IdempotentApiRequest::class]);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateApiKey::class); // throttled by key, no 404s before it
        // Before cookies and the session: sends everything to /install until GaiaCP is installed
        $middleware->prependToGroup('web', PrepareInstaller::class);
        // Visitor IPs and https behind Cloudflare (and any proxy in gaiacp.proxies)
        $middleware->replace(LaravelTrustProxies::class, TrustProxies::class);
        // Only APP_URL's address (and GAIACP_TRUSTED_HOSTS): email links never carry another host
        $middleware->prepend(TrustHosts::class);
        // No other site shows GaiaCP's pages in a frame (clickjacking)
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // A deleted record's page (Back after Delete, an old link): its list with a notice, not a bare 404
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => MissingRecord::redirect($e, $request));
    })->create();
