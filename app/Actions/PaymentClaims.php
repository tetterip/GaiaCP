<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\InvoiceStatus;
use App\Mail\PaymentClaimMail;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\User;
use App\Payments\PaymentMethods;
use App\Support\Locales;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * A client's "I've paid" on an unpaid payment notice, for a payment GaiaCP can't see by itself (an IRIS transfer, say).
 * It never marks anything paid: it rings the bell, emails the admins (Settings → Automation → Payment claim email) and
 * waits on the notice until an admin records the payment (Invoice::settle() clears it) or rejects the claim.
 */
class PaymentClaims
{
    /** An unpaid notice that still owes something and has no claim waiting. */
    public static function canClaim(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Unpaid && $invoice->payment_claimed_at === null && ! $invoice->credit
            && app(RecordInvoicePayment::class)->unpaid($invoice)->sum('total_cents') > 0;
    }

    /** The claim waiting on the notice, while it is still unpaid. */
    public static function waiting(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Unpaid && $invoice->payment_claimed_at !== null;
    }

    /** @param  string  $method  a payment method key (PaymentMethods), e.g. iris */
    public function claim(Invoice $invoice, string $method): void
    {
        if (! self::canClaim($invoice)) {
            throw new InvalidArgumentException('This payment notice cannot take a payment claim.');
        }

        // Once, even when the button is pressed twice at the same moment
        $claimed = Invoice::query()->whereKey($invoice->id)->whereNull('payment_claimed_at')
            ->update(['payment_claimed_at' => now(), 'payment_claim_method' => $method]);

        if (! $claimed) {
            return;
        }

        $invoice->refresh();
        $amount = app(RecordInvoicePayment::class)->unpaid($invoice)->sum('total_cents');

        ActivityLog::record('invoice.payment_claimed', sprintf('The client says payment notice %s (%s) was paid by %s',
            $invoice->number, Money::format($amount, $invoice->currency), PaymentMethods::label($method)),
            client: $invoice->client, properties: ['invoice_id' => $invoice->id, 'method' => $method, 'amount_cents' => $amount]);

        $this->notifyAdmins($invoice);
    }

    /** The money did not arrive: the notice is unpaid as before and the client may say so again. */
    public function reject(Invoice $invoice): void
    {
        if ($invoice->payment_claimed_at === null) {
            return;
        }

        $method = $invoice->payment_claim_method;
        $invoice->update(['payment_claimed_at' => null, 'payment_claim_method' => null]);

        ActivityLog::record('invoice.payment_claim_rejected', sprintf('Payment claim on payment notice %s (%s) rejected: payment not found',
            $invoice->number, PaymentMethods::label($method)), client: $invoice->client, properties: ['invoice_id' => $invoice->id, 'method' => $method]);
    }

    private function notifyAdmins(Invoice $invoice): void
    {
        if (! config('gaiacp.automation.payment_claim_email')) {
            return;
        }

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Billing));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new PaymentClaimMail($invoice));
            } catch (Throwable $e) {
                Log::error('Payment claim email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Payment claim email to {$admin->email} failed: {$e->getMessage()}", client: $invoice->client);
            }
        }
    }
}
