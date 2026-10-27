<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\MailTemplate;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Mail\ClientMailer;
use App\Mail\TicketMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Support tickets. A client opens one from the client area (or an admin opens one for them) and both sides write in
 * it; admins also leave internal notes the client never sees. Who wrote last sets the status: the client → Open
 * (waits on us), an admin → Answered (waits on the client). A reply to a closed ticket opens it again. The client gets
 * Support Ticket Opened / Reply / Closed; the admins an email (Settings → Support) and the bell.
 */
class Tickets
{
    public function __construct(private ClientMailer $mailer) {}

    /**
     * @param  array<string, string>  $files  uploaded file path on the "local" disk => the file's own name
     * @param  bool|null  $asClient  written in the client area (true), so from the client even by an admin linked to
     *                               them; null goes by the author's role
     */
    public function open(Client $client, User $author, string $subject, string $body, ?TicketDepartment $department = null,
        ?TicketPriority $priority = null, ?Service $service = null, array $files = [], bool $emailClient = true, ?bool $asClient = null): Ticket
    {
        if ($service !== null && $service->client_id !== $client->id) {
            throw new InvalidArgumentException('That service belongs to another client.');
        }

        $fromClient = $asClient ?? self::isClient($author);
        if ($fromClient && $author->client_id !== $client->id) {
            throw new InvalidArgumentException('A client opens tickets only for themselves.');
        }

        return $this->start([
            'client_id' => $client->id,
            'service_id' => $service?->id,
            'department_id' => $department?->id,
            'subject' => $subject,
            'priority' => $priority,
        ], $author, $body, $files, $fromClient, $emailClient);
    }

    /**
     * A ticket opened by an email (an inbound email plugin): from one of the client's addresses, or with no client
     * when the sender is unknown (an admin links one later). Its emails go to the sender's address.
     *
     * @param  array<string, string>  $files  as open()
     */
    public function openFromEmail(?Client $client, string $email, ?string $name, string $subject, string $body,
        ?TicketDepartment $department = null, array $files = [], ?string $messageId = null): Ticket
    {
        return $this->start([
            'client_id' => $client?->id,
            'email' => strtolower(trim($email)),
            'name' => filled($name) ? trim($name) : null,
            'department_id' => $department?->id,
            'subject' => $subject,
        ], null, $body, $files, fromClient: true, emailClient: true, messageId: $messageId);
    }

    /**
     * A ticket from the public area's contact form (Client\Pages\ContactSupport): no client, even when the address is a
     * client's (nothing proves the visitor owns it), so an admin links one. Its emails go to that address with the
     * ticket's secret link (Ticket::publicUrl()).
     */
    public function openFromVisitor(string $email, string $name, string $subject, string $body, ?TicketDepartment $department = null): Ticket
    {
        return $this->start([
            'client_id' => null,
            'email' => strtolower(trim($email)),
            'name' => trim($name),
            'access_token' => Str::random(48),
            'department_id' => $department?->id,
            'subject' => $subject,
        ], null, $body, [], fromClient: true, emailClient: true, via: 'contact form', fromVisitor: true);
    }

    /** The sender's answer on the ticket's secret page (Client\Pages\VisitorTicket), for a ticket without a client. */
    public function replyFromVisitor(Ticket $ticket, string $body): TicketMessage
    {
        if ($ticket->client_id !== null) {
            throw new InvalidArgumentException('A ticket with a client is answered in the client area.');
        }

        return $this->append($ticket, null, $body, [], internal: false, status: null, fromClient: true);
    }

    /**
     * A message from the client or an admin, or an admins' note ($internal). An admin's reply leaves the ticket as
     * $status (Answered by default); a note leaves the status alone unless one is given.
     *
     * @param  array<string, string>  $files  uploaded file path on the "local" disk => the file's own name
     * @param  bool|null  $asClient  written in the client area (see open())
     */
    public function reply(Ticket $ticket, User $author, string $body, array $files = [], bool $internal = false, ?TicketStatus $status = null, ?bool $asClient = null): TicketMessage
    {
        $fromClient = $asClient ?? self::isClient($author);

        if ($fromClient && ($internal || $author->client_id !== $ticket->client_id)) {
            throw new InvalidArgumentException('A client writes only in their own tickets, and never notes.');
        }

        return $this->append($ticket, $author, $body, $files, $internal, $status, $fromClient);
    }

    /**
     * The client's answer by email (an inbound email plugin, which has checked the sender).
     *
     * @param  array<string, string>  $files  as reply()
     */
    public function replyFromEmail(Ticket $ticket, string $body, array $files = [], ?string $messageId = null): TicketMessage
    {
        return $this->append($ticket, null, $body, $files, internal: false, status: null, fromClient: true, messageId: $messageId);
    }

    /** Closed by an admin or the client, or by itself ($auto, gaiacp:close-tickets), which emails Support Ticket Closed. */
    public function close(Ticket $ticket, bool $auto = false): void
    {
        if ($ticket->status === TicketStatus::Closed) {
            return;
        }

        $ticket->update(['status' => TicketStatus::Closed, 'closed_at' => now()]);
        ActivityLog::record('ticket.closed', $auto ? "Ticket {$ticket->number} closed: no answer from the client" : "Ticket {$ticket->number} closed",
            $ticket->service, $ticket->client, ['ticket_id' => $ticket->id]);

        if ($auto) {
            $this->email(MailTemplate::TicketClosed, $ticket);
        }
    }

    /** Status, priority, department, admin or service changed on the ticket's page, or a client linked to one that came from an unknown sender. */
    public function update(Ticket $ticket, array $values): void
    {
        $hadClient = $ticket->client_id !== null;
        $ticket->fill($values);

        // A ticket from an unknown sender is linked to a client once; a client's ticket keeps its client
        if ($ticket->isDirty('client_id') && ($hadClient || $ticket->client_id === null)) {
            throw new InvalidArgumentException('Only a ticket without a client can be linked to one.');
        }

        if ($ticket->isDirty('service_id') && $ticket->service_id !== null && Service::query()->whereKey($ticket->service_id)->value('client_id') !== $ticket->client_id) {
            throw new InvalidArgumentException('That service belongs to another client.');
        }

        if ($ticket->isDirty('status')) {
            $ticket->closed_at = $ticket->status === TicketStatus::Closed ? now() : null;
        }

        $changed = array_keys(array_diff_key($ticket->getDirty(), ['closed_at' => true]));
        if ($changed === []) {
            return;
        }

        $ticket->save();
        ActivityLog::record('ticket.updated', "Ticket {$ticket->number} changed: ".implode(', ', $changed), $ticket->service, $ticket->client, ['ticket_id' => $ticket->id]);
    }

    /**
     * Tickets that waited on the client for Settings → Support → Close tickets after days.
     *
     * @return Collection<int, Ticket> the ones closed (or that would be, on a dry run)
     */
    public function closeStale(bool $dryRun = false): Collection
    {
        $days = config('gaiacp.support.auto_close_days');
        if (blank($days) || (int) $days < 1) {
            return collect();
        }

        $tickets = Ticket::query()->with('client')
            ->where('status', TicketStatus::Answered)
            ->where('last_reply_at', '<=', CarbonImmutable::now()->subDays((int) $days))
            ->orderBy('id')
            ->get();

        if (! $dryRun) {
            $tickets->each(fn (Ticket $ticket) => $this->close($ticket, auto: true));
        }

        return $tickets;
    }

    /** A canned reply with its placeholders filled in for this ticket. */
    public static function fill(string $text, Ticket $ticket, ?User $admin = null): string
    {
        return strtr($text, [
            '{client_first_name}' => (string) $ticket->client?->first_name,
            '{client_name}' => (string) $ticket->client?->name,
            '{ticket_number}' => $ticket->number,
            '{ticket_subject}' => $ticket->subject,
            '{admin_name}' => (string) $admin?->name,
        ]);
    }

    /** @return list<string> the extensions a ticket may carry (Settings → Support) */
    public static function allowedExtensions(): array
    {
        return array_values(array_filter(array_map(fn (string $ext) => strtolower(trim($ext, " .\t")), explode(',', (string) config('gaiacp.support.attachment_types')))));
    }

    /** @param  array<string, mixed>  $attributes */
    private function start(array $attributes, ?User $author, string $body, array $files, bool $fromClient, bool $emailClient, ?string $messageId = null, ?string $via = null, bool $fromVisitor = false): Ticket
    {
        $ticket = DB::transaction(function () use ($attributes, $author, $body, $files, $fromClient, $messageId) {
            $ticket = Ticket::create([
                ...$attributes,
                'subject' => trim($attributes['subject']),
                'priority' => $attributes['priority'] ?? TicketPriority::Medium,
                'status' => $fromClient ? TicketStatus::Open : TicketStatus::Answered,
                'last_reply_at' => now(),
            ]);

            $this->addMessage($ticket, $author, $body, $files, internal: false, fromClient: $fromClient, messageId: $messageId);

            return $ticket;
        });

        $via ??= $messageId !== null ? 'email' : null;
        ActivityLog::record($fromClient ? 'ticket.opened' : 'ticket.opened_by_admin', "Ticket {$ticket->number} opened: {$ticket->subject}".($via !== null ? " (by {$via})" : ''),
            $ticket->service, $ticket->client, ['ticket_id' => $ticket->id]);

        if ($fromClient) {
            $this->notifyAdmins($ticket, $ticket->messages()->first(), new: true);
        }

        if ($fromVisitor) {
            // Nothing proves the visitor owns the address: the email repeats none of their words (name, subject or
            // message), so the form can't send someone else a text of the visitor's from this server.
            $this->email(MailTemplate::TicketOpened, $ticket, [
                'ticket_subject' => __('Contact form'),
                'ticket_message' => __('Your message is on the ticket\'s page, at the link in this email.'),
            ], anonymous: true);
        } elseif ($fromClient || $emailClient) {
            $this->email(MailTemplate::TicketOpened, $ticket, ['ticket_message' => $body]);
        }

        return $ticket;
    }

    /** @param  array<string, string>  $files */
    private function append(Ticket $ticket, ?User $author, string $body, array $files, bool $internal, ?TicketStatus $status, bool $fromClient, ?string $messageId = null): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $author, $body, $files, $internal, $status, $fromClient, $messageId) {
            $message = $this->addMessage($ticket, $author, $body, $files, $internal, $fromClient, $messageId);

            $status = match (true) {
                $fromClient => TicketStatus::Open,
                $internal => $status ?? $ticket->status,
                default => $status ?? TicketStatus::Answered,
            };

            $ticket->update([
                'status' => $status,
                'closed_at' => $status === TicketStatus::Closed ? ($ticket->closed_at ?? now()) : null,
                'last_reply_at' => $internal ? $ticket->last_reply_at : now(),
            ]);

            return $message;
        });

        if ($internal) {
            ActivityLog::record('ticket.note', "Note added to ticket {$ticket->number}", $ticket->service, $ticket->client, ['ticket_id' => $ticket->id]);
        } elseif ($fromClient) {
            ActivityLog::record('ticket.client_replied', "Client replied to ticket {$ticket->number}".($messageId !== null ? ' by email' : ''), $ticket->service, $ticket->client, ['ticket_id' => $ticket->id]);
            $this->notifyAdmins($ticket, $message, new: false);
        } else {
            ActivityLog::record('ticket.replied', "Ticket {$ticket->number} answered", $ticket->service, $ticket->client, ['ticket_id' => $ticket->id]);
            $this->email(MailTemplate::TicketReply, $ticket, ['ticket_message' => $body, 'admin_name' => $author?->name]);
        }

        return $message;
    }

    private static function isClient(User $user): bool
    {
        return $user->role === UserRole::Client;
    }

    /** @param  array<string, string>  $files */
    private function addMessage(Ticket $ticket, ?User $author, string $body, array $files, bool $internal, bool $fromClient, ?string $messageId = null): TicketMessage
    {
        $message = $ticket->messages()->create([
            'user_id' => $author?->id,
            'from_client' => $fromClient,
            'internal' => $internal,
            'body' => trim($body),
            'email_message_id' => $messageId,
        ]);

        $disk = Storage::disk('local');
        foreach ($files as $path => $name) {
            if (! $disk->exists($path)) {
                continue;
            }

            $message->attachments()->create([
                'path' => $path,
                'name' => basename((string) $name) ?: basename($path),
                'size' => $disk->size($path),
                'mime' => $disk->mimeType($path) ?: null,
            ]);
        }

        return $message;
    }

    /** @param  array<string, string|null>  $extra */
    private function email(MailTemplate $template, Ticket $ticket, array $extra = [], bool $anonymous = false): void
    {
        // A ticket from an unknown sender is emailed to that address, as a client not saved anywhere ($anonymous: named
        // by the address, not by the name the sender typed)
        $name = $anonymous ? $ticket->email : ($ticket->name ?: $ticket->email);
        $client = $ticket->client ?? (filled($ticket->email) ? Client::make(['first_name' => $name, 'last_name' => '', 'email' => $ticket->email]) : null);
        if ($client === null) {
            return;
        }

        $this->mailer->send($template, $client, fn () => $extra + [
            'ticket_number' => $ticket->number,
            'ticket_subject' => $ticket->subject,
            'ticket_department' => $ticket->department?->name,
        ], $ticket->service, invoice: $ticket);
    }

    /**
     * To the admin the ticket is assigned to, or else every admin who opens tickets, if they receive system emails
     * and Settings → Support allows it.
     */
    private function notifyAdmins(Ticket $ticket, TicketMessage $message, bool $new): void
    {
        if (! config('gaiacp.support.admin_emails')) {
            return;
        }

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Support));

        if ($ticket->assigned_to !== null && $admins->contains('id', $ticket->assigned_to)) {
            $admins = $admins->where('id', $ticket->assigned_to);
        }

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new TicketMail($ticket, $message, $new));
            } catch (Throwable $e) {
                Log::error('Ticket email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Ticket email to {$admin->email} failed: {$e->getMessage()}", $ticket->service, $ticket->client);
            }
        }
    }
}
