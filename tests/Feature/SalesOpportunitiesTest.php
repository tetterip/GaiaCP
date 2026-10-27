<?php

namespace Tests\Feature;

use App\Enums\DomainRegistrationType;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Filament\Clusters\Reports\Pages\SalesOpportunities as SalesOpportunitiesPage;
use App\Filament\Resources\Clients\Widgets\ClientSummary;
use App\Mail\ClientMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Reports\ClientHealth;
use App\Reports\SalesOpportunities;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Client health (App\Reports\ClientHealth) and Reports → Sales opportunities (App\Reports\SalesOpportunities). */
class SalesOpportunitiesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-23'));
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.com']);
        $this->actingAs(User::factory()->create());
    }

    private function domain(string $name, array $attributes = [], DomainRegistrationType $type = DomainRegistrationType::Register, ?string $expires = null): Service
    {
        $service = Service::factory()->domain()->for($this->client)->create(['domain' => $name] + $attributes);
        $service->registration()->update(['registration_type' => $type, 'expires_at' => $expires]);

        return $service->fresh();
    }

    private function pay(string $due, string $paid, int $cents = 6000): void
    {
        Payment::create([
            'client_id' => $this->client->id, 'service_id' => Service::factory()->for($this->client)->create()->id, 'amount_cents' => $cents,
            'currency' => 'EUR', 'paid_on' => $paid, 'method' => 'bank', 'due_date_before' => $due, 'due_date_after' => CarbonImmutable::parse($due)->addYear(),
        ]);
    }

    public function test_the_health_card_rates_how_the_client_pays(): void
    {
        $this->assertNull(ClientHealth::for($this->client)['rating'], 'nothing to judge by');

        $this->pay('2026-03-01', '2026-02-25');            // early: 0
        $this->pay('2026-06-01', '2026-06-03');            // 2 days late
        $this->pay('2024-06-01', '2024-09-01', 10000);     // over a year ago: income only

        $health = ClientHealth::for($this->client);
        $this->assertSame('on_time', $health['rating']);
        $this->assertSame(1, $health['averageDays']);
        $this->assertSame(1, $health['late']);
        $this->assertSame('€220.00', $health['income']);
        $this->assertSame('€120.00', $health['incomeYear']);

        // A notice 40 days overdue and a suspension: (0 + 2 + 40) / 3 = 14 → watch
        Invoice::create([
            'number' => 'PF-2026-0001', 'client_id' => $this->client->id, 'date' => '2026-09-01', 'due_date' => '2026-09-13',
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
        ActivityLog::record('service.suspended', 'Suspended: Overdue on payment', $this->client->services()->first());

        $health = ClientHealth::for($this->client);
        $this->assertSame('watch', $health['rating']);
        $this->assertSame(14, $health['averageDays']);
        $this->assertSame(2, $health['late']);
        $this->assertSame(1, $health['suspensions']);

        Livewire::test(ClientSummary::class, ['record' => $this->client])
            ->assertSee(__('Payment record'))
            ->assertSee(__('Watch'))
            ->assertSee('€220.00');
    }

    public function test_the_three_kinds_of_opportunity(): void
    {
        Tld::query()->create(['tld' => '.gr', 'renew_2y_cents' => 3000, 'transfer_2y_cents' => 1200, 'grace_days' => 15, 'grace_fee_cents' => 1000]);

        $tracked = $this->domain('elsewhere.gr', type: DomainRegistrationType::External);
        $hostingOnly = Service::factory()->for($this->client)->create(['domain' => 'www.site-only.gr']);
        $bare = $this->domain('bare.gr');
        $hosted = $this->domain('hosted.gr');
        Service::factory()->for($this->client)->create(['domain' => 'hosted.gr']);
        $expired = $this->domain('lapsed.gr', ['status' => ServiceStatus::Cancelled, 'price_cents' => 3000, 'next_due_date' => '2026-10-15'], expires: '2026-10-15');
        $tooLate = $this->domain('gone.gr', ['status' => ServiceStatus::Cancelled, 'next_due_date' => '2026-09-01'], expires: '2026-09-01');
        $reordered = $this->domain('back.gr', ['status' => ServiceStatus::Cancelled, 'next_due_date' => '2026-10-20'], expires: '2026-10-20');
        $this->domain('back.gr');

        $this->assertEqualsCanonicalizing([$tracked->id, $hostingOnly->id], SalesOpportunities::ids('transfer'));
        $this->assertContains($bare->id, SalesOpportunities::ids('hosting'));
        $this->assertNotContains($hosted->id, SalesOpportunities::ids('hosting'));
        $this->assertNotContains($tracked->id, SalesOpportunities::ids('hosting'));
        $this->assertSame([$expired->id], SalesOpportunities::ids('winback'), 'past grace or ordered again: left out');
        $this->assertNotContains($tooLate->id, SalesOpportunities::ids('winback'));
        $this->assertNotContains($reordered->id, SalesOpportunities::ids('winback'));

        $this->assertSame('€12.00', SalesOpportunities::values('transfer', $tracked)['transfer_price']);
        $this->assertSame(['domain' => 'lapsed.gr', 'expiry_date' => '15/10/2026', 'renewal_price' => '€40.00'], SalesOpportunities::values('winback', $expired));
        $this->assertSame(__('Expired on :date, recoverable until :last', ['date' => '15/10/2026', 'last' => '30/10/2026']), SalesOpportunities::detail('winback', $expired));
    }

    public function test_the_page_sends_the_edited_email_and_shows_when(): void
    {
        $bare = $this->domain('bare.gr');

        Livewire::test(SalesOpportunitiesPage::class)
            ->assertOk()
            ->assertSee(__('Transfer to us'))
            ->set('activeTab', 'hosting')
            ->assertCanSeeTableRecords([$bare])
            ->mountAction(TestAction::make('email')->table($bare))
            ->assertActionDataSet(['subject' => MailTemplate::HostingOffer->defaultSubject()])
            ->setActionData(['subject' => 'Hosting for {$domain}?', 'body' => 'Hi {$client_first_name}, a site for {$domain}?'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('Sent to :email', ['email' => 'maria@example.com']))
            ->assertSee('23/10/2026');

        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::HostingOffer
            && $mail->envelope()->subject === 'Hosting for bare.gr?'
            && str_contains($mail->render(), 'Hi Maria, a site for bare.gr?'));
        $this->assertTrue(ActivityLog::query()->where('event', 'email.sent')->where('service_id', $bare->id)->exists());
    }
}
