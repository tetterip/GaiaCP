<?php

namespace Tests;

use App\Filament\Client\Pages\Cart;
use App\Support\DomainSearch;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real panel or registrar.
        Http::preventStrayRequests();
        // Nor DNS: a domain search finds no nameservers unless a test says otherwise.
        DomainSearch::resolveNameserversUsing(fn () => []);

        // On a client area order page: add what is filled in to the cart, then place the cart (Cart) with $cart's
        // fields (notes, accept_terms). With errors on the order page, that page is returned for asserting them.
        Testable::macro('checkout', function (array $cart = []): Testable {
            /** @var Testable $this */
            $this->call('addToCart');

            if ($this->errors()->isNotEmpty()) {
                return $this;
            }

            return Livewire::test(Cart::class)->fillForm($cart)->call('placeOrder');
        });
    }
}
