<?php

namespace Tests\Feature;

use App\Actions\AlreadyPaid;
use App\Actions\RecordInvoicePayment;
use App\Actions\RecordPayment;
use App\Credit\ClientCredit;
use App\Invoices\GenerateInvoices;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\FakesWhm;
use Tests\TestCase;

/**
 * Two payments of the same notice landing at once (two API calls, or the admin's Record payment and an API call): the
 * second read the notice and its lines before the first saved, so only the service's row lock can tell it the cycle
 * is paid already. It must never move the due date twice or record a second payment.
 */
class ConcurrentNoticePaymentTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00'));
        config(['gaiacp.tax' => ['enabled' => false]]);
        $this->actingAs(User::factory()->create());
    }

    private function notice(): Invoice
    {
        $client = Client::factory()->create();
        Service::factory()->for($client)->create(['label' => 'First site', 'start_date' => '2025-10-20', 'next_due_date' => '2026-10-20', 'price_cents' => 5000]);
        Service::factory()->for($client)->create(['label' => 'Second site', 'start_date' => '2025-10-20', 'next_due_date' => '2026-10-20', 'price_cents' => 3000]);
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-09'));

        return Invoice::query()->where('client_id', $client->id)->sole();
    }

    /** A recorder that sees the lines as they were before the other request saved (what a request in the race reads). */
    private function racing(Collection $snapshot): RecordInvoicePayment
    {
        return new class($snapshot, app(RecordPayment::class), app(ClientCredit::class)) extends RecordInvoicePayment
        {
            public function __construct(private Collection $snapshot, RecordPayment $payments, ClientCredit $credit)
            {
                parent::__construct($payments, $credit);
            }

            public function unpaid(Invoice $invoice): Collection
            {
                return $this->snapshot;
            }
        };
    }

    public function test_a_second_payment_that_read_the_notice_before_the_first_saved_is_refused(): void
    {
        $notice = $this->notice();
        $stale = $notice->fresh();
        $snapshot = app(RecordInvoicePayment::class)->unpaid($notice);

        app(RecordInvoicePayment::class)->handle($notice, CarbonImmutable::parse('2026-10-09'), 'bank_transfer', 'R1');

        try {
            $this->racing($snapshot)->handle($stale, CarbonImmutable::parse('2026-10-09'), 'bank_transfer', 'R1');
            $this->fail('The second payment was recorded.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is not unpaid', $e->getMessage());
        }

        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(['2027-10-20', '2027-10-20'], Service::query()->orderBy('id')->get()->map(fn (Service $s) => $s->next_due_date->toDateString())->all());
    }

    public function test_a_line_paid_on_its_service_meanwhile_is_skipped_and_the_rest_is_paid(): void
    {
        $notice = $this->notice();
        $snapshot = app(RecordInvoicePayment::class)->unpaid($notice);
        $first = Service::query()->where('label', 'First site')->sole();

        app(RecordPayment::class)->handle($first, 5000, CarbonImmutable::parse('2026-10-09'), 'bank_transfer', 'R1');

        $payments = $this->racing($snapshot)->handle($notice, CarbonImmutable::parse('2026-10-09'), 'bank_transfer', 'R2');

        $this->assertCount(1, $payments);
        $this->assertSame('2027-10-20', $first->fresh()->next_due_date->toDateString());
        $this->assertSame('paid', $notice->fresh()->status->value);
    }

    public function test_a_payment_for_a_cycle_no_longer_due_throws_already_paid(): void
    {
        $this->notice();
        $first = Service::query()->where('label', 'First site')->sole();

        $this->expectException(AlreadyPaid::class);

        app(RecordPayment::class)->handle($first, 5000, CarbonImmutable::parse('2026-10-09'), 'bank_transfer', forDueDate: CarbonImmutable::parse('2026-09-20'));
    }

    public function test_an_upgrade_line_is_paid_once(): void
    {
        $notice = $this->notice();
        $first = Service::query()->where('label', 'First site')->sole();
        $line = $notice->items()->create(['service_id' => $first->id, 'fee' => InvoiceItem::UPGRADE, 'description' => 'Upgrade', 'due_date' => '2026-10-09',
            'period_end' => '2026-10-20', 'amount_cents' => 500, 'tax_cents' => 0, 'total_cents' => 500]);
        $stale = $line->fresh();

        app(RecordInvoicePayment::class)->payUpgrade($line, 500, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->expectException(AlreadyPaid::class);

        app(RecordInvoicePayment::class)->payUpgrade($stale, 500, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');
    }
}
