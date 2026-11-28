<?php

namespace Plugins\OfflineMethods;

use App\Models\Invoice;
use App\Payments\PaymentModule;
use App\Support\Money;

/**
 * One way to pay set up under Settings → Other ways to pay: a name, and instructions for the client in which
 * {number} and {amount} become the payment notice's number and total. Payments are recorded by hand with its key.
 */
class OfflineMethod implements PaymentModule
{
    public function __construct(public readonly string $key, public readonly string $name, public readonly ?string $text) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->name;
    }

    /** The instructions with the placeholders taken out (for emails about no single notice). */
    public function instructions(): ?string
    {
        if (blank($this->text)) {
            return null;
        }

        $text = trim(str_replace(['{number}', '{amount}'], '', (string) $this->text));

        return $this->name.":\n".$text;
    }

    /** For one payment notice: {number} = its number (the reference to write), {amount} = its total. */
    public function instructionsFor(Invoice $invoice): ?string
    {
        if (blank($this->text)) {
            return null;
        }

        return $this->name.":\n".trim(strtr((string) $this->text, [
            '{number}' => $invoice->number,
            '{amount}' => Money::format($invoice->total_cents, $invoice->currency),
        ]));
    }
}
