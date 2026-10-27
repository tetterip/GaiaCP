<?php

namespace App\Credit;

use App\Actions\RecordInvoicePayment;
use App\Actions\RecordPayment;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pays payment notice lines from the client's credit. A payment pays one service's whole cycle (with its late fees),
 * so credit covers the lines it can pay in full, in order, and leaves the rest owed; the notice is paid once every
 * line is. Each line is a RecordPayment with method "credit" (due dates move, domains renew, hosting unsuspends as
 * for any payment) and an Applied line in the ledger. "Add funds" notices and notices in another currency than the
 * client's are never paid from credit.
 */
class ApplyCredit
{
    /** Registrar renewals that failed after a payment, by line description. */
    public array $renewalErrors = [];

    public function __construct(
        private ClientCredit $credit,
        private RecordPayment $payments,
        private RecordInvoicePayment $invoicePayments,
    ) {}

    /** What paying this notice from credit would take now, in cents: the lines the balance covers, in order. */
    public function coverable(Invoice $invoice, ?int $balance = null): int
    {
        if (! $this->applies($invoice)) {
            return 0;
        }

        $balance ??= (int) $invoice->client->fresh()->credit_cents;
        $total = 0;

        foreach ($this->lines($invoice) as $amount) {
            if ($amount > 0 && $total + $amount <= $balance) {
                $total += $amount;
            }
        }

        return $total;
    }

    /**
     * Pays what the balance covers on this notice.
     *
     * @return int the cents taken from the balance
     */
    public function toInvoice(Invoice $invoice, ?CarbonImmutable $today = null): int
    {
        $this->renewalErrors = [];

        if (! $this->applies($invoice)) {
            return 0;
        }

        $today ??= CarbonImmutable::today();
        $client = $invoice->client;
        $used = 0;

        foreach ($this->lines($invoice) as $itemId => $amount) {
            $item = InvoiceItem::with('service')->find($itemId);

            if ($amount <= 0 || $amount > (int) $client->fresh()->credit_cents) {
                continue;
            }

            $debit = $this->credit->record($client, CreditType::Applied, -$amount, "{$item->description}, payment notice {$invoice->number}", ['invoice_id' => $invoice->id]);

            try {
                $payment = $item->fee === InvoiceItem::UPGRADE
                    ? $this->invoicePayments->payUpgrade($item, $amount, $today, ClientCredit::METHOD, notes: "Payment notice {$invoice->number}")
                    : $this->payments->handle($item->service, $amount, $today, ClientCredit::METHOD, notes: "Payment notice {$invoice->number}");
            } catch (Throwable $e) {
                $this->credit->record($client, CreditType::Refund, $amount, "Payment failed: {$item->description}, payment notice {$invoice->number}", ['invoice_id' => $invoice->id]);

                throw $e;
            }

            $debit->update(['payment_id' => $payment->id]);
            $used += $amount;

            if ($this->payments->renewalError !== null) {
                $this->renewalErrors[$item->description] = $this->payments->renewalError;
            }
        }

        $invoice->refresh()->settle($today);

        return $used;
    }

    /**
     * Pays the client's unpaid notices from credit, oldest due date first.
     *
     * @return int the cents taken from the balance
     */
    public function forClient(Client $client, ?CarbonImmutable $today = null): int
    {
        $used = 0;

        $invoices = $client->invoices()->unpaid()->where('credit', false)->orderBy('due_date')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            if ((int) $client->fresh()->credit_cents <= 0) {
                break;
            }

            $used += $this->toInvoice($invoice->setRelation('client', $client), $today);
        }

        return $used;
    }

    /**
     * The daily run: every client with credit pays what it can (when Settings → Client credit says so).
     *
     * @return array{clients: int, cents: int} cents in the base currency
     */
    public function handle(CarbonImmutable $today, bool $dryRun = false): array
    {
        $result = ['clients' => 0, 'cents' => 0];

        if (! ClientCredit::autoApply()) {
            return $result;
        }

        foreach (Client::query()->where('credit_cents', '>', 0)->orderBy('id')->get() as $client) {
            if ($dryRun) {
                $cents = $client->invoices()->unpaid()->where('credit', false)->get()
                    ->reduce(function (int $carry, Invoice $invoice) use ($client) {
                        return $carry + $this->coverable($invoice->setRelation('client', $client), (int) $client->credit_cents - $carry);
                    }, 0);
            } else {
                try {
                    $cents = $this->forClient($client, $today);
                } catch (Throwable $e) {
                    report($e);

                    continue;
                }
            }

            if ($cents > 0) {
                $result['clients']++;
                $result['cents'] += Currencies::toBase($cents, $client->currencyCode());
            }
        }

        return $result;
    }

    private function applies(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Unpaid
            && ! $invoice->credit
            && strtoupper($invoice->currency) === $invoice->client->currencyCode();
    }

    /**
     * Each payable line (a service's cycle, with that cycle's late fees) and its amount, in order. Lines that no longer
     * match their service's due date are left out: paying them would pay another cycle.
     *
     * @return Collection<int, int> item id => cents
     */
    private function lines(Invoice $invoice): Collection
    {
        $items = $this->invoicePayments->unpaid($invoice)->where('credit', false);
        $items = $items->diff($this->invoicePayments->stale($items));
        $fees = $items->whereNotNull('fee')->where('fee', '!=', InvoiceItem::UPGRADE)->groupBy('service_id');

        // An upgrade's difference is a line of its own.
        return $items->filter(fn (InvoiceItem $item) => $item->fee === null || $item->fee === InvoiceItem::UPGRADE)->sortBy('id')
            ->mapWithKeys(fn (InvoiceItem $item) => [$item->id => $item->total_cents + ($item->fee === null ? (int) $fees->get($item->service_id)?->sum('total_cents') : 0)]);
    }
}
