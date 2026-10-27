<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Enums\MailTemplate;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Mail\ClientMail;
use App\Mail\TicketMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Plugins\PluginManager;
use DirectoryTree\ImapEngine\FileMessage;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Plugins\TicketMail\InboundMail;
use Plugins\TicketMail\MailboxConnector;
use Plugins\TicketMail\QuotedText;
use Tests\TestCase;

/** The Email to ticket plugin: the support mailbox's emails become tickets and answers (Plugins\TicketMail). */
class TicketMailTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['gaiacp.support.reply_to' => 'support@gaiacp.test', 'mail.from.address' => 'billing@gaiacp.test']);

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create(['first_name' => 'Nikos', 'email' => 'nikos@example.com']);
    }

    private function process(string $raw): array
    {
        return app(InboundMail::class)->process(new FileMessage($raw));
    }

    /** @param  array<string, string>  $headers */
    private function email(string $from, string $subject, string $body, array $headers = [], string $to = 'support@gaiacp.test', ?string $attachment = null): string
    {
        $headers = ['Message-ID' => '<'.Str::random(16).'@mail.example.com>'] + $headers;
        $lines = ["From: {$from}", "To: {$to}", "Subject: {$subject}", 'Date: Fri, 23 Oct 2026 10:00:00 +0300', 'MIME-Version: 1.0'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        if ($attachment === null) {
            return implode("\r\n", [...$lines, 'Content-Type: text/plain; charset=utf-8', '', $body]);
        }

        return implode("\r\n", [...$lines,
            'Content-Type: multipart/mixed; boundary="b1"', '',
            '--b1', 'Content-Type: text/plain; charset=utf-8', '', $body,
            '--b1', "Content-Type: application/octet-stream; name=\"{$attachment}\"", "Content-Disposition: attachment; filename=\"{$attachment}\"",
            'Content-Transfer-Encoding: base64', '', base64_encode('error at line 3'),
            '--b1--', '']);
    }

    public function test_a_clients_email_opens_a_ticket_and_their_answer_joins_it(): void
    {
        $billing = TicketDepartment::create(['name' => 'Billing', 'email' => 'billing-help@gaiacp.test']);

        [$outcome] = $this->process($this->email('Nikos <NIKOS@example.com>', 'Invoice question', "Why is it €80?\r\nThanks", to: 'billing-help@gaiacp.test'));

        $this->assertSame(InboundMail::OPENED, $outcome);
        $ticket = Ticket::query()->sole();
        $this->assertSame([$this->client->id, $billing->id, TicketStatus::Open, 'Invoice question'], [$ticket->client_id, $ticket->department_id, $ticket->status, $ticket->subject]);
        $this->assertTrue($ticket->messages()->sole()->from_client);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => $mail->new);

        // The client's acknowledgement carries the ticket's thread and the department's reply address
        Mail::assertSent(ClientMail::class, function (ClientMail $mail) use ($ticket) {
            return $mail->mailTemplate === MailTemplate::TicketOpened
                && $mail->headers()->references === [$ticket->emailThreadId()]
                && $mail->envelope()->replyTo[0]->address === 'billing-help@gaiacp.test';
        });

        app(Tickets::class)->reply($ticket, $this->admin, 'It includes the setup fee.');
        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);

        $answer = $this->email('nikos@example.com', "Re: Re: [{$ticket->number}] Invoice question",
            "Ok, thanks!\r\n\r\nOn Fri, 23 Oct 2026 at 11:00, Support <support@gaiacp.test> wrote:\r\n> It includes the setup fee.",
            ['In-Reply-To' => '<'.$ticket->newEmailMessageId().'>', 'References' => '<'.$ticket->emailThreadId().'>'], attachment: 'error.log');

        $this->assertSame([InboundMail::REPLIED, $ticket->number], $this->process($answer));
        $this->assertSame([InboundMail::SKIPPED, 'already added'], $this->process($answer), 'read twice, added once');

        $message = $ticket->messages()->get()->last();
        $this->assertSame('Ok, thanks!', $message->body);
        $this->assertTrue($message->from_client);
        $this->assertNull($message->user_id);
        $this->assertSame('error.log', $message->attachments()->sole()->name);
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
        $this->assertSame(1, ActivityLog::query()->where('event', 'ticket.client_replied')->count());
    }

    public function test_someone_else_cannot_write_in_a_ticket_by_its_number(): void
    {
        $ticket = app(Tickets::class)->openFromEmail($this->client, 'nikos@example.com', 'Nikos', 'Email down', 'Help');

        [$outcome] = $this->process($this->email('stranger@example.net', "Re: [{$ticket->number}] Email down", 'Let me in'));

        $this->assertSame(InboundMail::OPENED, $outcome, 'a new ticket of their own instead');
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertNull(Ticket::query()->where('email', 'stranger@example.net')->sole()->client_id);
    }

    public function test_an_unknown_sender_gets_a_ticket_an_admin_links_to_a_client(): void
    {
        $this->process($this->email('Maria Papa <maria@example.org>', 'Hosting offer', 'How much is hosting?'));

        $ticket = Ticket::query()->sole();
        $this->assertSame([null, 'maria@example.org', 'Maria Papa'], [$ticket->client_id, $ticket->email, $ticket->name]);
        $this->assertSame('Maria Papa', $ticket->messages()->sole()->authorName());
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->hasTo('maria@example.org') && $mail->clientAreaUrl === null);

        // Answered by email to the sender, until an admin links a client
        app(Tickets::class)->reply($ticket, $this->admin, 'From €5 a month.');
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::TicketReply && $mail->hasTo('maria@example.org'));

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('maria@example.org')
            ->callAction('change', ['client_id' => $this->client->id])
            ->assertHasNoActionErrors();
        $this->assertSame($this->client->id, $ticket->fresh()->client_id);

        $this->expectException(\InvalidArgumentException::class);
        app(Tickets::class)->update($ticket->fresh(), ['client_id' => Client::factory()->create()->id]);
    }

    public function test_unknown_senders_can_be_ignored(): void
    {
        config(['gaiacp.ticket_mail.unknown_senders' => 'ignore']);

        $this->assertSame([InboundMail::SKIPPED, 'not a client'], $this->process($this->email('spam@example.org', 'Buy now', 'Cheap')));
        $this->assertSame(InboundMail::OPENED, $this->process($this->email('nikos@example.com', 'Hello', 'A question'))[0]);

        // A client-area login's address counts as the client's too
        User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => 'office@example.com']);
        $this->assertSame(InboundMail::OPENED, $this->process($this->email('office@example.com', 'From the office', 'Another'))[0]);
        $this->assertSame(2, $this->client->tickets()->count());
    }

    public function test_automatic_emails_and_floods_are_skipped(): void
    {
        config(['gaiacp.ticket_mail.max_per_hour' => 2]);

        $this->assertSame(InboundMail::SKIPPED, $this->process($this->email('nikos@example.com', 'Out of office', 'Back Monday', ['Auto-Submitted' => 'auto-replied']))[0]);
        $this->assertSame(InboundMail::SKIPPED, $this->process($this->email('MAILER-DAEMON@mail.example.com', 'Undelivered', 'Bounce'))[0]);
        $this->assertSame(InboundMail::SKIPPED, $this->process($this->email('support@gaiacp.test', 'Loop', 'Our own'))[0]);

        foreach ([1, 2, 3] as $i) {
            $outcomes[] = $this->process($this->email('maria@example.org', "Question {$i}", 'Hi'))[0];
        }

        $this->assertSame([InboundMail::OPENED, InboundMail::OPENED, InboundMail::SKIPPED], $outcomes);
        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(4, ActivityLog::query()->where('event', 'ticket_mail.skipped')->count());
    }

    public function test_the_command_reads_unread_emails_and_marks_them(): void
    {
        DB::table('plugins')->updateOrInsert(['id' => 'ticket-mail'], ['enabled' => false, 'version' => '1.0.0']);
        $this->artisan('gaiacp:fetch-ticket-mail')->expectsOutputToContain('plugin is off')->assertSuccessful();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('ticket-mail'));
        $this->artisan('gaiacp:fetch-ticket-mail')->expectsOutputToContain('No mailbox set')->assertSuccessful();

        $first = new FakeMessage(1, [], $this->email('nikos@example.com', 'Site down', 'example.com is down'));
        $second = new FakeMessage(2, [], $this->email('nikos@example.com', 'Out of office', 'Away', ['Auto-Submitted' => 'auto-replied']));
        $mailbox = new FakeMailbox([], [new FakeFolder('inbox', [], [$first, $second])]);

        $this->app->instance(MailboxConnector::class, new class($mailbox) extends MailboxConnector
        {
            public function __construct(private MailboxInterface $fake) {}

            public function configured(): bool
            {
                return true;
            }

            public function mailbox(): MailboxInterface
            {
                return $this->fake;
            }
        });

        $this->artisan('gaiacp:fetch-ticket-mail')->expectsOutputToContain('1 opened, 0 replies, 1 skipped, 0 failed')->assertSuccessful();

        $this->assertSame('Site down', Ticket::query()->sole()->subject);
        $this->assertTrue($first->isSeen() && $second->isSeen());
    }

    public function test_quoted_text_and_signatures_are_left_out(): void
    {
        $this->assertSame("Thanks,\nit works", QuotedText::strip("Thanks,\nit works\n\n-- \nNikos\n+30 210 000"));
        $this->assertSame('Ευχαριστώ', QuotedText::strip("Ευχαριστώ\n\nΣτις Παρ 23 Οκτ 2026 στις 11:00, Support <s@x.gr> έγραψε:\n> παλιό"));
        $this->assertSame('Fine', QuotedText::strip("Fine\n\nOn Fri, 23 Oct 2026 at 11:00, Support Team <support@example.com>\nwrote:\n> old"));
        $this->assertSame('Yes', QuotedText::strip("Yes\r\n\r\nFrom: Support <s@x.gr>\r\nSent: Friday\r\nSubject: Re: x\r\n\r\nold"));
        $this->assertSame("From: my side, all good.\nBye", QuotedText::strip("From: my side, all good.\nBye"));
        $this->assertSame("Hello\nthere", QuotedText::fromHtml('<p>Hello</p><p>there</p><blockquote>old text</blockquote>'));
    }
}
