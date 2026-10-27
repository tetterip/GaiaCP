<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A file of a ticket without a client, by the ticket's secret link (VisitorTicket); never one of an admins' note. */
class VisitorTicketFileController extends Controller
{
    public function __invoke(string $token, int $attachment): StreamedResponse
    {
        $ticket = Ticket::query()->whereNull('client_id')->where('access_token', $token)->firstOrFail();
        $file = TicketAttachment::query()->with('message')->findOrFail($attachment);

        abort_if($file->message->ticket_id !== $ticket->id || $file->message->internal, 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->name);
    }
}
