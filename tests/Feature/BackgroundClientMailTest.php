<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Mail\ClientMail;
use App\Mail\ClientMailer;
use App\Mail\SendClientMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** Client emails sent from a page wait on the queue, so a slow mail server never holds the page up. */
class BackgroundClientMailTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['email' => 'maria@example.com']);
        config(['queue.default' => 'database']);
        Mail::fake();
        Queue::fake();
    }

    /** As in a web request: PHPUnit itself runs in the console. */
    private function fromAPage(bool $page = true): void
    {
        (new \ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, ! $page);
    }

    private function send(array $files = []): bool
    {
        return app(ClientMailer::class)->send(MailTemplate::ClientAreaInvite, $this->client, ['link' => 'https://example.com/invite'], evenIfOff: true, files: $files);
    }

    public function test_a_page_queues_the_email_and_goes_on(): void
    {
        $this->fromAPage();

        $this->assertTrue($this->send());

        Queue::assertPushed(SendClientMail::class, fn (SendClientMail $job) => $job->mail->client->is($this->client) && $job->afterCommit);
        Mail::assertNothingSent();
        $this->assertFalse(ActivityLog::query()->where('event', 'email.sent')->exists(), 'logged as sent only once it is');
    }

    public function test_the_queued_email_is_sent_and_logged_by_the_worker(): void
    {
        $this->fromAPage();
        $this->send();

        $job = Queue::pushed(SendClientMail::class)->first();
        $job = unserialize(serialize($job)); // as the database queue keeps it
        $job->handle(app(ClientMailer::class));

        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->hasTo('maria@example.com') && $mail->mailTemplate === MailTemplate::ClientAreaInvite);
        $this->assertTrue(ActivityLog::query()->where('event', 'email.sent')->where('client_id', $this->client->id)->exists());
    }

    public function test_an_email_that_failed_every_try_is_in_the_email_and_activity_logs(): void
    {
        $this->fromAPage();
        $this->send();

        Queue::pushed(SendClientMail::class)->first()->failed(new RuntimeException('Connection refused'));

        $log = EmailLog::query()->sole();
        $this->assertSame(['failed', 'maria@example.com', 'Connection refused'], [$log->status, $log->to, $log->error]);
        $this->assertTrue(ActivityLog::query()->where('event', 'email.failed')->where('client_id', $this->client->id)->exists());
    }

    public function test_the_daily_run_sends_at_once(): void
    {
        $this->fromAPage(false);

        $this->assertTrue($this->send());

        Queue::assertNothingPushed();
        Mail::assertSent(ClientMail::class);
    }

    public function test_it_sends_at_once_when_switched_off_without_a_queue_or_with_files(): void
    {
        $this->fromAPage();

        config(['gaiacp.mail.background' => false]);
        $this->send();
        config(['gaiacp.mail.background' => true, 'queue.default' => 'sync']);
        $this->send();
        config(['queue.default' => 'database']);
        $this->send([['name' => 'invoice.pdf', 'data' => "%PDF-\xff\xfe", 'mime' => 'application/pdf']]);

        Queue::assertNothingPushed();
        Mail::assertSent(ClientMail::class, 3);
    }
}
