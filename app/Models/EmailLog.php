<?php

namespace App\Models;

use App\Enums\MailTemplate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Every email GaiaCP sent (App\Listeners\LogSentEmail) or failed to send (ClientMailer, SendRenewalReminders),
 * with its HTML as sent. Settings → Email log. `to_client` marks the ones sent to the client themselves, which
 * their Email history in the client area lists (not the admins' emails about them).
 */
#[Fillable(['client_id', 'to_client', 'template', 'to', 'cc', 'bcc', 'subject', 'body', 'status', 'error', 'created_at'])]
class EmailLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'template' => MailTemplate::class,
            'to_client' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function sent(Email $email, ?Client $client = null, ?MailTemplate $template = null, bool $toClient = false): self
    {
        $addresses = fn (array $list) => collect($list)->map(fn (Address $a) => $a->toString())->implode(', ') ?: null;

        return self::create([
            'client_id' => $client?->exists ? $client->id : null,
            'to_client' => $toClient && $client?->exists && filled($client->email)
                && collect($email->getTo())->contains(fn (Address $a) => strcasecmp($a->getAddress(), (string) $client->email) === 0),
            'template' => $template,
            'to' => (string) $addresses($email->getTo()),
            'cc' => $addresses($email->getCc()),
            'bcc' => $addresses($email->getBcc()),
            'subject' => $email->getSubject(),
            'body' => $email->getHtmlBody() !== null ? (string) $email->getHtmlBody() : (string) $email->getTextBody(),
            'status' => 'sent',
        ]);
    }

    /** A send that threw: what was meant to go out and why it did not. */
    public static function failed(string $to, ?string $subject, Throwable $error, ?Client $client = null, ?MailTemplate $template = null, ?string $bcc = null): self
    {
        return self::create([
            'client_id' => $client?->id,
            'template' => $template,
            'to' => $to,
            'bcc' => $bcc,
            'subject' => $subject,
            'status' => 'failed',
            'error' => $error->getMessage(),
        ]);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
