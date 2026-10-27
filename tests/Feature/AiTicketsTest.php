<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Ai\TicketContext;
use App\Enums\ServiceType;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Client;
use App\Models\KbArticle;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The AI assistant on a ticket: a reply draft, a summary with priority and department, a knowledge base article. */
class AiTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-secret']);

        $admin = User::factory()->create(['name' => 'Eleni']);
        $this->actingAs($admin);
        $this->client = Client::factory()->create(['first_name' => 'Xanthippi', 'last_name' => 'Karali', 'email' => 'xk@mail.example', 'phone' => '+30 210 123 4567']);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $hosting = Service::factory()->create(['client_id' => $this->client->id, 'type' => ServiceType::Hosting, 'domain' => 'karali-bakery.gr', 'password' => 'S3cret!pass']);

        $this->ticket = app(Tickets::class)->open($this->client, $user, 'Email not working on karali-bakery.gr',
            "Hello, I cannot send email from info@karali-bakery.gr since yesterday 2026-10-20. Call me at +30 210 123 4567.\nXanthippi Karali", service: $hosting);
    }

    private function claudeAnswers(string $text): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
        ])]);
    }

    private function sent(): string
    {
        $request = Http::recorded(fn (Request $request) => str_contains($request->url(), 'anthropic'))->sole()[0];

        return $request['system']."\n".$request['messages'][0]['content'];
    }

    public function test_a_reply_is_drafted_from_the_conversation_services_and_knowledge_base_without_client_details(): void
    {
        KbArticle::create(['texts' => ['en' => ['title' => 'Setting up email on your phone', 'body' => 'Use port 465 with SSL for sending email.']], 'published' => true]);
        KbArticle::create(['texts' => ['en' => ['title' => 'Paying by bank transfer', 'body' => 'Our IBAN is on the notice.']], 'published' => true]);
        app(Tickets::class)->reply($this->ticket, auth()->user(), 'Internal: their mailbox is over quota', internal: true);
        $this->claudeAnswers("Dear [Customer],\n\nPlease use port 465 with SSL. [check: mailbox quota]");

        Livewire::test(ViewTicket::class, ['record' => $this->ticket->getRouteKey()])
            ->mountAction('reply')
            ->callAction(TestAction::make('aiDraftReply')->schemaComponent('body', 'mountedActionSchema0'))
            ->assertSchemaStateSet(['body' => "Dear Xanthippi,\n\nPlease use port 465 with SSL. [check: mailbox quota]"], 'mountedActionSchema0');

        $sent = $this->sent();
        $this->assertStringContainsString('Setting up email on your phone', $sent);
        $this->assertStringNotContainsString('Paying by bank transfer', $sent, 'only articles close to the ticket');
        $this->assertStringContainsString('Web hosting: karali-bakery.gr', $sent);
        $this->assertStringContainsString('(this ticket is about it)', $sent);
        $this->assertStringContainsString('since yesterday 2026-10-20', $sent, 'dates are kept');
        $this->assertStringContainsString('[Customer]', $sent);
        foreach (['Xanthippi', 'Karali', 'xk@mail.example', 'info@karali-bakery', '210 123', 'S3cret', 'over quota'] as $private) {
            $this->assertStringNotContainsString($private, $sent);
        }
    }

    public function test_a_summary_suggests_priority_and_department_and_is_kept_until_the_client_writes(): void
    {
        $billing = TicketDepartment::create(['name' => 'Billing']);
        $technical = TicketDepartment::create(['name' => 'Technical support']);
        $this->ticket->update(['department_id' => $billing->id]);
        $this->claudeAnswers('```json'."\n".'{"summary": "[Customer] cannot send email since yesterday.", "priority": "high", "department": "Technical support", "mood": "worried"}'."\n```");

        Livewire::test(ViewTicket::class, ['record' => $this->ticket->getRouteKey()])
            ->mountAction('aiSummary')
            ->assertSchemaStateSet(['summary' => 'Xanthippi cannot send email since yesterday.', 'priority' => TicketPriority::High, 'department_id' => $technical->id], 'mountedActionSchema0')
            ->assertSchemaStateSet(['mood' => __('The client sounds worried.')], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->ticket->refresh();
        $this->assertSame(TicketPriority::High, $this->ticket->priority);
        $this->assertSame($technical->id, $this->ticket->department_id);
        $this->assertSame('Xanthippi cannot send email since yesterday.', $this->ticket->summary);
        $this->assertStringContainsString('Billing; Technical support', $this->sent());

        app(Tickets::class)->replyFromEmail($this->ticket, 'It works now, thanks.');
        $this->assertNull($this->ticket->refresh()->summary);
    }

    public function test_an_answered_ticket_becomes_an_unpublished_article_without_domains(): void
    {
        app(Tickets::class)->reply($this->ticket, auth()->user(), 'Use port 465 with SSL on mail.karali-bakery.gr.');
        $this->claudeAnswers('{"title": "Email cannot be sent", "body": "1. Use port 465 with SSL on mail.example.com."}');

        Livewire::test(ViewTicket::class, ['record' => $this->ticket->getRouteKey()])
            ->mountAction('aiKbArticle')
            ->assertSchemaStateSet(['title' => 'Email cannot be sent'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified(__('Article saved as a draft'));

        $article = KbArticle::query()->sole();
        $this->assertFalse($article->published);
        $this->assertSame('Email cannot be sent', $article->title('en'));

        $sent = $this->sent();
        $this->assertStringContainsString('mail.example.com', $sent);
        $this->assertStringNotContainsString('karali-bakery', $sent);
    }

    public function test_the_buttons_follow_their_switches(): void
    {
        config(['gaiacp.ai.features.ticket_summary' => false, 'gaiacp.ai.features.kb_article' => false]);
        app(Tickets::class)->reply($this->ticket, auth()->user(), 'Done.');

        Livewire::test(ViewTicket::class, ['record' => $this->ticket->getRouteKey()])
            ->assertActionHidden('aiSummary')
            ->assertActionHidden('aiKbArticle');

        $this->assertSame('Hi [Customer], call [phone], write to [email].',
            (new TicketContext($this->ticket))->redact('Hi Xanthippi, call 6912345678, write to someone@else.gr.'));
        Http::assertNothingSent();
    }
}
