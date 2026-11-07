<?php

namespace App\Mail;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A client email sent from the queue, so a slow or unreachable mail server never holds up the page that sent it
 * (ClientMailer::send() during a web request). Tried three times; the last failure is in the email log, the activity
 * log and Settings → Failed jobs, where it can be retried. Kept encrypted on the queue (jobs, failed_jobs) with the
 * APP_KEY, since a welcome or login email carries a password or a sign-in link.
 */
class SendClientMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> seconds before the second and third try */
    public array $backoff = [60, 300];

    public function __construct(public ClientMail $mail, public ?Service $service = null, public ?string $bcc = null)
    {
        // Never for a change that was rolled back
        $this->afterCommit = true;
    }

    public function handle(ClientMailer $mailer): void
    {
        $mailer->deliver($this->mail, $this->service, $this->bcc);
    }

    public function failed(Throwable $e): void
    {
        $client = $this->mail->client;
        $template = $this->mail->mailTemplate;

        rescue(fn () => EmailLog::failed($client->email, $this->mail->envelope()->subject, $e, $client, $template, $this->bcc), report: false);
        Log::error('Client email failed', ['template' => $template->value, 'client_id' => $client->id, 'error' => $e->getMessage()]);
        ActivityLog::record('email.failed', "{$template->label()} to {$client->email} failed: {$e->getMessage()}", $this->service, $client, ['template' => $template->value]);
    }
}
