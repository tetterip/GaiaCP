<?php

namespace Tests\Feature;

use App\Actions\PaymentClaims;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/** A client's "I've paid" on a payment notice (App\Actions\PaymentClaims). */
class PaymentClaimsTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $notice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $client = Client::factory()->create();
        $due = CarbonImmutable::today()->addDays(10);
        $service = Service::factory()->for($client)->create([
            'start_date' => $due->subYear(), 'next_due_date' => $due, 'billing_cycle' => BillingCycle::Annually, 'price_cents' => 6000,
        ]);
        $this->notice = Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $client->id, 'date' => today(), 'due_date' => $due,
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
        $this->notice->items()->create([
            'service_id' => $service->id, 'description' => 'Web hosting', 'due_date' => $due, 'period_end' => $due->addYear(),
            'amount_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
    }

    public function test_a_claim_waits_on_the_unpaid_notice_once(): void
    {
        app(PaymentClaims::class)->claim($this->notice, 'bank_transfer');

        $this->assertTrue(PaymentClaims::waiting($this->notice->refresh()));
        $this->assertFalse(PaymentClaims::canClaim($this->notice));
        $this->assertSame(1, ActivityLog::query()->where('event', 'invoice.payment_claimed')->count());
    }

    public function test_a_notice_paid_in_the_meantime_takes_no_claim(): void
    {
        // Paid by another request after this one read the notice
        Invoice::query()->whereKey($this->notice->id)->update(['status' => InvoiceStatus::Paid]);

        app(PaymentClaims::class)->claim($this->notice, 'bank_transfer');

        $this->assertNull($this->notice->refresh()->payment_claimed_at);
        $this->assertSame(0, ActivityLog::query()->where('event', 'invoice.payment_claimed')->count());
    }

    public function test_only_a_known_payment_method_can_be_claimed(): void
    {
        try {
            app(PaymentClaims::class)->claim($this->notice, "<b>wire</b>\nto me");
            $this->fail('An unknown method was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->assertNull($this->notice->refresh()->payment_claimed_at);
        $this->assertSame(0, ActivityLog::query()->where('event', 'invoice.payment_claimed')->count());
    }
}
