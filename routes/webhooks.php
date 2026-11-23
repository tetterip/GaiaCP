<?php

use App\Http\Controllers\CspReportController;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Route;

// Webhooks: outside services (Stripe…) post here. No web middleware: no session, cookies or CSRF token; each plugin's
// controller checks the call's signature and that the plugin is on.
Route::prefix('webhooks')->name('webhooks.')->group(fn () => app(PluginManager::class)->webhookRoutes());

// Browsers report here what the Content-Security-Policy blocked (App\Support\ContentSecurityPolicy), for the log
Route::post('csp-report', CspReportController::class)->middleware('throttle:30,1')->name('csp-report');
