<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\Tickets\TicketResource;
use App\Http\Controllers\Controller;
use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A ticket's file, for an admin who opens tickets. */
class TicketAttachmentController extends Controller
{
    public function __invoke(TicketAttachment $attachment): StreamedResponse
    {
        abort_unless(TicketResource::canAccess(), 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name);
    }
}
