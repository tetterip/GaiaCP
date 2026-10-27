<?php

use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Route;

// Webhooks: outside services (Stripe…) post here. No web middleware: no session, cookies or CSRF token; each plugin's
// controller checks the call's signature and that the plugin is on.
Route::prefix('webhooks')->name('webhooks.')->group(fn () => app(PluginManager::class)->webhookRoutes());
