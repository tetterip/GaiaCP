<?php

namespace Tests\Feature;

use App\Actions\RecordInvoicePayment;
use App\Actions\ReversePayment;
use App\Enums\InvoiceStatus;
use App\Events\GaiaEvent;
use App\Filament\Clusters\Settings\Pages\InvoiceSettings;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Invoices\GenerateInvoices;
use App\Invoicing\IssueCreditNote;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\TaxInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\FakesWhm;
use Tests\TestCase;

/** Credit notes: the only way to undo an invoice, by hand or when the payment of its notice is reversed. */
class CreditNotesTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00'));
        config([
            'gaiacp.invoicing' => ['enabled' => true, 'number_format' => 'INV-{YEAR}-{NUMBER}', 'credit_note_format' => 'CN-{YEAR}-{NUMBER}'],
            'gaiacp.tax' => ['enabled' => true, 'inclusive' => false, 'rules' => [['name' => 'VAT', 'rate' => 24, 'country' => null]]],
        ]);
    }

    /** A paid notice of two services (100.00 and 50.00 before tax) and its invoice. */
    private function invoice(): TaxInvoice
    {
        $client = Client::factory()->create();
        foreach ([10000, 5000] as $price) {
            Service::factory()->for($client)->create(['start_date' => '2025-10-20', 'next_due_date' => '2026-10-20', 'price_cents' => $price]);
        }
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-09'));
        app(RecordInvoicePayment::class)->handle(Invoice::query()->where('client_id', $client->id)->sole(), CarbonImmutable::parse('2026-10-09'), 'cash');

        return TaxInvoice::sole();
    }

    public function test_an_admin_credits_chosen_lines_then_the_rest(): void
    {
        $this->actingAs(User::factory()->create());
        $invoice = $this->invoice();
        [$first, $second] = $invoice->items()->orderBy('id')->get()->all();

        Livewire::test(ViewTaxInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('issueCreditNote')
            ->callAction('issueCreditNote', ['items' => [$second->id], 'reason' => 'Ordered by mistake'])
            ->assertHasNoActionErrors();

        $note = TaxInvoice::query()->where('type', TaxInvoice::CREDIT_NOTE)->sole();
        $this->assertSame('CN-2026-0001', $note->number);
        $this->assertSame($invoice->id, $note->credited_invoice_id);
        $this->assertSame([5000, 1200, 6200], [$note->subtotal_cents, $note->tax_cents, $note->total_cents]);
        $this->assertSame([$second->id], $note->items->pluck('credited_item_id')->all());
        $this->assertSame('Ordered by mistake', $note->reason);
        $this->assertSame(-6200, $note->signedTotalCents());
        $this->assertSame([$first->id], $invoice->creditableItems()->pluck('id')->all());
        $this->assertFalse($invoice->fullyCredited());

        Livewire::test(ViewTaxInvoice::class, ['record' => $note->id])
            ->assertSee('Credit note CN-2026-0001')
            ->assertSee($invoice->number)
            ->assertActionHidden('issueCreditNote')
            ->assertActionHidden('sendTaxInvoice')
            ->callAction('downloadPdf')->assertFileDownloaded('CN-2026-0001.pdf');

        app(IssueCreditNote::class)->handle($invoice);
        $this->assertSame('CN-2026-0002', TaxInvoice::query()->latest('id')->value('number'));
        $this->assertTrue($invoice->fullyCredited());
        Livewire::test(ViewTaxInvoice::class, ['record' => $invoice->id])->assertActionHidden('issueCreditNote')->assertSee('CN-2026-0002');

        $this->expectException(RuntimeException::class);
        app(IssueCreditNote::class)->handle($invoice);
    }

    public function test_reversing_the_payment_credits_the_invoice_and_paying_again_issues_a_new_one(): void
    {
        $invoice = $this->invoice();
        $notice = $invoice->notice;

        app(ReversePayment::class)->handle(Payment::query()->latest('id')->first());

        $this->assertSame(InvoiceStatus::Unpaid, $notice->fresh()->status);
        $note = TaxInvoice::query()->where('type', TaxInvoice::CREDIT_NOTE)->sole();
        $this->assertSame(IssueCreditNote::PAYMENT_REVERSED, $note->reason);
        $this->assertSame(18600, $note->total_cents); // the whole invoice: the notice is owed again and gets a new one
        $this->assertTrue($invoice->fullyCredited());

        app(RecordInvoicePayment::class)->handle($notice->fresh(), CarbonImmutable::parse('2026-10-09'), 'cash');
        $this->assertSame('INV-2026-0002', $notice->fresh()->taxInvoice->number);
        $this->assertSame(2, TaxInvoice::query()->where('type', TaxInvoice::INVOICE)->count());
    }

    public function test_the_pdf_and_the_csv_show_credit_notes_negative(): void
    {
        $this->actingAs(User::factory()->create());
        $invoice = $this->invoice();
        $note = app(IssueCreditNote::class)->handle($invoice, reason: 'Ordered by mistake');

        $html = view('invoices.pdf', ['invoice' => $note->load('items', 'notice', 'creditedInvoice'), 'logo' => null])->render();
        $this->assertStringContainsString('Credit note', $html);
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('-€186.00', $html);
        $this->assertStringContainsString('Ordered by mistake', $html);

        $list = Livewire::test(ListTaxInvoices::class)
            ->assertCanSeeTableRecords([$invoice, $note])
            ->filterTable('type', TaxInvoice::CREDIT_NOTE)
            ->assertCanSeeTableRecords([$note])
            ->assertCanNotSeeTableRecords([$invoice])
            ->removeTableFilters();

        ob_start();
        $list->instance()->csv()->sendContent();
        $lines = array_map('str_getcsv', array_slice(explode("\n", trim(ob_get_clean())), 1));
        $totals = array_column($lines, 14, 1);
        $this->assertSame(['CN-2026-0001' => '-186.00', 'INV-2026-0001' => '186.00'], $totals);
    }

    public function test_plugins_hear_of_invoices_and_credit_notes(): void
    {
        $invoice = $this->invoice();
        $note = app(IssueCreditNote::class)->handle($invoice);

        $log = ActivityLog::query()->where('event', 'credit_note.issued')->sole();
        $payload = (new GaiaEvent('credit_note.issued', $log))->payload();
        $this->assertSame(['credit_note', $note->number, $invoice->number, 18600], [
            $payload['tax_invoice']['type'], $payload['tax_invoice']['number'], $payload['tax_invoice']['credits'], $payload['tax_invoice']['total_cents'],
        ]);
        $this->assertSame($invoice->notice->number, $payload['invoice']['number']);

        $this->assertContains('tax_invoice.issued', GaiaEvent::NAMES);
        $this->assertSame($invoice->id, ActivityLog::query()->where('event', 'tax_invoice.issued')->sole()->properties['tax_invoice_id']);
    }

    public function test_settings_save_the_credit_note_format(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(InvoiceSettings::class)
            ->fillForm(['invoicing' => ['enabled' => true, 'number_format' => 'INV-{NUMBER}', 'credit_note_format' => 'PIS-{NUMBER}', 'next_number' => 1]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('PIS-{NUMBER}', config('gaiacp.invoicing.credit_note_format'));
        $this->assertSame('PIS-0001', app(IssueCreditNote::class)->handle($this->invoice())->number);
    }
}
