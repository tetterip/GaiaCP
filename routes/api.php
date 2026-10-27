<?php

use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\KeyController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

// REST API (App\Api\OpenApi describes it): a key from Settings → API keys as a bearer token, `api.key:<area>.<action>`
// the permission each call needs. No session, cookies or CSRF.
Route::prefix('v1')->name('api.v1.')->middleware(['api.key', 'throttle:gaiacp-api'])->group(function () {
    Route::get('/', [KeyController::class, 'show'])->name('key');
    Route::get('openapi.json', [KeyController::class, 'openApi'])->name('openapi');

    Route::middleware('api.key:clients.read')->group(function () {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    });
    Route::post('clients', [ClientController::class, 'store'])->middleware('api.key:clients.create')->name('clients.store');
    Route::patch('clients/{client}', [ClientController::class, 'update'])->middleware('api.key:clients.update')->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('api.key:clients.delete')->name('clients.destroy');

    Route::middleware('api.key:services.read')->group(function () {
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    });
    Route::middleware('api.key:services.update')->group(function () {
        Route::patch('services/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::post('services/{service}/suspend', [ServiceController::class, 'suspend'])->name('services.suspend');
        Route::post('services/{service}/unsuspend', [ServiceController::class, 'unsuspend'])->name('services.unsuspend');
    });
    Route::post('services/{service}/terminate', [ServiceController::class, 'terminate'])->middleware('api.key:services.delete')->name('services.terminate');

    Route::middleware('api.key:orders.read')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    });
    Route::post('orders', [OrderController::class, 'store'])->middleware('api.key:orders.create')->name('orders.store');
    Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->middleware('api.key:orders.update')->name('orders.complete');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('api.key:orders.delete')->name('orders.cancel');

    Route::middleware('api.key:invoices.read')->group(function () {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    });
    Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('api.key:payments.create')->name('invoices.pay');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->middleware('api.key:invoices.update')->name('invoices.send');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('api.key:invoices.delete')->name('invoices.cancel');

    Route::middleware('api.key:payments.read')->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    });
    Route::post('payments', [PaymentController::class, 'store'])->middleware('api.key:payments.create')->name('payments.store');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->middleware('api.key:payments.delete')->name('payments.destroy');

    Route::middleware('api.key:products.read')->group(function () {
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    });
    Route::post('products', [ProductController::class, 'store'])->middleware('api.key:products.create')->name('products.store');
    Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('api.key:products.update')->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('api.key:products.delete')->name('products.destroy');

    Route::middleware('api.key:leads.read')->group(function () {
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    });
    Route::post('leads', [LeadController::class, 'store'])->middleware('api.key:leads.create')->name('leads.store');
    Route::patch('leads/{lead}', [LeadController::class, 'update'])->middleware('api.key:leads.update')->name('leads.update');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->middleware('api.key:leads.delete')->name('leads.destroy');

    Route::middleware('api.key:quotes.read')->group(function () {
        Route::get('quotes', [QuoteController::class, 'index'])->name('quotes.index');
        Route::get('quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
    });
    Route::post('quotes', [QuoteController::class, 'store'])->middleware('api.key:quotes.create')->name('quotes.store');
    Route::middleware('api.key:quotes.update')->group(function () {
        Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
        Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
        Route::post('quotes/{quote}/decline', [QuoteController::class, 'decline'])->name('quotes.decline');
    });
    Route::delete('quotes/{quote}', [QuoteController::class, 'destroy'])->middleware('api.key:quotes.delete')->name('quotes.destroy');
});
