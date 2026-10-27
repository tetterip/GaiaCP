<?php

namespace App\Actions;

use App\Mail\ClientMessageMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\ClientMessageRecipient;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emails messages to clients without a queue worker: Send fixes the recipients (one row each, so nobody gets it
 * twice), and gaiacp:send-client-messages, every minute from the cron, sends the next few (Settings → Automation →
 * Messages per minute). Safe to re-run: only rows with neither sent_at nor failed_at are sent.
 */
class SendClientMessages
{
    /** Fix who gets it and start sending; the number of recipients (0 when it was already sent). */
    public function queue(ClientMessage $message): int
    {
        return DB::transaction(function () use ($message) {
            $message = ClientMessage::query()->lockForUpdate()->findOrFail($message->getKey());

            if ($message->isQueued() || ! $message->send_email) {
                return 0;
            }

            $count = 0;
            $message->emailClients()->select(['id', 'email'])->orderBy('id')->chunk(500, function ($clients) use ($message, &$count) {
                $now = now();
                ClientMessageRecipient::query()->insert($clients->map(fn (Client $c) => [
                    'client_message_id' => $message->id, 'client_id' => $c->id, 'email' => $c->email, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
                $count += $clients->count();
            });

            $message->update(['queued_at' => now(), 'finished_at' => $count === 0 ? now() : null]);
            ActivityLog::record('client_message.queued', Str::limit("Message to clients \"{$message->subject()}\" queued for {$count} client(s)", 250), properties: ['client_message_id' => $message->id]);

            return $count;
        });
    }

    /**
     * Send up to $limit pending emails, oldest message first.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendDue(int $limit): array
    {
        $summary = ['sent' => 0, 'failed' => 0];

        $pending = ClientMessageRecipient::query()->pending()
            ->whereHas('message', fn ($q) => $q->whereNotNull('queued_at'))
            ->with(['message', 'client'])
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($pending as $recipient) {
            $summary[$this->send($recipient) ? 'sent' : 'failed']++;
        }

        $this->finish();

        return $summary;
    }

    /** A test to the admin, as a client with their name, email and the chosen language would get it. */
    public function test(ClientMessage $message, User $admin, ?string $locale = null): void
    {
        [$first, $last] = array_pad(explode(' ', trim((string) $admin->name), 2), 2, '');
        $client = Client::make(['first_name' => $first, 'last_name' => $last, 'email' => $admin->email, 'locale' => $locale]);

        Mail::to($admin->email)->send(new ClientMessageMail($message, $client));
    }

    private function send(ClientMessageRecipient $recipient): bool
    {
        $client = $recipient->client;

        try {
            if (! $client || blank($client->email)) {
                throw new \RuntimeException('The client or their email address is gone');
            }

            Mail::to($client->email)->send(new ClientMessageMail($recipient->message, $client));
        } catch (Throwable $e) {
            $recipient->update(['failed_at' => now(), 'error' => Str::limit($e->getMessage(), 250)]);
            rescue(fn () => EmailLog::failed($recipient->email, $recipient->message->subject(), $e, $client), report: false);
            Log::error('Message to clients failed', ['client_message_id' => $recipient->client_message_id, 'client_id' => $recipient->client_id, 'error' => $e->getMessage()]);

            return false;
        }

        $recipient->update(['sent_at' => now(), 'email' => $client->email]);

        return true;
    }

    /** Messages with nobody left to send to are done. */
    private function finish(): void
    {
        ClientMessage::query()->whereNotNull('queued_at')->whereNull('finished_at')
            ->whereDoesntHave('recipients', fn ($q) => $q->pending())
            ->each(function (ClientMessage $message) {
                $message->update(['finished_at' => now()]);
                $sent = $message->recipients()->whereNotNull('sent_at')->count();
                $failed = $message->recipients()->whereNotNull('failed_at')->count();
                ActivityLog::record('client_message.sent', Str::limit("Message to clients \"{$message->subject()}\" sent: {$sent} sent, {$failed} failed", 250), properties: ['client_message_id' => $message->id]);
            });
    }
}
