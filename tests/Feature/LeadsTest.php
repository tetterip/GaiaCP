<?php

namespace Tests\Feature;

use App\Actions\Leads;
use App\Enums\AdminArea;
use App\Enums\LeadStatus;
use App\Enums\MailTemplate;
use App\Events\GaiaEvent;
use App\Filament\Clusters\Reports\Pages\LeadSources;
use App\Filament\Clusters\Settings\Pages\SalesSettings;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\RelationManagers\NotesRelationManager;
use App\Filament\Widgets\NeedsAttention;
use App\Mail\ClientMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Clients → Leads (App\Actions\Leads): prospective clients, their notes and follow-ups, until they become a client. */
class LeadsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00'));
        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->create(['name' => 'Admin']);
        $this->actingAs($this->admin);
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::query()->create($attributes + ['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr', 'source' => 'Phone']);
    }

    public function test_an_admin_adds_a_lead_assigned_to_themselves(): void
    {
        Livewire::test(CreateLead::class)
            ->fillForm([
                'first_name' => 'Maria',
                'last_name' => 'Papa',
                'company' => 'Papa Ltd',
                'email' => 'maria@example.gr',
                'phone' => '+30 6912345678',
                'source' => 'Referral',
                'next_contact_on' => '2026-10-27',
                'notes' => 'Two sites and email',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::query()->sole();
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame($this->admin->id, $lead->assigned_to);
        $this->assertSame('2026-10-27', $lead->next_contact_on->toDateString());
        $this->assertSame('Maria Papa (Papa Ltd)', $lead->displayName());
        $this->assertDatabaseHas('activity_logs', ['event' => 'lead.created', 'description' => 'Lead Maria Papa (Papa Ltd) added (source: Referral)']);
    }

    public function test_the_sources_come_from_settings_and_keep_a_renamed_one(): void
    {
        $this->assertContains('Website', Leads::sources());

        Livewire::test(SalesSettings::class)
            ->fillForm(['sales.lead_sources' => ['Google Ads', ' Facebook ', 'Google Ads']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Google Ads', 'Facebook'], Leads::sources());
        $this->assertSame(['Google Ads' => 'Google Ads', 'Facebook' => 'Facebook', 'Phone' => 'Phone'], LeadResource::sourceOptions('Phone'));
    }

    public function test_a_note_keeps_the_history_and_makes_a_new_lead_contacted(): void
    {
        $lead = $this->lead();

        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
            ->callAction(TestAction::make('create')->table(), ['body' => 'Called, wants two sites'])
            ->assertHasNoErrors();

        $note = $lead->leadNotes()->sole();
        $this->assertSame('Called, wants two sites', $note->body);
        $this->assertSame($this->admin->id, $note->user_id);
        $this->assertSame(LeadStatus::Contacted, $lead->refresh()->status);
    }

    public function test_converting_makes_a_client_and_invites_them(): void
    {
        $lead = $this->lead(['phone' => '+30 6912345678', 'country' => 'GR', 'next_contact_on' => '2026-10-26']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', ['company' => 'Papa Ltd', 'invite' => true])
            ->assertHasNoActionErrors();

        $client = Client::query()->sole();
        $this->assertSame(['Maria', 'Papa', 'Papa Ltd', 'maria@example.gr', '+30 6912345678', 'GR'], [$client->first_name, $client->last_name, $client->company, $client->email, $client->phone, $client->country]);

        $lead->refresh();
        $this->assertSame(LeadStatus::Won, $lead->status);
        $this->assertSame($client->id, $lead->client_id);
        $this->assertNull($lead->next_contact_on);
        $this->assertNotNull($lead->closed_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'lead.converted', 'client_id' => $client->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'client.created', 'client_id' => $client->id]);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->template() === MailTemplate::ClientAreaInvite && $mail->hasTo('maria@example.gr'));

        // Once a client, it can't be converted again or deleted.
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionHidden('convert')
            ->assertActionHidden('delete')
            ->assertActionVisible('openClient');
    }

    public function test_a_lead_with_a_clients_email_is_linked_to_that_client(): void
    {
        $existing = Client::factory()->create(['email' => 'maria@example.gr']);
        $lead = $this->lead();

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', ['invite' => true])
            ->assertHasNoActionErrors();

        $this->assertSame(1, Client::query()->count());
        $this->assertSame($existing->id, $lead->refresh()->client_id);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'client.created']);
        Mail::assertNothingSent();
    }

    public function test_converting_needs_an_email(): void
    {
        $lead = $this->lead(['email' => null]);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert')
            ->assertHasActionErrors(['email' => 'required']);

        $this->assertSame(0, Client::query()->count());
    }

    public function test_a_lead_is_lost_with_its_reason_and_reopened(): void
    {
        $lead = $this->lead(['next_contact_on' => '2026-10-26']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('lost', ['reason' => 'Too expensive']);

        $lead->refresh();
        $this->assertSame(LeadStatus::Lost, $lead->status);
        $this->assertSame('Too expensive', $lead->lost_reason);
        $this->assertNull($lead->next_contact_on);
        $this->assertDatabaseHas('activity_logs', ['event' => 'lead.lost', 'description' => 'Lead Maria Papa lost: Too expensive']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionHidden('lost')
            ->callAction('reopen');

        $this->assertSame(LeadStatus::Contacted, $lead->refresh()->status);
        $this->assertNull($lead->lost_reason);
    }

    public function test_the_bell_rings_once_on_the_contact_day_for_whoever_has_the_lead(): void
    {
        $sales = User::factory()->limitedTo([AdminArea::Sales])->create();
        $other = User::factory()->limitedTo([AdminArea::Sales])->create();
        $billing = User::factory()->limitedTo([AdminArea::Billing])->create();
        $assigned = $this->lead(['assigned_to' => $sales->id, 'next_contact_on' => '2026-10-25', 'phone' => '+30 6912345678']);
        $nobody = $this->lead(['first_name' => 'Nikos', 'email' => 'nikos@example.gr', 'next_contact_on' => '2026-10-24']);
        $this->lead(['first_name' => 'Later', 'email' => 'later@example.gr', 'next_contact_on' => '2026-10-26']);
        $this->lead(['first_name' => 'Lost', 'email' => 'lost@example.gr', 'next_contact_on' => '2026-10-25', 'status' => LeadStatus::Lost]);

        auth()->logout(); // cron runs it signed in as nobody

        $this->artisan('gaiacp:lead-follow-ups --dry-run')->expectsOutputToContain('2 to remind')->assertSuccessful();
        $this->assertSame(0, $sales->notifications()->count());

        $this->artisan('gaiacp:lead-follow-ups')->expectsOutputToContain('2 reminded')->assertSuccessful();

        // Maria is assigned to $sales; Nikos to nobody, so everyone in Sales hears about him.
        $this->assertEqualsCanonicalizing(['Contact Maria Papa today', 'Contact Nikos Papa today'], $sales->notifications()->get()->pluck('data.title')->all());
        $this->assertSame(['Contact Nikos Papa today'], $other->notifications()->get()->pluck('data.title')->all());
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(0, $billing->notifications()->count());

        // Run again the same day: nothing new. A new contact day rings again.
        $this->artisan('gaiacp:lead-follow-ups')->expectsOutputToContain('0 reminded');
        $assigned->update(['next_contact_on' => '2026-10-28']);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 08:00'));
        $this->artisan('gaiacp:lead-follow-ups')->expectsOutputToContain('2 reminded'); // Maria again, and Later

        $this->assertSame(4, $sales->notifications()->count());
        $this->assertSame(2, $other->notifications()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'lead.follow_up')->where('properties->lead_id', $nobody->id)->count());
    }

    public function test_leads_to_contact_show_on_the_dashboard_and_the_menu(): void
    {
        $this->assertNotContains('heroicon-o-phone', array_column(NeedsAttention::items(), 'icon'));

        $this->lead(['next_contact_on' => '2026-10-25']);
        $this->lead(['first_name' => 'Nikos', 'next_contact_on' => '2026-10-20']);

        $item = collect(NeedsAttention::items())->firstWhere('icon', 'heroicon-o-phone');
        $this->assertSame('2 lead(s) to contact today', $item['title']);
        $this->assertSame('Nikos Papa, Maria Papa', $item['sub']);
        $this->assertSame('2', LeadResource::getNavigationBadge());

        Livewire::test(ListLeads::class, ['activeTab' => 'to_contact'])
            ->assertCanSeeTableRecords(Lead::query()->get());
    }

    public function test_only_admins_with_sales_see_leads(): void
    {
        $this->actingAs(User::factory()->limitedTo([AdminArea::Sales])->create());
        $this->get(LeadResource::getUrl())->assertOk();
        $this->get(SalesSettings::getUrl())->assertOk();

        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());
        $this->get(LeadResource::getUrl())->assertForbidden();
        $this->assertNotContains('heroicon-o-phone', array_column(NeedsAttention::items(), 'icon'));
    }

    public function test_the_report_counts_leads_by_source(): void
    {
        $this->lead(['status' => LeadStatus::Won]);
        $this->lead(['status' => LeadStatus::Lost]);
        $this->lead();
        $this->lead(['source' => 'Website', 'status' => LeadStatus::Won]);
        $this->lead(['source' => null]);

        $report = Livewire::test(LeadSources::class)->instance()->report();

        $this->assertSame(['Phone', '3', '1', '1', '1', '50%'], $report['rows'][0]['cells']);
        $this->assertSame(['Website', '1', '1', '0', '0', '100%'], $report['rows'][1]['cells']);
        $this->assertSame(['Unknown', '1', '0', '0', '1', '—'], $report['rows'][2]['cells']);
        $this->assertSame(['Total', '5', '2', '1', '2', '67%'], $report['totals']);
    }

    public function test_lead_events_reach_plugins_with_the_lead(): void
    {
        Event::fake([GaiaEvent::class]);

        app(Leads::class)->create(['first_name' => 'Maria', 'email' => 'maria@example.gr', 'source' => 'Website']);

        Event::assertDispatched(GaiaEvent::class, fn (GaiaEvent $event) => $event->name === 'lead.created'
            && $event->payload()['lead']['email'] === 'maria@example.gr'
            && $event->payload()['lead']['status'] === 'new');
    }
}
