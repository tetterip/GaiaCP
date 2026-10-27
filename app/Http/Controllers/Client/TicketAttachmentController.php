<?php

namespace App\Http\Controllers\Client;

use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Http\Controllers\Controller;
use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A file of the signed-in client's own ticket, never one of an admins' note (another client's is a 404). */
class TicketAttachmentController extends Controller
{
    public function __invoke(int $attachment): StreamedResponse
    {
        $file = TicketAttachment::query()->with('message')->findOrFail($attachment);

        abort_if($file->message->internal, 404);
        TicketResource::getEloquentQuery()->findOrFail($file->message->ticket_id);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->name);
    }
}
