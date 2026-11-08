<?php

namespace Tests\Feature;

use App\Actions\RecordPayment;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Tables\InvoiceBulkActions;
use App\Invoices\GenerateInvoices;
use App\Mail\ClientMail;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** Payment notices list → pick some → Mark as paid / Send reminder / Mark as unpaid / Cancel. */
class InvoiceBulkActionsTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        config(['gaiacp.invoices.days_before' => 14, 'gaiacp.tax.enabled' => false]);
        Mail::fake();
        $this->actingAs(User::factory()->create());
    }

    /** @return list<Invoice> one notice per service, due on these days */
    private function notices(string ...$dues): array
    {
        $services = collect($dues)->map(fn (string $due) => Service::factory()->create([
            'start_date' => CarbonImmutable::parse($due)->subYear(),
            'next_due_date' => $due,
        ]));

        foreach ($services->pluck('next_due_date')->unique() as $due) {
            app(GenerateInvoices::class)->handle(CarbonImmutable::parse($due)->subDays(14));
        }

        return Invoice::query()->orderBy('due_date')->get()->all();
    }

    public function test_mark_as_paid_pays_the_unpaid_ones_and_leaves_out_the_rest(): void
    {
        [$first, $second, $third] = $this->notices('2026-10-20', '2026-10-21', '2026-10-22');
        $third->cancel();

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('markPaid', [$first, $second, $third], ['paid_on' => '2026-10-19', 'method' => 'bank_transfer', 'reference' => 'BATCH-1'])
            ->assertNotified('2 payment notices marked paid');

        $this->assertSame(InvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $second->fresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $third->fresh()->status);
        $this->assertSame('2027-10-20', $first->items()->first()->service->next_due_date->toDateString());
        $this->assertSame('BATCH-1', $first->items()->first()->payment->reference);
    }

    public function test_send_reminder_picks_the_overdue_email_once_past_due(): void
    {
        [$past, $coming] = $this->notices(today()->subDays(3)->toDateString(), today()->addDays(5)->toDateString());

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('sendEmail', [$past, $coming], ['template' => 'by_due_date'])
            ->assertNotified('2 emails sent');

        $sent = Mail::sent(ClientMail::class)->map(fn (ClientMail $m) => $m->template())->values()->all();
        $this->assertEqualsCanonicalizing([MailTemplate::InvoiceFirstOverdue, MailTemplate::InvoicePaymentReminder], $sent);
        $this->assertNotNull($past->fresh()->emailed_at);
    }

    public function test_mark_as_unpaid_reverses_only_where_it_still_can(): void
    {
        [$first, $second] = $this->notices('2026-10-20', '2026-10-21');

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('markPaid', [$first, $second], ['paid_on' => '2026-10-19', 'method' => 'bank_transfer']);

        // A later payment on the second notice's service: its payment is no longer the latest
        $later = $second->items()->first()->service;
        app(RecordPayment::class)->handle($later->fresh(), $later->price_cents, CarbonImmutable::parse('2026-10-25'), 'bank_transfer');

        $this->assertNotNull(InvoiceBulkActions::whyNotReversible($second->fresh()->load('items')));

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('markUnpaid', [$first, $second])
            ->assertNotified('1 payment notice marked unpaid');

        $this->assertSame(InvoiceStatus::Unpaid, $first->fresh()->status);
        $this->assertSame('2026-10-20', $first->items()->first()->service->next_due_date->toDateString());
        $this->assertSame(InvoiceStatus::Paid, $second->fresh()->status);
    }

    public function test_cancel_cancels_the_unpaid_ones(): void
    {
        [$first, $second] = $this->notices('2026-10-20', '2026-10-21');

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('cancel', [$first, $second])
            ->assertNotified('2 payment notices cancelled');

        $this->assertSame(InvoiceStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $second->fresh()->status);
    }
}
