<?php

namespace Tests\Feature;

use App\Actions\CancellationRequests;
use App\Enums\CancellationType;
use App\Enums\ChangeRequestStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Filament\Resources\CancellationRequests\Pages\ListCancellationRequests;
use App\Mail\CancellationRequestMail;
use App\Mail\ClientMail;
use App\Models\ActivityLog;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** A client asks to cancel a service from the client area; an admin approves or declines it (App\Actions\CancellationRequests). */
class CancellationRequestsTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Client $client;

    private User $user;

    private User $admin;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeWhm();
        $this->travelTo(CarbonImmutable::parse('2026-10-08'));

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->hosting = Service::factory()->for($this->client)->create([
            'label' => 'Starter', 'domain' => 'maria.gr', 'username' => 'maria', 'start_date' => '2025-12-01', 'next_due_date' => '2026-12-01',
        ]);
        $this->whmAccounts['maria'] = false;
    }

    private function clientPage(?Service $service = null): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(ViewService::class, ['record' => ($service ?? $this->hosting)->getRouteKey()]);
    }

    private function adminList(): Testable
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        return Livewire::test(ListCancellationRequests::class);
    }

    private function domain(array $attributes = []): Service
    {
        return Service::factory()->domain()->for($this->client)->create($attributes + [
            'domain' => 'maria.gr', 'start_date' => '2025-11-20', 'next_due_date' => '2026-11-20', 'price_cents' => 1500,
        ]);
    }

    public function test_the_client_asks_to_cancel_at_the_end_of_the_period_with_the_domain(): void
    {
        $domain = $this->domain();

        $this->clientPage()
            ->assertActionVisible('requestCancellation')
            ->assertActionHidden('withdrawCancellation')
            ->callAction('requestCancellation', ['reason' => 'Moving to our own server', 'type' => 'end_of_period', 'with_domain' => true])
            ->assertNotified(__('Cancellation request sent'));

        $request = CancellationRequest::sole();
        $this->assertSame(CancellationType::EndOfPeriod, $request->type);
        $this->assertSame('Moving to our own server', $request->reason);
        $this->assertTrue($request->domainService->is($domain));
        $this->assertSame(ChangeRequestStatus::Pending, $request->status);

        // Nothing changes until an admin approves
        $this->assertFalse($this->hosting->fresh()->auto_terminate);
        $this->assertSame(1, ActivityLog::query()->where('event', 'service.cancellation_requested')->count());

        Mail::assertSent(CancellationRequestMail::class, fn (CancellationRequestMail $mail) => $mail->hasTo('admin@example.com'));
        $this->assertStringContainsString('Cancellation request: maria.gr', $this->admin->notifications()->first()->data['title']);

        // Once asked, the page shows Withdraw instead
        $this->clientPage()
            ->assertActionHidden('requestCancellation')
            ->assertActionVisible('withdrawCancellation')
            ->assertSee(__('Cancellation requested'));
    }

    public function test_the_admin_email_follows_its_setting(): void
    {
        config(['gaiacp.automation.cancellation_request_email' => false]);

        app(CancellationRequests::class)->request($this->hosting, CancellationType::Immediate, null);

        Mail::assertNotSent(CancellationRequestMail::class);
    }

    public function test_the_client_withdraws_a_request(): void
    {
        app(CancellationRequests::class)->request($this->hosting, CancellationType::EndOfPeriod, null);

        $this->clientPage()
            ->callAction('withdrawCancellation')
            ->assertNotified(__('Cancellation request withdrawn'));

        $this->assertSame(0, CancellationRequest::query()->count());
        $this->assertTrue(CancellationRequests::canRequest($this->hosting));
    }

    public function test_who_can_ask(): void
    {
        $this->assertTrue(CancellationRequests::canRequest($this->hosting));
        $this->assertFalse(CancellationRequests::canRequest($this->domain()), 'a domain turns auto renew off instead');

        $this->hosting->update(['status' => ServiceStatus::Pending]);
        $this->assertFalse(CancellationRequests::canRequest($this->hosting));

        $this->hosting->update(['status' => ServiceStatus::Suspended]);
        $this->assertTrue(CancellationRequests::canRequest($this->hosting));

        $this->hosting->update(['auto_terminate' => true]);
        $this->assertFalse(CancellationRequests::canRequest($this->hosting), 'already ending');
    }

    public function test_approving_at_the_end_of_the_period_sets_auto_terminate_and_stops_the_domain_renewing(): void
    {
        $domain = $this->domain();
        $request = app(CancellationRequests::class)->request($this->hosting, CancellationType::EndOfPeriod, 'Too expensive', withDomain: true);

        $this->adminList()
            ->assertCanSeeTableRecords([$request])
            ->callAction(TestAction::make('approve')->table($request))
            ->assertNotified(__('Request approved'));

        $this->assertSame(ChangeRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame($this->admin->id, $request->fresh()->decided_by);
        $this->assertTrue($this->hosting->fresh()->auto_terminate);
        $this->assertSame('Too expensive', $this->hosting->fresh()->auto_terminate_reason);
        $this->assertSame(ServiceStatus::Active, $this->hosting->fresh()->status);
        $this->assertFalse($domain->fresh()->auto_renew);
        $this->assertFalse($this->whmAccounts['maria'], 'still on the server until its due date');

        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::CancellationApproved
            && $mail->hasTo($this->client->email)
            && $mail->values['cancellation_date'] === '01/12/2026');
    }

    public function test_approving_immediately_terminates_on_the_server(): void
    {
        $request = app(CancellationRequests::class)->request($this->hosting, CancellationType::Immediate, null);

        $this->adminList()->callAction(TestAction::make('approve')->table($request));

        $this->assertSame(ServiceStatus::Terminated, $this->hosting->fresh()->status);
        $this->assertArrayNotHasKey('maria', $this->whmAccounts);
        $this->assertSame(ChangeRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_a_failed_termination_keeps_the_request_open(): void
    {
        $request = app(CancellationRequests::class)->request($this->hosting, CancellationType::Immediate, null);
        $this->whmFailures['removeacct'] = 'Server busy';

        $this->adminList()
            ->callAction(TestAction::make('approve')->table($request))
            ->assertNotified(__('The server did not terminate the account'));

        $this->assertSame(ChangeRequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $this->hosting->fresh()->status);
        Mail::assertNotSent(ClientMail::class);
    }

    public function test_declining_leaves_the_service_as_it_is(): void
    {
        $request = app(CancellationRequests::class)->request($this->hosting, CancellationType::EndOfPeriod, null);

        $this->adminList()
            ->callAction(TestAction::make('decline')->table($request))
            ->assertNotified(__('Request declined'));

        $this->assertSame(ChangeRequestStatus::Rejected, $request->fresh()->status);
        $this->assertFalse($this->hosting->fresh()->auto_terminate);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::CancellationRejected);

        // Decided requests move to Completed
        $this->adminList()->assertCanNotSeeTableRecords([$request])
            ->set('activeTab', 'completed')
            ->assertCanSeeTableRecords([$request]);
    }
}
