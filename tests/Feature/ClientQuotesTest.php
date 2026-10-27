<?php

namespace Tests\Feature;

use App\Actions\Quotes;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Client\Resources\Quotes\QuoteResource;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ClientAlert;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Client area → Billing → Quotes: the client's sent quotes, accepted or declined there. */
class ClientQuotesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00'));
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'email' => 'maria@example.gr']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => 'maria@example.gr', 'email_verified_at' => now()]);
    }

    private function quote(array $attributes = []): Quote
    {
        $quote = app(Quotes::class)->create($attributes + [
            'client_id' => $this->client->id,
            'items' => [['type' => 'other', 'label' => 'Website care', 'billing_cycle' => 'annually', 'price_cents' => 10000]],
        ]);
        $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);

        return $quote->refresh();
    }

    private function signIn(): void
    {
        $this->actingAs($this->user, 'client');
        Filament::setCurrentPanel('client');
    }

    public function test_the_menu_shows_quotes_only_once_the_client_has_one(): void
    {
        $this->signIn();
        $this->assertFalse(QuoteResource::shouldRegisterNavigation());

        $draft = app(Quotes::class)->create(['client_id' => $this->client->id, 'extras' => [['description' => 'Audit', 'amount_cents' => 100]]]);
        $this->assertFalse(QuoteResource::shouldRegisterNavigation()); // a draft is not shown

        $this->quote();
        $this->assertTrue(QuoteResource::shouldRegisterNavigation());
        $this->assertSame('1', QuoteResource::getNavigationBadge());
        $this->get(QuoteResource::getUrl(panel: 'client'))->assertOk()->assertSee('QT-2026-0002')->assertDontSee($draft->number);
    }

    public function test_the_client_sees_their_quotes_and_the_ones_written_for_their_lead(): void
    {
        $mine = $this->quote();
        $lead = Lead::query()->create(['first_name' => 'Maria', 'client_id' => $this->client->id]);
        $asLead = $this->quote(['client_id' => null, 'lead_id' => $lead->id]);
        $other = $this->quote(['client_id' => Client::factory()->create()->id]);
        $this->signIn();

        Livewire::test(ListQuotes::class)
            ->assertCanSeeTableRecords([$mine, $asLead])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_client_accepts_a_quote_and_the_order_is_placed(): void
    {
        $quote = $this->quote();
        $this->signIn();

        Livewire::test(ListQuotes::class)
            ->callAction(TestAction::make('accept')->table($quote))
            ->assertNotified();

        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertNotNull($quote->order_id);
        $this->assertSame(1, Service::query()->where('client_id', $this->client->id)->count());

        Livewire::test(ListQuotes::class)->assertTableActionHidden('accept', $quote);
    }

    public function test_the_terms_are_asked_when_required(): void
    {
        config(['gaiacp.client_area.terms' => true, 'gaiacp.client_area.terms_text' => 'Be nice.', 'gaiacp.client_area.terms_version' => '3']);
        $quote = $this->quote();
        $this->signIn();

        Livewire::test(ListQuotes::class)
            ->callAction(TestAction::make('accept')->table($quote), ['accept_terms' => false])
            ->assertHasFormErrors(['accept_terms']);
        $this->assertSame(QuoteStatus::Sent, $quote->refresh()->status);

        Livewire::test(ListQuotes::class)->callAction(TestAction::make('accept')->table($quote), ['accept_terms' => true]);
        $this->assertSame('3', $quote->refresh()->terms_version);
    }

    public function test_the_client_declines_with_a_reason(): void
    {
        $quote = $this->quote();
        $this->signIn();

        Livewire::test(ListQuotes::class)->callAction(TestAction::make('decline')->table($quote), ['reason' => 'Too expensive']);

        $this->assertSame(QuoteStatus::Declined, $quote->refresh()->status);
        $this->assertSame('Too expensive', $quote->decline_reason);
    }

    public function test_a_sent_quote_rings_the_clients_bell(): void
    {
        $this->actingAs(User::factory()->create());
        $quote = app(Quotes::class)->create([
            'client_id' => $this->client->id,
            'items' => [['type' => 'other', 'label' => 'Website care', 'billing_cycle' => 'annually', 'price_cents' => 10000]],
        ]);

        app(Quotes::class)->send($quote);

        $notification = $this->user->notifications()->sole();
        $this->assertSame(ClientAlert::class, $notification->type);
        $this->assertSame('New quote QT-2026-0001', $notification->data['title']);
        $this->assertSame('Valid until 24/11/2026', $notification->data['body']);
    }
}
