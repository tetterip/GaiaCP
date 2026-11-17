<?php

namespace Tests\Feature;

use App\Actions\PaymentClaims;
use App\Actions\RecordInvoicePayment;
use App\Enums\BillingCycle;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Filament\Clusters\Settings\Pages\PaymentMethodsSettings;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Mail\PaymentClaimMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Payments\PaymentMethods;
use App\Plugins\PluginManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Livewire\Livewire;
use Plugins\Iris\IrisPlugin;
use Tests\TestCase;

/** Choosing how to pay a notice in the client area ("Pay online"), and the client's "I've paid" (IRIS). */
class PaymentClaimsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $notice;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.iris' => ['payee' => 'Gaia Hosting', 'number' => '6912345678', 'qr' => null, 'note' => null]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('iris'));

        $this->admin = User::factory()->create(['email' => 'admin@example.gr', 'system_emails' => true, 'panel_notifications' => true]);
        $this->client = Client::factory()->create(['email' => 'maria@example.gr']);
        $due = CarbonImmutable::today()->addDays(10);
        $service = Service::factory()->for($this->client)->create([
            'label' => 'Web hosting maria.gr', 'start_date' => $due->subYear(), 'next_due_date' => $due,
            'billing_cycle' => BillingCycle::Annually, 'price_cents' => 6000,
        ]);
        $this->notice = Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $this->client->id, 'date' => today(), 'due_date' => $due,
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
        $this->notice->items()->create([
            'service_id' => $service->id, 'description' => 'Web hosting maria.gr', 'due_date' => $due, 'period_end' => $due->addYear(),
            'amount_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
    }

    private function actAsClient(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    private function enableStripe(?string $key = 'sk_test_123'): void
    {
        config(['gaiacp.stripe.mode' => 'test', 'gaiacp.stripe.test_secret_key' => $key]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('stripe'));
    }

    public function test_one_way_to_pay_is_its_own_button_and_several_are_one_pay_online_menu(): void
    {
        $this->actAsClient();
        $url = "/payment-notices/{$this->notice->id}";

        $this->get($url)->assertOk()->assertSee('Pay with IRIS')->assertDontSee('Pay online');
        $this->get('/help')->assertOk()->assertDontSee('Pay online');

        $this->enableStripe();
        $this->get($url)->assertOk()->assertSeeInOrder(['Pay online', 'Pay by card', 'Pay with IRIS']);
        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->assertActionVisible('payByCard')
            ->assertActionVisible('payWithIris');

        $this->get('/help')->assertOk()->assertSee('it has one <strong>Pay online</strong> button', false);

        // Stripe on without its key offers no button: back to IRIS alone
        $this->enableStripe(null);
        $this->get($url)->assertOk()->assertSee('Pay with IRIS')->assertDontSee('Pay online');
    }

    public function test_the_client_says_they_paid_by_iris_and_the_admins_hear_of_it_once(): void
    {
        $this->actAsClient();

        Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()])
            ->mountAction('payWithIris')
            ->callMountedAction()
            ->assertNotified('Thank you, we will check for your payment');

        $notice = $this->notice->fresh();
        $this->assertNotNull($notice->payment_claimed_at);
        $this->assertSame('iris', $notice->payment_claim_method);
        $this->assertSame('unpaid', $notice->status->value); // nothing is recorded
        $this->assertSame(0, Payment::query()->count());

        $log = ActivityLog::query()->where('event', 'invoice.payment_claimed')->sole();
        $this->assertSame(6000, $log->properties['amount_cents']);
        Mail::assertSent(PaymentClaimMail::class, fn (PaymentClaimMail $mail) => $mail->hasTo('admin@example.gr'));
        $this->assertSame(1, $this->admin->notifications()->count()); // the bell
        $this->assertStringContainsString($notice->number, $this->admin->notifications()->first()->data['title']);

        // Shown to the client, and the button does not come back
        $this->get("/payment-notices/{$notice->id}")->assertSee('You told us on');
        $this->assertFalse(PaymentClaims::canClaim($notice));
        $this->assertStringContainsString('You told us on', IrisPlugin::details($notice)->toHtml());

        $this->expectException(InvalidArgumentException::class);
        app(PaymentClaims::class)->claim($notice, 'iris');
    }

    public function test_the_window_asks_for_i_have_paid_until_it_is_pressed(): void
    {
        $this->assertStringContainsString('Once you have paid, press I&#039;ve paid', IrisPlugin::details($this->notice)->toHtml());
    }

    public function test_no_email_when_the_setting_is_off(): void
    {
        config(['gaiacp.automation.payment_claim_email' => false]);

        app(PaymentClaims::class)->claim($this->notice, 'iris');

        Mail::assertNothingSent();
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_the_admin_sees_the_claim_and_records_the_payment_with_iris_already_picked(): void
    {
        app(PaymentClaims::class)->claim($this->notice, 'iris');
        $this->actingAs($this->admin);

        Livewire::test(ViewInvoice::class, ['record' => $this->notice->getRouteKey()])
            ->assertSee('Client says paid')
            ->assertActionVisible('rejectPaymentClaim')
            ->mountAction('recordInvoicePayment')
            ->assertSchemaStateSet(['method' => 'iris'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $notice = $this->notice->fresh();
        $this->assertSame('paid', $notice->status->value);
        $this->assertNull($notice->payment_claimed_at);
        $this->assertSame('iris', Payment::query()->sole()->method);
    }

    public function test_a_rejected_claim_goes_away_and_can_be_made_again(): void
    {
        app(PaymentClaims::class)->claim($this->notice, 'iris');
        $this->actingAs($this->admin);

        Livewire::test(ViewInvoice::class, ['record' => $this->notice->getRouteKey()])
            ->callAction('rejectPaymentClaim')
            ->assertNotified('Claim rejected');

        $notice = $this->notice->fresh();
        $this->assertNull($notice->payment_claimed_at);
        $this->assertSame('unpaid', $notice->status->value);
        $this->assertTrue(ActivityLog::query()->where('event', 'invoice.payment_claim_rejected')->exists());
        $this->assertTrue(PaymentClaims::canClaim($notice));
    }

    public function test_a_paid_notice_takes_no_claim(): void
    {
        app(RecordInvoicePayment::class)->handle($this->notice, CarbonImmutable::today(), 'iris');

        $this->assertFalse(PaymentClaims::canClaim($this->notice->fresh()));
    }

    public function test_payment_methods_marks_a_method_that_is_on_but_not_set_up(): void
    {
        $this->actingAs($this->admin);
        $this->enableStripe(null);

        $this->get(PaymentMethodsSettings::getUrl())->assertOk()->assertSee('Not set up');

        $this->enableStripe();
        $this->get(PaymentMethodsSettings::getUrl())->assertOk()->assertDontSee('Not set up');
    }

    public function test_the_instructions_point_to_pay_online_once_there_are_several_ways(): void
    {
        $this->enableStripe();
        config(['gaiacp.iris.number' => null]); // Stripe alone: its own line

        $this->assertStringContainsString('press Pay by card', PaymentMethods::instructions());

        config(['gaiacp.iris.number' => '6912345678']);
        $instructions = PaymentMethods::instructions();
        $this->assertStringContainsString('press Pay online and choose how to pay', $instructions);
        $this->assertStringNotContainsString('press Pay by card', $instructions);
        $this->assertStringContainsString('6912345678', $instructions); // IRIS keeps its details
    }
}
