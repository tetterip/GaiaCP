<?php

namespace Tests\Feature;

use App\Actions\RecordInvoicePayment;
use App\Actions\RecordPayment;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Invoices\Pages\ListInvoices as ClientListInvoices;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Filament\Clusters\Settings\Pages\InvoiceSettings;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\TaxInvoicesRelationManager;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Invoices\GenerateInvoices;
use App\Invoicing\InvoiceNumbers;
use App\Invoicing\InvoicePdf;
use App\Invoicing\IssueInvoice;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use Tests\FakesWhm;
use Tests\TestCase;

/** Settings → Invoices: a paid payment notice gets an invoice with its own number, never changed after. */
class TaxInvoicesTest extends TestCase
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
            'gaiacp.tax' => ['enabled' => true, 'inclusive' => false, 'rules' => [['name' => 'VAT', 'rate' => 24, 'country' => null]]],
            'gaiacp.company.name' => 'Acme Hosting',
            'gaiacp.company.vat_number' => 'EL123456789',
        ]);
    }

    private function notice(?Client $client = null, int $price = 10000): Invoice
    {
        $client ??= Client::factory()->create();
        Service::factory()->for($client)->create(['start_date' => '2025-10-20', 'next_due_date' => '2026-10-20', 'price_cents' => $price]);
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-09'));

        return Invoice::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
    }

    public function test_paying_a_notice_issues_its_invoice(): void
    {
        $notice = $this->notice();
        $this->assertSame(0, TaxInvoice::count());

        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::parse('2026-10-08'), 'bank_transfer');

        $invoice = TaxInvoice::sole();
        $this->assertSame('INV-2026-0001', $invoice->number);
        $this->assertSame($notice->id, $invoice->invoice_id);
        $this->assertSame('2026-10-09', $invoice->date->toDateString()); // the day it is issued
        $this->assertSame('2026-10-08', $invoice->paid_on->toDateString());
        $this->assertSame([10000, 2400, 12400], [$invoice->subtotal_cents, $invoice->tax_cents, $invoice->total_cents]);
        $this->assertSame('Acme Hosting', $invoice->seller['name']);
        $this->assertSame($notice->client->last_name, $invoice->bill_to['last_name']);
        $this->assertSame('2026-10-20', $invoice->items->sole()->period_start->toDateString());
        $this->assertSame('2027-10-19', $invoice->items->sole()->period_end->toDateString());
        $this->assertNotNull($invoice->payment_methods);
        $this->assertSame($invoice->id, $notice->fresh()->taxInvoice->id);
    }

    public function test_paying_the_service_directly_issues_it_too_and_only_once(): void
    {
        $notice = $this->notice();
        $service = $notice->items->sole()->service;

        app(RecordPayment::class)->handle($service, 12400, CarbonImmutable::parse('2026-10-09'), 'cash');
        app(IssueInvoice::class)->handle($notice->fresh());

        $this->assertSame(InvoiceStatus::Paid, $notice->fresh()->status);
        $this->assertSame(1, TaxInvoice::count());
    }

    public function test_numbers_follow_the_format_and_start_again_each_year(): void
    {
        foreach ([1, 2] as $i) {
            app(RecordInvoicePayment::class)->handle($this->notice(), CarbonImmutable::parse('2026-10-09'), 'cash');
        }
        $this->assertSame(['INV-2026-0001', 'INV-2026-0002'], TaxInvoice::query()->orderBy('id')->pluck('number')->all());

        $this->assertSame(1, InvoiceNumbers::upcoming(CarbonImmutable::parse('2027-01-01')));
        config(['gaiacp.invoicing.number_format' => 'A {NUMBER}']);
        $this->assertSame(1, InvoiceNumbers::upcoming(today())); // a series of its own
        $this->assertSame('A 0001', InvoiceNumbers::take(today()));
    }

    public function test_the_next_number_only_goes_up(): void
    {
        app(RecordInvoicePayment::class)->handle($this->notice(), CarbonImmutable::parse('2026-10-09'), 'cash');

        $this->assertTrue(InvoiceNumbers::continueFrom(50, today()));
        $this->assertFalse(InvoiceNumbers::continueFrom(10, today()));
        $this->assertSame(50, InvoiceNumbers::upcoming(today()));
        $this->assertSame('INV-2026-0050', InvoiceNumbers::take(today()));
    }

    public function test_no_invoice_while_off_nor_for_add_funds(): void
    {
        config(['gaiacp.invoicing.enabled' => false]);
        app(RecordInvoicePayment::class)->handle($this->notice(), CarbonImmutable::parse('2026-10-09'), 'cash');
        $this->assertSame(0, TaxInvoice::count());

        $funds = Invoice::create([
            'number' => 'PF-2026-0099', 'client_id' => Client::factory()->create()->id, 'status' => InvoiceStatus::Paid, 'date' => today(),
            'due_date' => today(), 'currency' => 'EUR', 'subtotal_cents' => 5000, 'total_cents' => 5000, 'credit' => true, 'paid_on' => today(),
        ]);
        config(['gaiacp.invoicing.enabled' => true]);
        $this->assertNull(app(IssueInvoice::class)->handle($funds));
    }

    public function test_an_invoice_cannot_be_changed_or_deleted(): void
    {
        app(RecordInvoicePayment::class)->handle($this->notice(), CarbonImmutable::parse('2026-10-09'), 'cash');
        $invoice = TaxInvoice::sole();

        try {
            $invoice->update(['total_cents' => 1]);
            $this->fail('An invoice was changed');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $invoice->delete();
    }

    public function test_admin_pages_and_issue_by_hand(): void
    {
        $this->actingAs(User::factory()->create());
        config(['gaiacp.invoicing.enabled' => false]);
        $notice = $this->notice();
        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::parse('2026-10-09'), 'cash');
        config(['gaiacp.invoicing.enabled' => true]);

        Livewire::test(ViewInvoice::class, ['record' => $notice->id])
            ->assertActionVisible('issueInvoice')
            ->callAction('issueInvoice');

        $invoice = TaxInvoice::sole();
        Livewire::test(ListTaxInvoices::class)->assertCanSeeTableRecords([$invoice]);
        Livewire::test(ViewTaxInvoice::class, ['record' => $invoice->id])->assertSee('INV-2026-0001')->assertSee('Acme Hosting')
            ->callAction('downloadPdf')->assertFileDownloaded('INV-2026-0001.pdf')
            ->callAction('sendTaxInvoice');
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::InvoiceIssued, 2);
        Livewire::test(ViewInvoice::class, ['record' => $notice->id])->assertActionHidden('issueInvoice')->assertSee('INV-2026-0001');

        // The client page's Invoices tab, with the PDF on each row
        $this->assertTrue(TaxInvoicesRelationManager::canViewForRecord($notice->client, EditClient::class));
        Livewire::test(TaxInvoicesRelationManager::class, ['ownerRecord' => $notice->client, 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords([$invoice])
            ->callTableAction('downloadPdf', $invoice)
            ->assertFileDownloaded('INV-2026-0001.pdf');

        config(['gaiacp.invoicing.enabled' => false]);
        $this->assertTrue(TaxInvoicesRelationManager::canViewForRecord($notice->client, EditClient::class)); // it has one
        $this->assertFalse(TaxInvoicesRelationManager::canViewForRecord(Client::factory()->create(), EditClient::class));
    }

    public function test_the_client_gets_the_invoice_issued_email_with_its_pdf(): void
    {
        app(RecordInvoicePayment::class)->handle($this->notice(), CarbonImmutable::parse('2026-10-09'), 'cash');

        Mail::assertSent(ClientMail::class, function (ClientMail $mail) {
            return $mail->template() === MailTemplate::InvoiceIssued
                && $mail->files[0]['name'] === 'INV-2026-0001.pdf'
                && str_starts_with($mail->files[0]['data'], '%PDF')
                && str_contains($mail->envelope()->subject, 'INV-2026-0001');
        });

        config(['gaiacp.invoicing.enabled' => false]);
        app(RecordInvoicePayment::class)->handle($this->notice(), CarbonImmutable::parse('2026-10-09'), 'cash');
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::InvoiceIssued, 1);
    }

    public function test_the_pdf_is_in_the_clients_language(): void
    {
        $client = Client::factory()->create(['locale' => 'el']);
        app(RecordInvoicePayment::class)->handle($this->notice($client), CarbonImmutable::parse('2026-10-09'), 'cash');

        $html = Locales::using('el', fn () => view('invoices.pdf', ['invoice' => TaxInvoice::sole()->load('items', 'notice'), 'logo' => null])->render());
        $this->assertStringContainsString('Τιμολόγιο', $html);
        $this->assertStringContainsString('EL123456789', $html);
        $this->assertStringStartsWith('%PDF', InvoicePdf::content(TaxInvoice::sole()));
    }

    public function test_clients_see_and_download_only_their_own_invoices(): void
    {
        $client = Client::factory()->create();
        app(RecordInvoicePayment::class)->handle($mine = $this->notice($client), CarbonImmutable::parse('2026-10-09'), 'cash');
        app(RecordInvoicePayment::class)->handle($this->notice(), CarbonImmutable::parse('2026-10-09'), 'cash');
        [$own, $other] = TaxInvoice::query()->orderBy('id')->get()->all();

        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');
        Filament::setCurrentPanel('client');

        Livewire::test(ClientListInvoices::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other])
            ->callTableAction('downloadPdf', $own)
            ->assertFileDownloaded('INV-2026-0001.pdf');

        Livewire::test(ViewPaymentNotice::class, ['record' => $mine->id])
            ->assertActionVisible('downloadInvoice')
            ->callAction('downloadInvoice')
            ->assertFileDownloaded('INV-2026-0001.pdf');
    }

    public function test_settings_page_saves_the_format_and_the_next_number(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(InvoiceSettings::class)
            ->fillForm(['invoicing' => ['enabled' => true, 'number_format' => 'no tag', 'next_number' => 1]])
            ->call('save')
            ->assertHasFormErrors(['invoicing.number_format']);

        Livewire::test(InvoiceSettings::class)
            ->fillForm(['invoicing' => ['enabled' => true, 'number_format' => 'F{YEAR}/{NUMBER}', 'next_number' => 120]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('F{YEAR}/{NUMBER}', config('gaiacp.invoicing.number_format'));
        $this->assertSame('F2026/0120', InvoiceNumbers::take(today()));
    }
}
