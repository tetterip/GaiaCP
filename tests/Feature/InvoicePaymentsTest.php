<?php

namespace Tests\Feature;

use App\Actions\RecordInvoicePayment;
use App\Actions\RecordPayment;
use App\Credit\AddFunds;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Invoices\GenerateInvoices;
use App\Invoices\NoticeLedger;
use App\Invoicing\IssueCreditNote;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\TaxInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** The Payments section of a payment notice and of its invoice: when, how and how much was paid, and what is still owed. */
class InvoicePaymentsTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00'));
        config([
            'gaiacp.invoicing' => ['enabled' => true, 'number_format' => 'INV-{YEAR}-{NUMBER}'],
            'gaiacp.tax' => ['enabled' => false],
        ]);
        $this->actingAs(User::factory()->create());
    }

    /** A notice with two services of 50.00 and 30.00, due 20/10/2026. */
    private function notice(): Invoice
    {
        $client = Client::factory()->create();
        Service::factory()->for($client)->create(['label' => 'First site', 'start_date' => '2025-10-20', 'next_due_date' => '2026-10-20', 'price_cents' => 5000]);
        Service::factory()->for($client)->create(['label' => 'Second site', 'start_date' => '2025-10-20', 'next_due_date' => '2026-10-20', 'price_cents' => 3000]);
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-09'));

        return Invoice::query()->where('client_id', $client->id)->sole();
    }

    public function test_an_unpaid_notice_shows_no_payments_and_its_whole_total_as_balance(): void
    {
        $notice = $this->notice();

        $this->assertSame([], NoticeLedger::rows($notice));
        $this->assertSame(8000, NoticeLedger::balanceCents($notice));

        Livewire::test(ViewInvoice::class, ['record' => $notice->id])
            ->assertSee('Balance due')
            ->assertSee('No payments yet');
    }

    public function test_a_payment_on_one_service_lowers_the_balance_and_is_listed(): void
    {
        $notice = $this->notice();
        $first = Service::query()->where('label', 'First site')->sole();

        app(RecordPayment::class)->handle($first, 5000, CarbonImmutable::parse('2026-10-05'), 'bank_transfer', 'TRX-778');

        $rows = NoticeLedger::rows($notice->refresh());
        $this->assertCount(1, $rows);
        $this->assertSame(['05/10/2026', 'First site', 'TRX-778', 5000], [$rows[0]['date'], $rows[0]['description'], $rows[0]['reference'], $rows[0]['amount_cents']]);
        $this->assertSame(3000, NoticeLedger::balanceCents($notice));
        $this->assertSame(5000, NoticeLedger::paidCents($notice));

        Livewire::test(ViewInvoice::class, ['record' => $notice->id])
            ->assertSee('05/10/2026')
            ->assertSee('TRX-778')
            ->assertSee('Bank transfer')
            ->assertDontSee('No payments yet');
    }

    public function test_the_invoice_page_shows_its_status_due_date_and_the_notice_payments(): void
    {
        $notice = $this->notice();
        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::parse('2026-10-07'), 'cash', 'R-1');

        $invoice = TaxInvoice::sole();
        $this->assertSame(0, NoticeLedger::balanceCents($notice->refresh()));
        $this->assertCount(2, NoticeLedger::rows($notice));
        $this->assertSame('Settled', $invoice->statusLabel());

        Livewire::test(ViewTaxInvoice::class, ['record' => $invoice->id])
            ->assertSee('Settled')
            ->assertSee('20/10/2026') // the notice's due date
            ->assertSee('07/10/2026')
            ->assertSee('First site')
            ->assertSee('Second site')
            ->assertSee('R-1');

        $first = $invoice->items()->orderBy('id')->firstOrFail();
        $note = app(IssueCreditNote::class)->handle($invoice, [$first->id], 'Ordered by mistake');
        $this->assertSame('Partly credited', $invoice->statusLabel());

        Livewire::test(ViewTaxInvoice::class, ['record' => $invoice->id])
            ->assertSee('Partly credited')
            ->assertSee($note->number)
            ->assertSee('Ordered by mistake');

        app(IssueCreditNote::class)->handle($invoice);
        $this->assertSame('Credited', $invoice->statusLabel());

        // A credit note has no payments of its own.
        Livewire::test(ViewTaxInvoice::class, ['record' => $note->id])
            ->assertSee('Credit note')
            ->assertDontSee('R-1');
    }

    public function test_add_funds_shows_the_deposit_into_the_balance(): void
    {
        config(['gaiacp.credit' => ['enabled' => true, 'min_deposit' => 10, 'max_deposit' => 300, 'max_balance' => 600, 'auto_apply' => false]]);
        $client = Client::factory()->create();
        Service::factory()->for($client)->create(['next_due_date' => '2027-06-01']); // an active service, so funds can be added
        $notice = app(AddFunds::class)->handle($client, 2500);

        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::parse('2026-10-08'), 'bank_transfer', 'DEP-9');

        $rows = NoticeLedger::rows($notice->refresh());
        $this->assertCount(1, $rows);
        $this->assertSame(['Funds added', 'DEP-9', 2500], [$rows[0]['type'], $rows[0]['reference'], $rows[0]['amount_cents']]);

        Livewire::test(ViewInvoice::class, ['record' => $notice->id])->assertSee('DEP-9')->assertSee('Funds added');
    }
}
