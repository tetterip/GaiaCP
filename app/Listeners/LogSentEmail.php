<?php

namespace App\Listeners;

use App\Mail\ClientMail;
use App\Mail\ClientMessageMail;
use App\Models\Client;
use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * Keeps a copy of every email sent in the email log (Settings → Email log), with the client and template
 * when it is a client email (marked as sent to the client when it went to their address, for their Email history).
 * Discovered automatically (app/Listeners); a failure here never fails the send.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        $client = $event->data['client'] ?? null;
        $mailable = $event->data['__laravel_mailable'] ?? null;
        $isClientMail = is_a($mailable, ClientMail::class, true);

        rescue(fn () => EmailLog::sent(
            $event->message,
            $client instanceof Client ? $client : null,
            $isClientMail ? ($event->data['mailTemplate'] ?? null) : null,
            $isClientMail || is_a($mailable, ClientMessageMail::class, true),
        ), report: false);
    }
}
