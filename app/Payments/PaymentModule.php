<?php

namespace App\Payments;

/**
 * A way clients pay (bank transfer, later card payments), from a plugin. Its key is stored in `payments.method` and
 * `clients.default_payment_method`; a payment keeps its key even when the plugin is switched off later.
 */
interface PaymentModule
{
    /** Stable key, stored on payments. */
    public function key(): string;

    /** Translated name, e.g. "Bank transfer". */
    public function label(): string;

    /** Plain text telling the client how to pay this way (printed on payment notices and their emails); null = none. */
    public function instructions(): ?string;
}
