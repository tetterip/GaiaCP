<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Client\QuoteController;
use App\Http\Controllers\Client\UnsubscribeController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SitemapController;
use App\Support\Branding;
use Illuminate\Support\Facades\Route;

// These moved under Settings (/admin/settings/...) in October 2026; keep old bookmarks working.
Route::get('/admin/{area}/{rest?}', fn (string $area, ?string $rest = null) => redirect('/admin/settings/'.$area.($rest ? '/'.$rest : ''), 301))
    ->where(['area' => 'products|servers|registrars|email-templates|system-health', 'rest' => '.*']);
// Settings → Billing became part of Settings → General.
Route::redirect('/admin/settings/billing', '/admin/settings/general', 301);

// The web installer: open only until GaiaCP is installed (App\Http\Middleware\PrepareInstaller), a 404 after
// Every route has its own name, or `route:cache` (run by `optimize` on deploy) refuses the duplicates.
Route::controller(InstallController::class)->prefix('install')->group(function () {
    Route::get('/', 'requirements')->name('install');
    Route::get('database', 'database')->name('install.database');
    Route::post('database', 'saveDatabase')->name('install.database.save');
    Route::get('admin', 'admin')->name('install.admin');
    Route::post('admin', 'finish')->name('install.finish');
});

// For search engines (Settings → Client area → Search engines): the public pages and what may be crawled
Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');

// The admin panel as an installable app: its manifest and service worker (push notifications), no login needed
Route::get('admin/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('admin/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.service-worker');

// The company logo and favicon (Settings → Company), shown before login too; no storage:link needed
Route::get('branding/{asset}', BrandingController::class)
    ->whereIn('asset', array_keys(Branding::ASSETS))
    ->name('branding');

// "Stop these emails" at the end of an informational message to clients (signed links, no login)
Route::controller(UnsubscribeController::class)->middleware('signed')->group(function () {
    Route::get('unsubscribe/{client}', 'show')->whereNumber('client')->name('unsubscribe');
    Route::post('unsubscribe/{client}', 'store')->whereNumber('client')->name('unsubscribe.confirm');
});

// A quote's page, from the Quote email (its long random token is the key, no login): accept or decline it
Route::controller(QuoteController::class)->prefix('quote/{token}')->where(['token' => '[A-Za-z0-9]{40,64}'])->group(function () {
    Route::get('/', 'show')->name('quote.show');
    Route::post('accept', 'accept')->middleware('throttle:10,1')->name('quote.accept');
    Route::post('decline', 'decline')->middleware('throttle:10,1')->name('quote.decline');
});
