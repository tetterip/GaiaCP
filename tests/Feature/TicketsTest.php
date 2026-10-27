<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Enums\AdminArea;
use App\Enums\MailTemplate;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Tickets\Pages\CreateTicket as ClientCreateTicket;
use App\Filament\Client\Resources\Tickets\Pages\ViewTicket as ClientViewTicket;
use App\Filament\Client\Resources\Tickets\TicketResource as ClientTicketResource;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\ClientMail;
use App\Mail\TicketMail;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Support tickets: the client asks in the client area, the admins answer (and leave notes), old ones close by themselves. */
class TicketsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        $this->admin = User::factory()->create(['name' => 'Maria', 'email' => 'admin@example.com', 'panel_notifications' => true]);
        $this->client = Client::factory()->create(['first_name' => 'Nikos']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
    }

    private function asClient(): void
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
    }

    private function asAdmin(?User $admin = null): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($admin ?? $this->admin);
    }

    private function ticket(): Ticket
    {
        return app(Tickets::class)->open($this->client, $this->user, 'Email not working', 'I cannot send email.',
            files: [UploadedFile::fake()->create('error.log', 3)->store('tickets', 'local') => 'error.log']);
    }

    public function test_the_client_opens_a_ticket_and_an_admin_answers_it(): void
    {
        $department = TicketDepartment::create(['name' => 'Technical support']);
        TicketDepartment::create(['name' => 'Internal', 'client_visible' => false]);

        $this->asClient();
        Livewire::test(ClientCreateTicket::class)
            ->assertFormFieldExists('department_id', fn ($field) => array_values($field->getOptions()) === ['Technical support'])
            ->fillForm([
                'department_id' => $department->id,
                'priority' => TicketPriority::High->value,
                'subject' => 'Email not working',
                'body' => 'I cannot send email since this morning.',
                'attachments' => [UploadedFile::fake()->create('screenshot.png', 20, 'image/png')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame([TicketStatus::Open, TicketPriority::High, $department->id], [$ticket->status, $ticket->priority, $ticket->department_id]);
        $file = $ticket->messages()->sole()->attachments()->sole();
        $this->assertSame('screenshot.png', $file->name);
        Storage::disk('local')->assertExists($file->path);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => $mail->hasTo('admin@example.com') && $mail->new);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::TicketOpened);
        $this->assertSame(1, $this->admin->notifications()->count(), 'the bell rings');

        $this->asAdmin();
        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('I cannot send email since this morning.')
            ->callAction('reply', ['body' => 'Please try port 587.', 'status' => TicketStatus::Answered->value])
            ->assertHasNoActionErrors()
            ->callAction('note', ['body' => 'Their mailbox is full'])
            ->assertHasNoActionErrors();

        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::TicketReply
            && $mail->values['ticket_message'] === 'Please try port 587.' && $mail->values['admin_name'] === 'Maria');
        Mail::assertSent(ClientMail::class, 2); // the note is never emailed

        $this->asClient();
        Livewire::test(ClientViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('Please try port 587.')
            ->assertDontSee('Their mailbox is full')
            ->callAction('reply', ['body' => 'It works now, thanks!'])
            ->assertHasNoActionErrors();

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status, 'waits on us again');
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => ! $mail->new);
    }

    public function test_an_admin_linked_to_the_client_writes_as_the_client_in_the_client_area(): void
    {
        $this->admin->update(['client_id' => $this->client->id]);
        Filament::setCurrentPanel('client');
        $this->actingAs($this->admin, 'client');

        Livewire::test(ClientCreateTicket::class)
            ->fillForm(['subject' => 'Email not working', 'body' => 'I cannot send email.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::Open, $ticket->status, 'waits on us, not on the client');
        $this->assertTrue($ticket->messages()->sole()->from_client);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => $mail->new);

        app(Tickets::class)->reply($ticket, $this->admin, 'We are on it.');
        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);

        Livewire::test(ClientViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction('reply', ['body' => 'Still broken'])
            ->assertHasNoActionErrors();
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
        $this->assertTrue($ticket->messages()->latest('id')->first()->from_client);
    }

    public function test_an_admin_opens_a_ticket_for_a_client(): void
    {
        $this->asAdmin();

        Livewire::withQueryParams(['client' => $this->client->id])
            ->test(CreateTicket::class)
            ->assertSchemaStateSet(['client_id' => $this->client->id])
            ->fillForm(['subject' => 'Your website', 'body' => 'We moved your website to a faster server.', 'email_client' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::Answered, $ticket->status, 'waits on the client');
        $this->assertFalse($ticket->messages()->sole()->from_client);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::TicketOpened);
        Mail::assertNotSent(TicketMail::class);
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_files_are_only_for_the_client_and_admins_who_open_tickets(): void
    {
        $ticket = $this->ticket();
        $file = $ticket->messages()->sole()->attachments()->sole();
        $note = app(Tickets::class)->reply($ticket, $this->admin, 'See the log',
            [UploadedFile::fake()->create('server.log', 2)->store('tickets', 'local') => 'server.log'], internal: true);

        $this->actingAs($this->user, 'client')->get(route('filament.client.ticket-file', ['attachment' => $file]))
            ->assertOk()->assertDownload('error.log');
        $this->actingAs($this->user, 'client')->get(route('filament.client.ticket-file', ['attachment' => $note->attachments()->sole()]))
            ->assertNotFound();

        $other = User::factory()->create(['role' => UserRole::Client, 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($other, 'client')->get(route('filament.client.ticket-file', ['attachment' => $file]))->assertNotFound();

        // The client requests above made "client" the default guard
        $this->actingAs($this->admin, 'web')->get(route('filament.admin.ticket-file', ['attachment' => $file]))->assertOk();
        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create(), 'web')
            ->get(route('filament.admin.ticket-file', ['attachment' => $file]))->assertForbidden();
    }

    public function test_a_client_cannot_attach_a_file_that_is_already_on_the_disk(): void
    {
        $department = TicketDepartment::create(['name' => 'Technical support']);
        Storage::disk('local')->put('backups/gaiacp-2026-10-23-030000.zip', 'the whole database');
        $theirs = $this->ticket()->messages()->sole()->attachments()->sole()->path; // another ticket's file

        $this->asClient();
        Livewire::test(ClientCreateTicket::class)
            ->fillForm([
                'department_id' => $department->id,
                'subject' => 'Help',
                'body' => 'Please look at the files.',
                'attachments' => ['tickets/../backups/gaiacp-2026-10-23-030000.zip', $theirs, 'backups/gaiacp-2026-10-23-030000.zip'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $message = Ticket::query()->latest('id')->first()->messages()->sole();
        $this->assertSame('Please look at the files.', $message->body);
        $this->assertSame(0, $message->attachments()->count());
    }

    public function test_a_ticket_waiting_on_the_client_closes_by_itself(): void
    {
        $answered = $this->ticket();
        app(Tickets::class)->reply($answered, $this->admin, 'Done, please check.');
        $waiting = $this->ticket();

        $this->travel(8)->days();
        $this->artisan('gaiacp:close-tickets')->expectsOutputToContain('1 closed')->assertSuccessful();

        $this->assertSame(TicketStatus::Closed, $answered->fresh()->status);
        $this->assertSame(TicketStatus::Open, $waiting->fresh()->status, 'one waiting on us never closes by itself');
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::TicketClosed);

        // A reply from the client opens it again
        app(Tickets::class)->reply($answered->fresh(), $this->user, 'Still broken');
        $this->assertSame(TicketStatus::Open, $answered->fresh()->status);
        $this->assertNull($answered->fresh()->closed_at);
    }

    public function test_the_assigned_admin_alone_gets_the_clients_reply_by_email(): void
    {
        $helper = User::factory()->limitedTo([AdminArea::Support])->create(['email' => 'helper@example.com']);
        User::factory()->limitedTo([AdminArea::Billing])->create(['email' => 'billing@example.com']);

        $ticket = $this->ticket();
        Mail::assertSent(TicketMail::class, 2); // both admins who open tickets
        Mail::assertNotSent(TicketMail::class, fn (TicketMail $mail) => $mail->hasTo('billing@example.com'));

        app(Tickets::class)->update($ticket, ['assigned_to' => $helper->id]);
        app(Tickets::class)->reply($ticket, $this->user, 'Any news?');

        Mail::assertSent(TicketMail::class, 3);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => ! $mail->new && $mail->hasTo('helper@example.com'));
    }

    public function test_the_dashboard_lists_the_tickets_waiting_for_us(): void
    {
        $this->ticket();

        $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()
            ->assertSee('gx-attention gx-support', false)
            ->assertSee('Email not working');

        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create())->get(Dashboard::getUrl())->assertOk()
            ->assertDontSee('gx-attention gx-support', false);
    }

    public function test_a_canned_reply_is_filled_in_for_the_ticket(): void
    {
        $ticket = $this->ticket();

        $this->assertSame("Hello Nikos,\nabout {$ticket->number}. Maria",
            Tickets::fill("Hello {client_first_name},\nabout {ticket_number}. {admin_name}", $ticket, $this->admin));
    }

    public function test_a_client_only_sees_their_own_tickets_and_none_when_switched_off(): void
    {
        $ticket = $this->ticket();
        $other = User::factory()->create(['role' => UserRole::Client, 'client_id' => Client::factory()->create()->id]);

        $this->actingAs($other, 'client')->get(ClientTicketResource::getUrl('view', ['record' => $ticket], panel: 'client'))->assertNotFound();
        $this->actingAs($this->user, 'client')->get(ClientTicketResource::getUrl('view', ['record' => $ticket], panel: 'client'))->assertOk()->assertSee('Email not working');

        config(['gaiacp.support.client_tickets' => false]);
        $this->actingAs($this->user, 'client')->get(ClientTicketResource::getUrl(panel: 'client'))->assertForbidden();

        $this->expectException(\InvalidArgumentException::class);
        app(Tickets::class)->reply($ticket, $other, 'Not mine');
    }
}
