<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Enums\MailTemplate;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\ContactSupport;
use App\Filament\Client\Pages\KnowledgeBase as ClientKnowledgeBase;
use App\Filament\Client\Pages\VisitorTicket;
use App\Filament\Clusters\Settings\Pages\PublicAreaSettings;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The public area's support (Settings → Public area): the knowledge base for visitors and the contact form, whose ticket the sender follows at a secret link. */
class PublicSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private KbArticle $public;

    private KbArticle $clientsOnly;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['gaiacp.captcha.provider' => null]);

        User::factory()->create(['email' => 'admin@example.com']);
        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        $category = KbCategory::create(['names' => ['en' => 'Email']]);
        $this->public = KbArticle::create(['kb_category_id' => $category->id, 'texts' => ['en' => ['title' => 'Set up your mailbox', 'body' => 'Open your mail app.']], 'published' => true]);
        $this->clientsOnly = KbArticle::create(['kb_category_id' => $category->id, 'texts' => ['en' => ['title' => 'Mailbox server addresses', 'body' => 'Use mail.example.com.']], 'published' => true, 'clients_only' => true]);
    }

    public function test_the_knowledge_base_stays_for_clients_until_it_is_switched_on(): void
    {
        $this->get('/knowledgebase')->assertForbidden();
        $this->get('/knowledgebase/'.$this->public->slug)->assertForbidden();

        config(['gaiacp.public_area.knowledge_base' => true]);

        $this->get('/knowledgebase')->assertOk()->assertSee('Set up your mailbox')->assertDontSee('Mailbox server addresses');
        $this->get('/knowledgebase/'.$this->public->slug)->assertOk()->assertSee('Open your mail app.');

        // An article kept for clients asks for a login, then opens
        $this->get('/knowledgebase/'.$this->clientsOnly->slug)->assertRedirect('/login');
        $this->assertSame(url('/knowledgebase/'.$this->clientsOnly->slug), session('url.intended'));
        $this->actingAs($this->user, 'client')->get('/knowledgebase/'.$this->clientsOnly->slug)->assertOk()->assertSee('mail.example.com');
        $this->get('/knowledgebase')->assertOk()->assertSee('Mailbox server addresses');
    }

    public function test_visitors_find_no_knowledge_base_without_an_article_for_them(): void
    {
        config(['gaiacp.public_area.knowledge_base' => true]);
        $this->public->update(['clients_only' => true]);

        $this->assertFalse(ClientKnowledgeBase::canAccess());
        $this->get('/knowledgebase')->assertForbidden();
    }

    public function test_the_contact_form_is_off_by_default_and_clients_open_a_ticket_instead(): void
    {
        $this->get('/contact')->assertForbidden();

        config(['gaiacp.public_area.contact' => true]);
        $this->get('/contact')->assertOk()->assertSee(__('Your name'));
        $this->actingAs($this->user, 'client')->get('/contact')->assertRedirect('/tickets/create');
    }

    public function test_a_visitor_opens_a_ticket_and_follows_it_at_its_secret_link(): void
    {
        config(['gaiacp.public_area.contact' => true, 'gaiacp.public_area.knowledge_base' => true]);
        Filament::setCurrentPanel('client');

        Livewire::test(ContactSupport::class)
            ->fillForm(['name' => 'Eleni', 'email' => 'Eleni@Example.com', 'subject' => 'My mailbox does not work', 'body' => 'Since this morning.'])
            ->assertSee('Set up your mailbox') // an article that may answer it
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $ticket = Ticket::query()->sole();
        $this->assertNull($ticket->client_id);
        $this->assertSame('eleni@example.com', $ticket->email);
        $this->assertSame('Eleni', $ticket->name);
        $this->assertSame(48, strlen($ticket->access_token));
        $this->assertArrayNotHasKey('access_token', $ticket->toArray());
        $this->assertStringContainsString('/support/ticket/'.$ticket->access_token, $ticket->publicUrl());

        // The email carries the link
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::TicketOpened
            && $mail->hasTo('eleni@example.com') && $mail->clientAreaUrl === $ticket->publicUrl() && $mail->linkLabel === __('View your ticket'));

        // An admin's answer and note: only the answer shows
        $tickets = app(Tickets::class);
        $tickets->reply($ticket, User::query()->where('email', 'admin@example.com')->sole(), 'Please try again now.');
        $tickets->reply($ticket, User::query()->where('email', 'admin@example.com')->sole(), 'Restarted dovecot.', internal: true);

        $this->get($ticket->publicUrl())->assertOk()
            ->assertSee($ticket->number)->assertSee('Since this morning.')->assertSee('Please try again now.')->assertDontSee('Restarted dovecot.');

        Livewire::test(VisitorTicket::class, ['token' => $ticket->access_token])
            ->callAction('reply', ['body' => 'It works, thanks!'])
            ->assertHasNoActionErrors()
            ->callAction('close');

        $this->assertSame('It works, thanks!', $ticket->messages()->get()->last()->body);
        $this->assertTrue($ticket->messages()->get()->last()->from_client);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_the_contact_form_emails_none_of_the_visitors_words_and_limits_each_address(): void
    {
        config(['gaiacp.public_area.contact' => true]);
        Filament::setCurrentPanel('client');

        // Each time as if from another IP address
        $send = fn () => tap(Livewire::test(ContactSupport::class), fn () => RateLimiter::clear('contact-form:127.0.0.1'))
            ->fillForm(['name' => 'Win at https://bad.example', 'email' => 'someone@example.com', 'subject' => 'You won a prize', 'body' => '[Claim it](https://bad.example/claim)'])
            ->call('send');

        $send()->assertHasNoFormErrors();

        Mail::assertSent(ClientMail::class, function (ClientMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('someone@example.com') && ! str_contains($html, 'bad.example') && ! str_contains($html, 'You won')
                && ! str_contains($mail->envelope()->subject, 'You won');
        });

        // From other addresses too, the same mailbox gets at most PER_HOUR of them
        foreach (range(2, ContactSupport::PER_HOUR) as $i) {
            $send()->assertHasNoFormErrors();
        }
        $send()->assertHasFormErrors(['body']);
        $this->assertSame(ContactSupport::PER_HOUR, Ticket::query()->count());
    }

    public function test_a_wrong_link_or_a_ticket_with_a_client_has_no_page(): void
    {
        $ticket = app(Tickets::class)->openFromVisitor('eleni@example.com', 'Eleni', 'Question', 'Hello');

        $this->get('/support/ticket/wrong-token')->assertNotFound();

        $ticket->update(['client_id' => $this->user->client_id]);
        $this->assertNull($ticket->fresh()->publicUrl());
        $this->get('/support/ticket/'.$ticket->access_token)->assertNotFound();
        $this->assertNull(Ticket::query()->create(['client_id' => $this->user->client_id, 'subject' => 'A client\'s'])->access_token);
    }

    public function test_robots_and_floods_open_nothing(): void
    {
        config(['gaiacp.public_area.contact' => true]);
        Filament::setCurrentPanel('client');
        $form = ['name' => 'Bot', 'email' => 'bot@example.com', 'subject' => 'Cheap offers', 'body' => 'Buy now.'];

        Livewire::test(ContactSupport::class)->fillForm($form + ['website' => 'https://spam.example'])->call('send')->assertNoRedirect();
        $this->assertSame(0, Ticket::query()->count());

        for ($i = 0; $i < ContactSupport::PER_HOUR; $i++) {
            Livewire::test(ContactSupport::class)->fillForm($form)->call('send')->assertRedirect();
        }
        Livewire::test(ContactSupport::class)->fillForm($form)->call('send')->assertHasErrors(['data.body'])->assertNoRedirect();
        $this->assertSame(ContactSupport::PER_HOUR, Ticket::query()->count());

        RateLimiter::clear('contact-form:127.0.0.1');
    }

    public function test_files_open_only_from_their_own_ticket_link(): void
    {
        $tickets = app(Tickets::class);
        $ticket = $tickets->openFromVisitor('eleni@example.com', 'Eleni', 'Question', 'Hello');
        $other = $tickets->openFromVisitor('nikos@example.com', 'Nikos', 'Other', 'Hi');
        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $answer = $tickets->reply($ticket, $admin, 'See the log.', files: [UploadedFile::fake()->create('mail.log', 2)->store('tickets', 'local') => 'mail.log']);
        $note = $tickets->reply($ticket, $admin, 'Internal.', internal: true, files: [UploadedFile::fake()->create('secret.log', 2)->store('tickets', 'local') => 'secret.log']);
        $file = $answer->attachments()->sole();

        $this->get(route('filament.client.support-ticket-file', ['token' => $ticket->access_token, 'attachment' => $file->id]))->assertOk()->assertDownload('mail.log');
        $this->get(route('filament.client.support-ticket-file', ['token' => $other->access_token, 'attachment' => $file->id]))->assertNotFound();
        $this->get(route('filament.client.support-ticket-file', ['token' => $ticket->access_token, 'attachment' => $note->attachments()->sole()->id]))->assertNotFound();
    }

    public function test_the_settings_page_saves_the_support_switches(): void
    {
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());

        Livewire::test(PublicAreaSettings::class)
            ->set('data.public_area.knowledge_base', true)
            ->set('data.public_area.contact', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(config('gaiacp.public_area.knowledge_base'));
        $this->assertTrue(config('gaiacp.public_area.contact'));
    }

    public function test_the_contact_page_shows_the_company_details_beside_the_form(): void
    {
        config([
            'gaiacp.company.name' => 'Acme Hosting',
            'gaiacp.company.address' => "1 Main Street\nAthens",
            'gaiacp.company.phone' => '+30 210 123 4567',
            'gaiacp.company.email' => 'hello@acme.test',
            'gaiacp.company.vat_number' => 'EL123456789',
            'gaiacp.public_area.contact_hours' => ['en' => 'Mon-Fri 09:00-17:00'],
            'gaiacp.public_area.contact_text' => ['en' => 'We answer **within a day**.'],
        ]);

        // Details switched on but nothing else: still no page for visitors without the form
        $this->get('/contact')->assertForbidden();

        config(['gaiacp.public_area.contact_details' => true]);
        $this->get('/contact')->assertOk()
            ->assertSee('Acme Hosting')
            ->assertSee('href="tel:+302101234567"', false)
            ->assertSee('href="mailto:hello@acme.test"', false)
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=1%20Main%20Street%20Athens', false)
            ->assertSee('Mon-Fri 09:00-17:00')
            ->assertSee('<strong>within a day</strong>', false)
            ->assertDontSee('EL123456789') // not picked
            ->assertDontSee(__('Your name')); // the form stays off

        config(['gaiacp.public_area.contact' => true, 'gaiacp.public_area.contact_map' => false, 'gaiacp.public_area.contact_fields' => ['phone', 'vat_number']]);
        $this->get('/contact')->assertOk()
            ->assertSee(__('Your name'))
            ->assertSee('EL123456789')
            ->assertDontSee('hello@acme.test')
            ->assertDontSee('google.com/maps');

        // A client gets the page with Open a ticket, and it is in their menu
        $this->actingAs($this->user, 'client')->get('/contact')->assertOk()
            ->assertSee('Acme Hosting')
            ->assertSee(__('Open a ticket'))
            ->assertDontSee(__('Your name'));
        $this->assertTrue(ContactSupport::shouldRegisterNavigation());

        config(['gaiacp.public_area.contact_details' => false]);
        $this->assertFalse(ContactSupport::shouldRegisterNavigation());
        $this->get('/contact')->assertRedirect('/tickets/create');
    }

    public function test_the_settings_page_saves_the_contact_details(): void
    {
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());

        Livewire::test(PublicAreaSettings::class)
            ->set('data.public_area.contact_details', true)
            ->set('data.public_area.contact_fields', ['email', 'address'])
            ->set('data.public_area.contact_map', false)
            ->set('data.public_area.contact_hours.en', ' Mon-Fri 9-5 ')
            ->set('data.public_area.contact_text.en', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(config('gaiacp.public_area.contact_details'));
        $this->assertSame(['address', 'email'], config('gaiacp.public_area.contact_fields'));
        $this->assertFalse(config('gaiacp.public_area.contact_map'));
        $this->assertSame(['en' => 'Mon-Fri 9-5'], config('gaiacp.public_area.contact_hours'));
        $this->assertSame([], config('gaiacp.public_area.contact_text'));

        // Switched off, the picks and texts are kept for next time
        Livewire::test(PublicAreaSettings::class)
            ->set('data.public_area.contact_details', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(config('gaiacp.public_area.contact_details'));
        $this->assertSame(['address', 'email'], config('gaiacp.public_area.contact_fields'));
        $this->assertSame(['en' => 'Mon-Fri 9-5'], config('gaiacp.public_area.contact_hours'));
    }
}
