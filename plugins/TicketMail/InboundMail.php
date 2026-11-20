<?php

namespace Plugins\TicketMail;

use App\Actions\Tickets;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One email from the support mailbox. An answer to a ticket email joins that ticket when it comes from the ticket's
 * client or sender and carries the ticket's secret in In-Reply-To / References (or, from a sender the mail server
 * confirmed, the ticket's id there or [T-000123] in the subject); anything else opens a
 * ticket, from the client with that address or (Settings → Email to ticket) from an unknown sender. A message the
 * receiving server marks as failing DMARC or SPF (Authentication-Results) counts as from an unknown sender. Automatic replies,
 * our own emails and a message already added are skipped, so reading a message twice changes nothing.
 */
class InboundMail
{
    public const OPENED = 'opened';

    public const REPLIED = 'replied';

    public const SKIPPED = 'skipped';

    public function __construct(private Tickets $tickets) {}

    /** @return array{0: string, 1: string} what happened (OPENED, REPLIED or SKIPPED) and why, for the log */
    public function process(MessageInterface $message): array
    {
        $messageId = self::bare((string) $message->messageId());
        $from = $message->from();
        $email = strtolower(trim((string) $from?->email()));

        if ($messageId !== '' && TicketMessage::query()->where('email_message_id', $messageId)->exists()) {
            return [self::SKIPPED, 'already added'];
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->skip($message, 'no sender address');
        }

        if ($reason = $this->automatic($message, $email)) {
            return $this->skip($message, $reason);
        }

        // A From address the receiving mail server found forged (DMARC / SPF fail) speaks for no client or ticket; with
        // Settings → Email to ticket → Senders on "verified", neither does one it didn't confirm
        $sender = self::senderCheck($message, $email);
        $forged = $sender === self::FORGED
            || ($sender !== self::VERIFIED && config('gaiacp.ticket_mail.sender_check') === 'verified');
        $client = $forged ? null : self::clientFor($email);

        // An answer joins its ticket by the ticket's secret in the thread headers (Ticket::emailThreadId()); a bare
        // number (the subject's [T-000123], or the headers of an email sent before the secret) only when the mail
        // server confirmed the sender, since anyone can write a client's address and guess a number
        [$ticket, $signed] = $forged ? [null, false] : $this->ticketFor($message);
        if ($ticket !== null && ($signed || $sender === self::VERIFIED)
            && ($ticket->email === $email || ($ticket->client_id !== null && $ticket->client_id === $client?->id))) {
            $added = $this->tickets->replyFromEmail($ticket, $this->body($message), $this->files($message), $messageId ?: null);
            $this->markUnconfirmed($added, $sender);

            return [self::REPLIED, $ticket->number];
        }

        if ($client === null && config('gaiacp.ticket_mail.unknown_senders') === 'ignore') {
            return $this->skip($message, 'not a client');
        }

        $limit = (int) config('gaiacp.ticket_mail.max_per_hour');
        if ($limit > 0 && Ticket::query()->where('email', $email)->where('created_at', '>=', now()->subHour())->count() >= $limit) {
            return $this->skip($message, "more than {$limit} new tickets in an hour");
        }

        $subject = trim(preg_replace('/\s+/u', ' ', (string) $message->subject()) ?? '');
        $ticket = $this->tickets->openFromEmail($client, $email, $from?->name(), Str::limit($subject !== '' ? $subject : '(no subject)', 250, ''),
            $this->body($message), $this->department($message), $this->files($message), $messageId ?: null);
        $this->markUnconfirmed($ticket->messages()->first(), $sender);

        return [self::OPENED, $ticket->number];
    }

    /** A message from a sender the mail server didn't confirm says so to the admins (only on "forged", as "verified" never lets one count as the client's). */
    private function markUnconfirmed(?TicketMessage $message, string $sender): void
    {
        if ($message !== null && $sender !== self::VERIFIED) {
            $message->update(['sender_unconfirmed' => true]);
        }
    }

    /** The client with this address, or with a client-area login that has it. */
    public static function clientFor(string $email): ?Client
    {
        return Client::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first()
            ?? User::query()->where('role', UserRole::Client)->whereRaw('lower(email) = ?', [$email])->whereNotNull('client_id')->first()?->client;
    }

    /** @return array{0: ?Ticket, 1: bool} the ticket it answers, and whether by the ticket's secret */
    private function ticketFor(MessageInterface $message): array
    {
        $references = preg_split('/\s+/', trim((string) $message->header('References')?->getRawValue())) ?: [];
        $found = Ticket::fromEmailReferences([...$message->inReplyTo(), ...array_reverse($references)]);

        if ($found === null && preg_match('/\[#?T-(\d{1,9})\]/i', (string) $message->subject(), $match)) {
            $found = ['id' => (int) $match[1], 'signed' => false];
        }

        return $found !== null ? [Ticket::query()->find($found['id']), $found['signed']] : [null, false];
    }

    /** Why this is an automatic email nobody should answer (out of office, bounce, list, our own), or null. */
    public const VERIFIED = 'verified';

    public const FORGED = 'forged';

    public const UNCONFIRMED = 'unconfirmed';

    /**
     * What the mail servers that received it say of the From address, in their Authentication-Results headers:
     * - forged: dmarc=fail, or spf=fail / softfail without a passing DKIM signature of the From domain, in any header
     *   (a sender can add a header of their own, but writing "fail" there only hurts them);
     * - verified: the topmost header (the last server's, which is ours) says dmarc=pass, a DKIM signature of the From
     *   domain passed, or SPF passed for it. Only the topmost counts, as a sender can write a "pass" header below it;
     * - unconfirmed: neither.
     * A DKIM signature or SPF pass of another domain says nothing of the From address (anyone can sign their own).
     */
    public static function senderCheck(MessageInterface $message, string $email): string
    {
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $verdict = self::UNCONFIRMED;

        for ($i = 0; $i < 10 && ($header = $message->header('Authentication-Results', $i)) !== null; $i++) {
            $results = self::results((string) $header->getRawValue(), $domain);

            if ($results['dmarc'] === 'fail' || (in_array($results['spf'], ['fail', 'softfail'], true) && ! $results['dkim'])) {
                return self::FORGED;
            }

            if ($i === 0 && ($results['dmarc'] === 'pass' || $results['dkim'] || ($results['spf'] === 'pass' && $results['spf_aligned']))) {
                $verdict = self::VERIFIED;
            }
        }

        return $verdict;
    }

    /** @return array{dmarc: ?string, spf: ?string, spf_aligned: bool, dkim: bool} dkim: a signature of $domain passed */
    private static function results(string $value, string $domain): array
    {
        $results = ['dmarc' => null, 'spf' => null, 'spf_aligned' => false, 'dkim' => false];

        // "authserv-id; method=result prop=value ...; method=result ..."; a header's own comments in () dropped
        foreach (array_slice(explode(';', strtolower((string) preg_replace('/\([^)]*\)/', ' ', $value))), 1) as $part) {
            if (! preg_match('/^\s*(dmarc|spf|dkim)\s*=\s*([a-z]+)/', $part, $m)) {
                continue;
            }

            match ($m[1]) {
                'dmarc' => $results['dmarc'] = $m[2],
                'spf' => [$results['spf'] = $m[2], $results['spf_aligned'] = self::aligned(self::property($part, 'smtp.mailfrom'), $domain)],
                'dkim' => $results['dkim'] = $results['dkim'] || ($m[2] === 'pass'
                    && self::aligned(self::property($part, 'header.d') ?? self::property($part, 'header.i'), $domain)),
            };
        }

        return $results;
    }

    private static function property(string $part, string $name): ?string
    {
        if (! preg_match('/\b'.preg_quote($name, '/').'\s*=\s*"?([^\s";]+)/', $part, $m)) {
            return null;
        }

        return ltrim((string) (str_contains($m[1], '@') ? substr((string) strrchr($m[1], '@'), 1) : $m[1]), '.');
    }

    /** The same domain, or one inside the other (mail.example.com signs for example.com and back, as DMARC allows). */
    private static function aligned(?string $signer, string $domain): bool
    {
        return $signer !== null && $signer !== '' && $domain !== ''
            && ($signer === $domain || str_ends_with($domain, '.'.$signer) || str_ends_with($signer, '.'.$domain));
    }

    private function automatic(MessageInterface $message, string $email): ?string
    {
        $header = fn (string $name) => strtolower(trim((string) $message->header($name)?->getRawValue()));

        if (($auto = $header('Auto-Submitted')) !== '' && $auto !== 'no') {
            return 'automatic reply';
        }

        if ($header('X-Autoreply') !== '' || $header('X-Autorespond') !== '' || in_array($header('Precedence'), ['bulk', 'junk', 'list', 'auto_reply'], true)
            || $header('List-Id') !== '' || $header('X-Auto-Response-Suppress') === 'all') {
            return 'automatic or list email';
        }

        if (preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/i', $email)) {
            return 'automatic sender';
        }

        $ours = collect([config('mail.from.address'), config('gaiacp.support.reply_to'), config('gaiacp.ticket_mail.username')])
            ->merge(TicketDepartment::query()->whereNotNull('email')->pluck('email'))
            ->filter()->map(fn ($address) => strtolower(trim((string) $address)));

        return $ours->contains($email) ? 'sent by GaiaCP itself' : null;
    }

    private function department(MessageInterface $message): ?TicketDepartment
    {
        $to = collect([...$message->to(), ...$message->cc()])->map(fn (Address $address) => strtolower($address->email()));

        return TicketDepartment::query()->whereNotNull('email')->ordered()->get()
            ->first(fn (TicketDepartment $department) => $to->contains(strtolower(trim((string) $department->email))))
            ?? TicketDepartment::query()->find(config('gaiacp.ticket_mail.department_id'));
    }

    private function body(MessageInterface $message): string
    {
        $text = filled($plain = $message->text()) ? QuotedText::strip($plain) : QuotedText::fromHtml((string) $message->html());

        return Str::limit($text !== '' ? $text : '(empty message)', 60000);
    }

    /**
     * Attached files of the types and size Settings → Support allows, stored like the panel's uploads.
     *
     * @return array<string, string> path on the "local" disk => the file's own name
     */
    private function files(MessageInterface $message): array
    {
        $allowed = Tickets::allowedExtensions();
        $maxBytes = (int) config('gaiacp.support.attachments_max_kb') * 1024;
        $files = [];

        foreach (array_slice($message->attachments(), 0, 10) as $attachment) {
            /** @var Attachment $attachment */
            $name = basename(str_replace('\\', '/', (string) $attachment->filename()));
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $contents = $attachment->contents();

            // Pictures shown inside the email (a signature's logo) are not files the sender attached
            $inline = $attachment->contentId() !== null && str_starts_with(strtolower((string) rescue(fn () => $attachment->contentDisposition(), '', false)), 'inline');

            if ($inline || $name === '' || ! in_array($extension, $allowed, true) || strlen($contents) > $maxBytes) {
                continue;
            }

            $path = 'tickets/'.Str::random(40).'.'.$extension;
            Storage::disk('local')->put($path, $contents);
            $files[$path] = $name;
        }

        return $files;
    }

    /** @return array{0: string, 1: string} */
    private function skip(MessageInterface $message, string $reason): array
    {
        ActivityLog::record('ticket_mail.skipped', Str::limit("Email from {$message->from()?->email()} not added to a ticket ({$reason}): {$message->subject()}", 250));

        return [self::SKIPPED, $reason];
    }

    private static function bare(string $messageId): string
    {
        return Str::limit(trim($messageId, " \t<>"), 250, '');
    }
}
