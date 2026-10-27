<?php

namespace App\Mail;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins (Receive system emails; Settings → Support → Email the admins): a client opened or answered a ticket. */
class TicketMail extends Mailable
{
    public function __construct(public Ticket $ticket, public TicketMessage $ticketMessage, public bool $new) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".($this->new
            ? __('New ticket :number: :subject', ['number' => $this->ticket->number, 'subject' => $this->ticket->subject])
            : __('Client replied to :number: :subject', ['number' => $this->ticket->number, 'subject' => $this->ticket->subject])));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ticket', with: [
            'ticket' => $this->ticket,
            'body' => $this->ticketMessage->body,
            'files' => $this->ticketMessage->attachments()->count(),
            'url' => TicketResource::getUrl('view', ['record' => $this->ticket], panel: 'admin'),
        ]);
    }
}
