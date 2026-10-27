<?php

namespace Tests\Feature;

use App\Actions\Leads;
use App\Actions\Quotes;
use App\Api\OpenApi;
use App\Enums\AdminArea;
use App\Enums\LeadStatus;
use App\Enums\QuoteStatus;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The REST API's leads and quotes: a website form adds leads, quotes are written, sent and answered. */
class LeadsQuotesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00'));
    }

    /** @param  list<string>|null  $permissions */
    private function api(?array $permissions = null): static
    {
        [, $token] = ApiKey::generate(['name' => 'Website', 'permissions' => $permissions ?? OpenApi::permissions()]);

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    public function test_a_website_form_adds_a_lead_and_the_bell_tells_sales(): void
    {
        $sales = User::factory()->limitedTo([AdminArea::Sales])->create();

        $this->api(['leads.read'])->postJson('/api/v1/leads', ['first_name' => 'Maria'])->assertForbidden();

        $this->api(['leads.create'])->postJson('/api/v1/leads', ['email' => 'x@example.gr'])->assertUnprocessable()->assertJsonValidationErrors('first_name');

        $id = $this->api(['leads.create'])->postJson('/api/v1/leads', [
            'first_name' => 'Maria',
            'email' => 'Maria@Example.gr',
            'phone' => '6900000000',
            'source' => 'Website',
            'notes' => 'Wants an online shop',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.email', 'maria@example.gr')
            ->json('data.id');

        $log = ActivityLog::query()->where('event', 'lead.created')->sole();
        $this->assertNotNull($log->api_key_id);
        $this->assertSame(['New lead: Maria'], $sales->notifications()->get()->pluck('data.title')->all());

        $this->api()->getJson('/api/v1/leads?search=shop')->assertJsonCount(0, 'data');
        $this->api()->getJson('/api/v1/leads?search=maria')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->api()->patchJson("/api/v1/leads/{$id}", ['status' => 'won'])->assertUnprocessable();
        $this->api()->patchJson("/api/v1/leads/{$id}", ['status' => 'contacted', 'next_contact_on' => '2026-10-27'])->assertOk()
            ->assertJsonPath('data.status', 'contacted')->assertJsonPath('data.next_contact_on', '2026-10-27');
    }

    public function test_an_admin_adding_a_lead_in_the_panel_rings_no_bell(): void
    {
        $sales = User::factory()->limitedTo([AdminArea::Sales])->create();
        $this->actingAs(User::factory()->create());

        app(Leads::class)->create(['first_name' => 'Nikos']);

        $this->assertSame(0, $sales->notifications()->count());
    }

    public function test_a_converted_lead_is_not_changed_or_deleted(): void
    {
        $lead = Lead::query()->create(['first_name' => 'Maria', 'status' => LeadStatus::Won, 'client_id' => Client::factory()->create()->id]);

        $this->api()->patchJson("/api/v1/leads/{$lead->id}", ['phone' => '1'])->assertStatus(409);
        $this->api()->deleteJson("/api/v1/leads/{$lead->id}")->assertStatus(409);

        $open = Lead::query()->create(['first_name' => 'Nikos']);
        $this->api()->deleteJson("/api/v1/leads/{$open->id}")->assertNoContent();
        $this->assertNull(Lead::find($open->id));
    }

    public function test_a_quote_is_written_sent_and_accepted_for_the_lead(): void
    {
        $lead = Lead::query()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr', 'country' => 'GR']);

        $this->api()->postJson('/api/v1/quotes', ['lead_id' => $lead->id, 'items' => [
            ['type' => 'hosting', 'label' => 'Site', 'billing_cycle' => 'onetime', 'price_cents' => 100],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.billing_cycle');

        $quote = $this->api()->postJson('/api/v1/quotes', [
            'lead_id' => $lead->id,
            'items' => [['type' => 'other', 'label' => 'Website care', 'billing_cycle' => 'annually', 'price_cents' => 10000]],
            'extras' => [['description' => 'Moving the site', 'amount_cents' => 5000]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', 'QT-2026-0001')
            ->assertJsonPath('data.valid_until', '2026-11-24')
            ->assertJsonPath('data.net_cents', 15000)
            ->json('data');

        $this->api(['quotes.read'])->postJson("/api/v1/quotes/{$quote['id']}/send")->assertForbidden();
        $this->api()->postJson("/api/v1/quotes/{$quote['id']}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertSame(LeadStatus::Quoted, $lead->refresh()->status);

        $accepted = $this->api()->postJson("/api/v1/quotes/{$quote['id']}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted')->json('data');
        $this->assertNotNull($accepted['order_id']);
        $this->assertSame(2, Service::query()->where('order_id', $accepted['order_id'])->count());
        $this->assertSame(LeadStatus::Won, $lead->refresh()->status);

        $this->api()->postJson("/api/v1/quotes/{$quote['id']}/accept")->assertStatus(409);
        $this->api()->deleteJson("/api/v1/quotes/{$quote['id']}")->assertStatus(409);
        $this->api()->getJson("/api/v1/quotes?status=accepted&lead_id={$lead->id}")->assertJsonCount(1, 'data');
    }

    public function test_a_sent_quote_is_declined_and_a_draft_deleted(): void
    {
        $client = Client::factory()->create();
        $quote = app(Quotes::class)->create(['client_id' => $client->id, 'extras' => [['description' => 'Audit', 'amount_cents' => 9000]]]);

        $this->api()->postJson("/api/v1/quotes/{$quote->id}/decline")->assertStatus(409); // a draft was never sent

        $quote->update(['status' => QuoteStatus::Sent]);
        $this->api()->postJson("/api/v1/quotes/{$quote->id}/decline", ['reason' => 'Not now'])->assertOk()
            ->assertJsonPath('data.status', 'declined')->assertJsonPath('data.decline_reason', 'Not now');

        $draft = app(Quotes::class)->create(['client_id' => $client->id, 'extras' => [['description' => 'Audit', 'amount_cents' => 9000]]]);
        $this->api()->deleteJson("/api/v1/quotes/{$draft->id}")->assertNoContent();
        $this->assertNull(Quote::find($draft->id));
    }
}
